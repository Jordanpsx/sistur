#!/usr/bin/env php
<?php
/**
 * Script de Teste Completo do Banco de Horas
 *
 * Este script testa e demonstra o funcionamento do banco de horas,
 * mostrando como o sistema calcula o saldo baseado na carga horária.
 *
 * Uso: php test-banco-horas-completo.php
 */

// Simular ambiente WordPress mínimo
define('WP_USE_THEMES', false);

// Tentar carregar WordPress de diferentes locais
$possible_paths = [
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../wp-load.php',
    '/var/www/html/wp-load.php',
];

$wp_loaded = false;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    echo "❌ ERRO: Não foi possível carregar o WordPress\n";
    echo "Este script deve ser executado a partir do diretório do plugin.\n\n";

    echo "=== INFORMAÇÕES SOBRE O BANCO DE HORAS ===\n\n";
    echo "O sistema de banco de horas JÁ ESTÁ IMPLEMENTADO e funciona da seguinte forma:\n\n";

    echo "1. CARGA HORÁRIA DO FUNCIONÁRIO:\n";
    echo "   - Prioridade 1: Carga do tipo de contrato (sistur_contract_types.carga_horaria_diaria_minutos)\n";
    echo "   - Prioridade 2: Carga do funcionário (sistur_employees.time_expected_minutes)\n";
    echo "   - Prioridade 3: Padrão de 480 minutos (8 horas)\n\n";

    echo "2. CÁLCULO DO TEMPO TRABALHADO:\n";
    echo "   - O sistema exige EXATAMENTE 4 batidas por dia:\n";
    echo "     1) Entrada (clock_in)\n";
    echo "     2) Início do almoço (lunch_start)\n";
    echo "     3) Fim do almoço (lunch_end)\n";
    echo "     4) Saída (clock_out)\n";
    echo "   - Tempo trabalhado = (batida2 - batida1) + (batida4 - batida3)\n\n";

    echo "3. CÁLCULO DO SALDO:\n";
    echo "   - Saldo = Tempo Trabalhado - Carga Horária Esperada\n";
    echo "   - Saldo ZERO (0): Trabalhou exatamente a carga esperada\n";
    echo "   - Saldo POSITIVO (+): Trabalhou MAIS que o esperado (hora extra)\n";
    echo "   - Saldo NEGATIVO (-): Trabalhou MENOS que o esperado (falta)\n\n";

    echo "4. EXEMPLOS:\n";
    echo "   Carga horária: 480 min (8h)\n\n";

    echo "   Exemplo 1 - Saldo ZERO:\n";
    echo "   - Entrada: 08:00, Almoço: 12:00-13:00, Saída: 17:00\n";
    echo "   - Trabalhado: (12:00-08:00) + (17:00-13:00) = 4h + 4h = 8h = 480 min\n";
    echo "   - Saldo: 480 - 480 = 0 min ✓\n\n";

    echo "   Exemplo 2 - Saldo POSITIVO:\n";
    echo "   - Entrada: 08:00, Almoço: 12:00-13:00, Saída: 18:00\n";
    echo "   - Trabalhado: (12:00-08:00) + (18:00-13:00) = 4h + 5h = 9h = 540 min\n";
    echo "   - Saldo: 540 - 480 = +60 min (+1h) ✓\n\n";

    echo "   Exemplo 3 - Saldo NEGATIVO:\n";
    echo "   - Entrada: 09:00, Almoço: 12:00-13:00, Saída: 17:00\n";
    echo "   - Trabalhado: (12:00-09:00) + (17:00-13:00) = 3h + 4h = 7h = 420 min\n";
    echo "   - Saldo: 420 - 480 = -60 min (-1h) ✓\n\n";

    echo "5. ONDE OS DADOS SÃO ARMAZENADOS:\n";
    echo "   - Batidas: tabela wp_sistur_time_entries\n";
    echo "   - Dias processados: tabela wp_sistur_time_days\n";
    echo "     * minutos_trabalhados: total de minutos trabalhados no dia\n";
    echo "     * saldo_calculado_minutos: saldo do dia (trabalhado - esperado)\n";
    echo "     * saldo_final_minutos: saldo + ajuste manual (se houver)\n\n";

    echo "6. COMO PROCESSAR:\n";
    echo "   - Automático: WP-Cron roda diariamente às 01:00 (configurável)\n";
    echo "   - Manual: GET /wp-json/sistur/v1/cron/process\n\n";

    echo "7. COMO VISUALIZAR:\n";
    echo "   - API: GET /wp-json/sistur/v1/balance/{employee_id}\n";
    echo "   - API: GET /wp-json/sistur/v1/time-bank/{employee_id}/weekly\n";
    echo "   - API: GET /wp-json/sistur/v1/time-bank/{employee_id}/monthly\n";
    echo "   - Template: /banco-de-horas/ (página do funcionário)\n\n";

    echo "=== ARQUIVOS PRINCIPAIS ===\n";
    echo "- includes/class-sistur-punch-processing.php (linha 344-462: algoritmo 1-2-3-4)\n";
    echo "- includes/class-sistur-punch-processing.php (linha 971-985: get_expected_minutes)\n";
    echo "- templates/banco-de-horas.php (interface do funcionário)\n";
    echo "- templates/components/time-bank-widget.php (widget semanal)\n\n";

    exit(1);
}

