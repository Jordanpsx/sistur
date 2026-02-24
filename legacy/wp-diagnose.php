<?php
/**
 * Diagnóstico via WordPress
 *
 * INSTRUÇÕES:
 * 1. Copie este arquivo para a raiz do WordPress: /var/www/html/wordpress/ (ou onde estiver instalado)
 * 2. Acesse: http://localhost/wordpress/wp-diagnose.php
 * 3. DELETE este arquivo após o uso
 */

// Carregar WordPress
$wp_load_paths = array(
    __DIR__ . '/wp-load.php',
    __DIR__ . '/../wp-load.php',
    '/var/www/html/wordpress/wp-load.php',
    dirname(dirname(__FILE__)) . '/wp-load.php'
);

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('❌ Não foi possível carregar o WordPress. Copie este arquivo para a raiz do WordPress.');
}

// Verificar se é admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('❌ Acesso negado. Faça login como administrador.');
}

global $wpdb;

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO - FUNCIONÁRIO ID 10 ===\n\n";

// 1. Verificar dados do funcionário
echo "1. DADOS DO FUNCIONÁRIO:\n";
echo "------------------------\n";
$employee = $wpdb->get_row($wpdb->prepare("
    SELECT e.*, ct.carga_horaria_diaria_minutos
    FROM {$wpdb->prefix}sistur_employees e
    LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
    WHERE e.id = %d
", 10), ARRAY_A);

if ($employee) {
    echo "Nome: {$employee['name']}\n";
    echo "Email: {$employee['email']}\n";
    echo "Carga horária esperada (tipo contrato): " . ($employee['carga_horaria_diaria_minutos'] ?? 'não definida') . " minutos\n";
    echo "Carga horária esperada (individual): " . ($employee['time_expected_minutes'] ?? 'não definida') . " minutos\n";

    $expected = $employee['carga_horaria_diaria_minutos']
                ?? $employee['time_expected_minutes']
                ?? 480;
    echo "Carga usada no cálculo: $expected minutos (" . floor($expected/60) . "h" . ($expected%60) . ")\n\n";
} else {
    echo "❌ Funcionário ID 10 não encontrado!\n\n";
    exit;
}

// 2. Verificar batidas do dia 18/11/2024
echo "2. BATIDAS DO DIA 18/11/2024:\n";
echo "----------------------------\n";
$punches = $wpdb->get_results($wpdb->prepare("
    SELECT id, punch_type, punch_time, shift_date, processing_status, source
    FROM {$wpdb->prefix}sistur_time_entries
    WHERE employee_id = %d AND shift_date = %s
    ORDER BY punch_time ASC
", 10, '2024-11-18'), ARRAY_A);

if (empty($punches)) {
    echo "❌ Nenhuma batida encontrada para 18/11/2024\n\n";
} else {
    $punch_count = count($punches);
    echo "Total de batidas: $punch_count\n\n";

    foreach ($punches as $i => $punch) {
        $num = $i + 1;
        echo "Batida #$num:\n";
        echo "  ID: {$punch['id']}\n";
        echo "  Tipo: {$punch['punch_type']}\n";
        echo "  Horário: {$punch['punch_time']}\n";
        echo "  Status: {$punch['processing_status']}\n";
        echo "  Origem: {$punch['source']}\n\n";
    }

    // Simular cálculo manual
    echo "CÁLCULO MANUAL (algoritmo de pares):\n";
    $total_minutes = 0;
    $has_error = false;

    for ($i = 0; $i < $punch_count; $i += 2) {
        $entrada = isset($punches[$i]) ? $punches[$i] : null;
        $saida = isset($punches[$i + 1]) ? $punches[$i + 1] : null;

        $par_num = ($i / 2) + 1;

        if ($entrada && $saida) {
            $entrada_ts = strtotime($entrada['punch_time']);
            $saida_ts = strtotime($saida['punch_time']);

            if ($entrada_ts === false) {
                echo "  Par #$par_num: ❌ ERRO - Timestamp de entrada inválido: {$entrada['punch_time']}\n";
                $has_error = true;
                continue;
            }

            if ($saida_ts === false) {
                echo "  Par #$par_num: ❌ ERRO - Timestamp de saída inválido: {$saida['punch_time']}\n";
                $has_error = true;
                continue;
            }

            if ($saida_ts > $entrada_ts) {
                $minutos = ($saida_ts - $entrada_ts) / 60;
                $total_minutes += $minutos;

                $entrada_h = date('H:i', $entrada_ts);
                $saida_h = date('H:i', $saida_ts);

                echo "  Par #$par_num: $entrada_h a $saida_h = " . number_format($minutos, 2) . " minutos ✅\n";
            } else {
                echo "  Par #$par_num: ❌ ERRO - Saída ({$saida['punch_time']}) antes/igual à entrada ({$entrada['punch_time']})\n";
                $has_error = true;
            }
        } elseif ($entrada && !$saida) {
            echo "  Par #$par_num: ⚠️ Incompleto - entrada sem saída\n";
            $has_error = true;
        }
    }

    echo "\nTotal calculado: " . number_format($total_minutes, 2) . " minutos\n";
    $hours = floor($total_minutes / 60);
    $mins = $total_minutes % 60;
    echo "Formatado: {$hours}h" . sprintf('%02d', $mins) . "\n";

    $saldo = $total_minutes - $expected;
    $saldo_sign = $saldo >= 0 ? '+' : '';
    echo "Saldo: $saldo_sign" . number_format($saldo, 2) . " minutos\n";

    if ($has_error) {
        echo "⚠️ Há erros nos dados de batida que impedirão o cálculo correto!\n";
    }
    echo "\n";
}

// 3. Verificar dados processados
echo "3. DADOS PROCESSADOS (wp_sistur_time_days):\n";
echo "------------------------------------------\n";
$day = $wpdb->get_row($wpdb->prepare("
    SELECT shift_date, minutos_trabalhados, saldo_calculado_minutos,
           saldo_final_minutos, needs_review, status, notes, supervisor_notes, updated_at
    FROM {$wpdb->prefix}sistur_time_days
    WHERE employee_id = %d AND shift_date = %s
", 10, '2024-11-18'), ARRAY_A);

if ($day) {
    echo "✅ Dia processado encontrado:\n";
    echo "  Data: {$day['shift_date']}\n";
    echo "  Minutos trabalhados: {$day['minutos_trabalhados']} min\n";
    echo "  Saldo calculado: {$day['saldo_calculado_minutos']} min\n";
    echo "  Saldo final: {$day['saldo_final_minutos']} min\n";
    echo "  Precisa revisão: " . ($day['needs_review'] ? 'SIM' : 'NÃO') . "\n";
    echo "  Status: {$day['status']}\n";
    echo "  Atualizado em: {$day['updated_at']}\n";

    if ($day['notes']) {
        echo "\n  === NOTES ===\n";
        echo "  " . str_replace("\n", "\n  ", $day['notes']) . "\n";
    }
    if ($day['supervisor_notes']) {
        echo "\n  === SUPERVISOR NOTES ===\n";
        echo "  " . $day['supervisor_notes'] . "\n";
    }
    echo "\n";

    if ($day['minutos_trabalhados'] == 0 && !empty($punches)) {
        echo "⚠️ PROBLEMA DETECTADO: Há batidas mas minutos_trabalhados = 0\n";
        echo "Isso indica que o cálculo não está funcionando corretamente.\n\n";
    }
} else {
    echo "❌ Dia NÃO processado na tabela wp_sistur_time_days\n\n";
}

// 4. Verificar semana completa
echo "4. VISÃO SEMANAL (17/11 a 21/11):\n";
echo "--------------------------------\n";
$week_days = $wpdb->get_results($wpdb->prepare("
    SELECT shift_date, minutos_trabalhados, saldo_final_minutos, needs_review
    FROM {$wpdb->prefix}sistur_time_days
    WHERE employee_id = %d
      AND shift_date >= %s
      AND shift_date <= %s
    ORDER BY shift_date ASC
", 10, '2024-11-17', '2024-11-21'), ARRAY_A);

if (empty($week_days)) {
    echo "❌ Nenhum dia processado nesta semana\n\n";
} else {
    echo sprintf("%-12s | %-12s | %-12s | %s\n", "Data", "Trabalhadas", "Saldo", "Revisão");
    echo str_repeat("-", 60) . "\n";

    foreach ($week_days as $d) {
        $trab = $d['minutos_trabalhados'] . " min";
        $saldo_sign = $d['saldo_final_minutos'] >= 0 ? '+' : '';
        $saldo = $saldo_sign . $d['saldo_final_minutos'] . " min";
        $rev = $d['needs_review'] ? 'SIM' : 'NÃO';

        echo sprintf("%-12s | %-12s | %-12s | %s\n", $d['shift_date'], $trab, $saldo, $rev);
    }
    echo "\n";
}

// 5. RECOMENDAÇÕES E AÇÃO
echo "5. AÇÃO AUTOMÁTICA - REPROCESSAMENTO:\n";
echo "-------------------------------------\n";

if (!empty($punches)) {
    echo "Executando reprocessamento automático do dia 18/11/2024...\n\n";

    // Carregar classe de processamento
    require_once(ABSPATH . 'wp-content/plugins/sistur/includes/class-sistur-punch-processing.php');
    $processor = new SISTUR_Punch_Processing();

    // Marcar como PENDENTE
    $wpdb->update(
        $wpdb->prefix . 'sistur_time_entries',
        array('processing_status' => 'PENDENTE'),
        array('employee_id' => 10, 'shift_date' => '2024-11-18'),
        array('%s'),
        array('%d', '%s')
    );

    // Processar
    $result = $processor->process_employee_day(10, '2024-11-18');

    if ($result) {
        echo "✅ Reprocessamento concluído com sucesso!\n\n";

        // Buscar dados atualizados
        $day_after = $wpdb->get_row($wpdb->prepare("
            SELECT minutos_trabalhados, saldo_final_minutos, needs_review, notes
            FROM {$wpdb->prefix}sistur_time_days
            WHERE employee_id = %d AND shift_date = %s
        ", 10, '2024-11-18'), ARRAY_A);

        if ($day_after) {
            echo "RESULTADO APÓS REPROCESSAMENTO:\n";
            echo "  Minutos trabalhados: {$day_after['minutos_trabalhados']} min\n";
            echo "  Saldo final: {$day_after['saldo_final_minutos']} min\n";
            echo "  Precisa revisão: " . ($day_after['needs_review'] ? 'SIM' : 'NÃO') . "\n";

            if ($day_after['notes']) {
                echo "\n  Notes:\n  " . str_replace("\n", "\n  ", $day_after['notes']) . "\n";
            }
            echo "\n";

            if ($day_after['minutos_trabalhados'] > 0) {
                echo "🎉 SUCESSO! As horas foram calculadas corretamente.\n";
                echo "Atualize a página do painel do funcionário para ver as mudanças.\n\n";
            } else {
                echo "⚠️ AINDA ZERADO! Verifique os logs acima para identificar o problema.\n\n";
            }
        }
    } else {
        echo "❌ Erro ao reprocessar o dia.\n\n";
    }
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
echo "\n⚠️ IMPORTANTE: DELETE este arquivo após o uso!\n";
echo "Arquivo: " . __FILE__ . "\n\n";
