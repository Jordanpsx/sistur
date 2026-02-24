<?php
/**
 * Script de diagnóstico do Banco de Horas
 * Verifica configuração e processamento
 */

// Carregar WordPress
require_once(__DIR__ . '/wp-load.php');

echo "=== DIAGNÓSTICO DO BANCO DE HORAS ===\n\n";

global $wpdb;

// 1. Verificar funcionários e suas cargas horárias
echo "1. FUNCIONÁRIOS E CARGA HORÁRIA:\n";
echo str_repeat("-", 80) . "\n";

$employees = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        e.time_expected_minutes,
        ct.descricao as contract_type,
        ct.carga_horaria_diaria_minutos
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE e.status = 1
    LIMIT 10
");

foreach ($employees as $emp) {
    $carga = $emp->carga_horaria_diaria_minutos ?: $emp->time_expected_minutes ?: 480;
    echo sprintf(
        "ID: %d | Nome: %s | Contrato: %s | Carga: %d min (%dh)\n",
        $emp->id,
        $emp->name,
        $emp->contract_type ?: 'N/A',
        $carga,
        $carga / 60
    );
}

// 2. Verificar registros de ponto recentes
echo "\n\n2. REGISTROS DE PONTO (ÚLTIMOS 7 DIAS):\n";
echo str_repeat("-", 80) . "\n";

$recent_punches = $wpdb->get_results("
    SELECT
        te.employee_id,
        e.name,
        te.shift_date,
        COUNT(*) as punch_count,
        te.processing_status
    FROM {$wpdb->prefix}sistur_time_entries te
    JOIN {$wpdb->prefix}sistur_employees e ON te.employee_id = e.id
    WHERE te.shift_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY te.employee_id, te.shift_date, te.processing_status
    ORDER BY te.shift_date DESC, te.employee_id
    LIMIT 20
");

foreach ($recent_punches as $punch) {
    echo sprintf(
        "Data: %s | Funcionário: %s (ID: %d) | Batidas: %d | Status: %s\n",
        date('d/m/Y', strtotime($punch->shift_date)),
        $punch->name,
        $punch->employee_id,
        $punch->punch_count,
        $punch->processing_status
    );
}

// 3. Verificar dias processados
echo "\n\n3. DIAS PROCESSADOS (ÚLTIMOS 7 DIAS):\n";
echo str_repeat("-", 80) . "\n";

$processed_days = $wpdb->get_results("
    SELECT
        td.shift_date,
        e.name,
        td.minutos_trabalhados,
        td.saldo_calculado_minutos,
        td.saldo_final_minutos,
        td.needs_review,
        td.status
    FROM {$wpdb->prefix}sistur_time_days td
    JOIN {$wpdb->prefix}sistur_employees e ON td.employee_id = e.id
    WHERE td.shift_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY td.shift_date DESC, e.name
    LIMIT 20
");

if (empty($processed_days)) {
    echo "NENHUM DIA PROCESSADO ENCONTRADO!\n";
} else {
    foreach ($processed_days as $day) {
        $trabalhado = floor($day->minutos_trabalhados / 60) . 'h' . ($day->minutos_trabalhados % 60) . 'min';
        $saldo = ($day->saldo_final_minutos >= 0 ? '+' : '') . floor($day->saldo_final_minutos / 60) . 'h' . abs($day->saldo_final_minutos % 60) . 'min';

        echo sprintf(
            "Data: %s | %s | Trabalhado: %s | Saldo: %s | Review: %s | Status: %s\n",
            date('d/m/Y', strtotime($day->shift_date)),
            $day->name,
            $trabalhado,
            $saldo,
            $day->needs_review ? 'SIM' : 'NÃO',
            $day->status
        );
    }
}

// 4. Saldo total acumulado por funcionário
echo "\n\n4. SALDO TOTAL ACUMULADO:\n";
echo str_repeat("-", 80) . "\n";

$balances = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        SUM(td.saldo_final_minutos) as total_banco_minutos,
        COUNT(*) as dias_processados
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_time_days td ON e.id = td.employee_id AND td.status = 'present' AND td.needs_review = 0
    WHERE e.status = 1
    GROUP BY e.id, e.name
    LIMIT 10
");

foreach ($balances as $balance) {
    $total = $balance->total_banco_minutos ?: 0;
    $saldo_formatado = ($total >= 0 ? '+' : '') . floor($total / 60) . 'h' . abs($total % 60) . 'min';

    echo sprintf(
        "ID: %d | %s | Saldo Total: %s | Dias: %d\n",
        $balance->id,
        $balance->name,
        $saldo_formatado,
        $balance->dias_processados
    );
}

// 5. Verificar configurações
echo "\n\n5. CONFIGURAÇÕES DO SISTEMA:\n";
echo str_repeat("-", 80) . "\n";

$settings = get_option('sistur_settings', array());
echo "Auto-processamento: " . ($settings['auto_processing_enabled'] ?? 'false') . "\n";
echo "Horário de processamento: " . ($settings['processing_time'] ?? '01:00') . "\n";
echo "Tolerância por batida: " . ($settings['tolerance_minutes_per_punch'] ?? '5') . " minutos\n";
echo "Tipo de tolerância: " . ($settings['tolerance_type'] ?? 'PER_PUNCH') . "\n";

echo "\n\n=== FIM DO DIAGNÓSTICO ===\n";