global $wpdb;

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║          TESTE COMPLETO DO SISTEMA DE BANCO DE HORAS               ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n\n";

// 1. VERIFICAR FUNCIONÁRIOS
echo "1️⃣  FUNCIONÁRIOS E CARGA HORÁRIA\n";
echo str_repeat("─", 70) . "\n";

$employees = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        e.contract_type_id,
        e.time_expected_minutes,
        ct.descricao as contract_type,
        ct.carga_horaria_diaria_minutos
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE e.status = 1
    ORDER BY e.id
    LIMIT 5
");

if (empty($employees)) {
    echo "⚠️  Nenhum funcionário ativo encontrado\n\n";
} else {
    foreach ($employees as $emp) {
        // Simular a mesma lógica do get_expected_minutes()
        $expected = 480; // default
        if (!empty($emp->carga_horaria_diaria_minutos) && intval($emp->carga_horaria_diaria_minutos) > 0) {
            $expected = intval($emp->carga_horaria_diaria_minutos);
        } elseif (!empty($emp->time_expected_minutes) && intval($emp->time_expected_minutes) > 0) {
            $expected = intval($emp->time_expected_minutes);
        }

        $hours = floor($expected / 60);
        $mins = $expected % 60;

        echo sprintf(
            "👤 %s (ID: %d)\n   Carga horária: %d min (%dh%02d) | Contrato: %s\n",
            $emp->name,
            $emp->id,
            $expected,
            $hours,
            $mins,
            $emp->contract_type ?: 'Sem contrato'
        );
    }
}

// 2. VERIFICAR REGISTROS DE PONTO RECENTES
echo "\n2️⃣  REGISTROS DE PONTO (ÚLTIMOS 3 DIAS)\n";
echo str_repeat("─", 70) . "\n";

$recent_entries = $wpdb->get_results("
    SELECT
        e.name,
        te.shift_date,
        COUNT(*) as batidas,
        GROUP_CONCAT(DATE_FORMAT(te.punch_time, '%H:%i') ORDER BY te.punch_time SEPARATOR ' → ') as horarios,
        te.processing_status
    FROM {$wpdb->prefix}sistur_time_entries te
    JOIN {$wpdb->prefix}sistur_employees e ON te.employee_id = e.id
    WHERE te.shift_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    GROUP BY te.employee_id, te.shift_date
    ORDER BY te.shift_date DESC, e.name
    LIMIT 10
");

if (empty($recent_entries)) {
    echo "⚠️  Nenhum registro de ponto nos últimos 3 dias\n\n";
} else {
    foreach ($recent_entries as $entry) {
        $status_icon = $entry->processing_status === 'PROCESSADO' ? '✅' : '⏳';
        echo sprintf(
            "%s %s | %s | %d batidas | %s\n",
            $status_icon,
            date('d/m/Y', strtotime($entry->shift_date)),
            $entry->name,
            $entry->batidas,
            $entry->horarios
        );
    }
}

// 3. VERIFICAR DIAS PROCESSADOS E SALDOS
echo "\n3️⃣  DIAS PROCESSADOS E CÁLCULO DE SALDO\n";
echo str_repeat("─", 70) . "\n";

$processed_days = $wpdb->get_results("
    SELECT
        e.name,
        td.shift_date,
        td.minutos_trabalhados,
        td.saldo_calculado_minutos,
        td.saldo_final_minutos,
        td.needs_review,
        td.status,
        COALESCE(ct.carga_horaria_diaria_minutos, e.time_expected_minutes, 480) as carga_esperada
    FROM {$wpdb->prefix}sistur_time_days td
    JOIN {$wpdb->prefix}sistur_employees e ON td.employee_id = e.id
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE td.shift_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY td.shift_date DESC, e.name
    LIMIT 10
");

if (empty($processed_days)) {
    echo "⚠️  Nenhum dia processado nos últimos 7 dias\n";
    echo "💡 Execute o processamento: GET /wp-json/sistur/v1/cron/process\n\n";
} else {
    foreach ($processed_days as $day) {
        $trabalhado_h = floor($day->minutos_trabalhados / 60);
        $trabalhado_m = $day->minutos_trabalhados % 60;

        $esperado_h = floor($day->carga_esperada / 60);
        $esperado_m = $day->carga_esperada % 60;

        $saldo = $day->saldo_final_minutos;
        $saldo_sign = $saldo >= 0 ? '+' : '';
        $saldo_h = floor(abs($saldo) / 60);
        $saldo_m = abs($saldo) % 60;
        $saldo_formatted = sprintf('%s%dh%02d', $saldo_sign, $saldo_h, $saldo_m);

        // Ícone baseado no saldo
        if ($saldo > 0) {
            $icon = '⬆️'; // Trabalhou mais
        } elseif ($saldo < 0) {
            $icon = '⬇️'; // Trabalhou menos
        } else {
            $icon = '✅'; // Exato
        }

        $review = $day->needs_review ? ' ⚠️  REVISÃO' : '';

        echo sprintf(
            "%s %s | %s\n   Trabalhado: %dh%02d | Esperado: %dh%02d | Saldo: %s%s\n",
            $icon,
            date('d/m/Y', strtotime($day->shift_date)),
            $day->name,
            $trabalhado_h,
            $trabalhado_m,
            $esperado_h,
            $esperado_m,
            $saldo_formatted,
            $review
        );
    }
}

// 4. SALDO TOTAL ACUMULADO
echo "\n4️⃣  SALDO TOTAL ACUMULADO (BANCO DE HORAS)\n";
echo str_repeat("─", 70) . "\n";

$balances = $wpdb->get_results("
    SELECT
        e.id,
        e.name,
        COALESCE(SUM(td.saldo_final_minutos), 0) as saldo_total,
        COUNT(td.id) as dias_processados
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_time_days td
        ON e.id = td.employee_id
        AND td.status = 'present'
        AND td.needs_review = 0
    WHERE e.status = 1
    GROUP BY e.id, e.name
    ORDER BY e.name
    LIMIT 10
");

foreach ($balances as $bal) {
    $saldo = intval($bal->saldo_total);
    $saldo_sign = $saldo >= 0 ? '+' : '';
    $saldo_h = floor(abs($saldo) / 60);
    $saldo_m = abs($saldo) % 60;
    $saldo_formatted = sprintf('%s%dh%02d', $saldo_sign, $saldo_h, $saldo_m);

    if ($saldo > 60) {
        $icon = '💰'; // Muitas horas positivas
    } elseif ($saldo < -60) {
        $icon = '⚠️'; // Muitas horas negativas
    } elseif ($saldo == 0) {
        $icon = '✅'; // Em dia
    } else {
        $icon = '📊'; // Normal
    }

    echo sprintf(
        "%s %s (ID: %d)\n   Banco: %s | Dias processados: %d\n",
        $icon,
        $bal->name,
        $bal->id,
        $saldo_formatted,
        $bal->dias_processados
    );
}

// 5. RESUMO FINAL
echo "\n5️⃣  RESUMO E INTERPRETAÇÃO\n";
echo str_repeat("─", 70) . "\n";
echo "✅ Saldo ZERO (0): Funcionário trabalhou exatamente a carga horária\n";
echo "⬆️  Saldo POSITIVO (+): Funcionário trabalhou MAIS (hora extra)\n";
echo "⬇️  Saldo NEGATIVO (-): Funcionário trabalhou MENOS (falta/atraso)\n\n";

echo "📋 FÓRMULA:\n";
echo "   Saldo = Tempo Trabalhado - Carga Horária Esperada\n\n";

echo "📌 ARQUIVO PRINCIPAL:\n";
echo "   includes/class-sistur-punch-processing.php\n";
echo "   - Linha 344-462: process_employee_day() (Algoritmo 1-2-3-4)\n";
echo "   - Linha 406: \$saldo_calculado = \$trabalhado - \$esperado\n";
echo "   - Linha 971-985: get_expected_minutes() (Busca carga horária)\n\n";

echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║                    ✅ TESTE CONCLUÍDO                              ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
