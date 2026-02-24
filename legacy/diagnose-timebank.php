<?php
/**
 * Script de diagnóstico para o banco de horas
 * Acesse via: /diagnose-timebank.php
 */

// Carregar o WordPress
require_once(__DIR__ . '/wp-load.php');

// Prevenir acesso não autorizado
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

global $wpdb;

// ID do funcionário para testar (altere conforme necessário)
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 10;

echo "<h1>Diagnóstico do Banco de Horas</h1>";
echo "<p>Funcionário ID: $employee_id</p>";
echo "<hr>";

// 1. Verificar dados do funcionário
echo "<h2>1. Dados do Funcionário</h2>";
$table_employees = $wpdb->prefix . 'sistur_employees';
$employee = $wpdb->get_row($wpdb->prepare(
    "SELECT e.*, ct.carga_horaria_diaria_minutos, ct.name as contract_name
     FROM $table_employees e
     LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
     WHERE e.id = %d",
    $employee_id
), ARRAY_A);

if ($employee) {
    echo "<pre>";
    echo "Nome: " . $employee['name'] . "\n";
    echo "Email: " . $employee['email'] . "\n";
    echo "Tipo de Contrato: " . ($employee['contract_name'] ?: 'N/A') . "\n";
    echo "Carga Horária Diária: " . ($employee['carga_horaria_diaria_minutos'] ?: 'N/A') . " minutos\n";
    echo "Status: " . ($employee['status'] ? 'Ativo' : 'Inativo') . "\n";
    echo "</pre>";
} else {
    echo "<p style='color:red;'>❌ Funcionário não encontrado!</p>";
    exit;
}

// 2. Verificar registros de ponto (últimos 10 dias)
echo "<h2>2. Registros de Ponto (Últimos 10 Dias)</h2>";
$table_entries = $wpdb->prefix . 'sistur_time_entries';
$entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_entries
     WHERE employee_id = %d
     AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
     ORDER BY shift_date DESC, punch_time ASC",
    $employee_id
), ARRAY_A);

if ($entries) {
    echo "<p>✅ Encontrados " . count($entries) . " registros de ponto</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Data</th><th>Tipo</th><th>Hora</th></tr>";
    foreach ($entries as $entry) {
        echo "<tr>";
        echo "<td>" . $entry['shift_date'] . "</td>";
        echo "<td>" . $entry['punch_type'] . "</td>";
        echo "<td>" . date('H:i', strtotime($entry['punch_time'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange;'>⚠️ Nenhum registro de ponto encontrado nos últimos 10 dias</p>";
}

// 3. Verificar dados processados (time_days)
echo "<h2>3. Dados Processados (sistur_time_days) - Últimos 10 Dias</h2>";
$table_days = $wpdb->prefix . 'sistur_time_days';
$days = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_days
     WHERE employee_id = %d
     AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
     ORDER BY shift_date DESC",
    $employee_id
), ARRAY_A);

if ($days) {
    echo "<p>✅ Encontrados " . count($days) . " dias processados</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Data</th><th>Trabalhadas</th><th>Esperadas</th><th>Saldo</th><th>Status</th><th>Observações</th></tr>";
    foreach ($days as $day) {
        $worked_hours = floor($day['minutos_trabalhados'] / 60);
        $worked_mins = $day['minutos_trabalhados'] % 60;
        $expected_hours = floor($day['minutos_esperados'] / 60);
        $expected_mins = $day['minutos_esperados'] % 60;
        $saldo_hours = floor(abs($day['saldo_final_minutos']) / 60);
        $saldo_mins = abs($day['saldo_final_minutos']) % 60;
        $saldo_sign = $day['saldo_final_minutos'] >= 0 ? '+' : '-';

        echo "<tr>";
        echo "<td>" . $day['shift_date'] . "</td>";
        echo "<td>{$worked_hours}h{$worked_mins}</td>";
        echo "<td>{$expected_hours}h{$expected_mins}</td>";
        echo "<td>{$saldo_sign}{$saldo_hours}h{$saldo_mins}</td>";
        echo "<td>" . $day['status'] . "</td>";
        echo "<td>" . ($day['supervisor_notes'] ?: $day['notes'] ?: '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>❌ Nenhum dado processado encontrado! Este é provavelmente o problema.</p>";
    echo "<p>Os registros de ponto precisam ser processados para aparecer no banco de horas.</p>";
}

// 4. Verificar saldo total
echo "<h2>4. Saldo Total Acumulado</h2>";
$total_bank = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(saldo_final_minutos) FROM $table_days
     WHERE employee_id = %d AND status = 'present' AND needs_review = 0",
    $employee_id
));

if ($total_bank !== null) {
    $bank_hours = floor(abs($total_bank) / 60);
    $bank_mins = abs($total_bank) % 60;
    $bank_sign = $total_bank >= 0 ? '+' : '-';
    echo "<p>Saldo Total: <strong>{$bank_sign}{$bank_hours}h{$bank_mins}</strong></p>";
} else {
    echo "<p style='color:orange;'>⚠️ Nenhum saldo calculado</p>";
}

// 5. Testar API
echo "<h2>5. Teste da API</h2>";
echo "<p>Testando endpoint: <code>/wp-json/sistur/v1/time-bank/{$employee_id}/weekly</code></p>";

$api_url = rest_url("sistur/v1/time-bank/{$employee_id}/weekly");
echo "<p><a href='{$api_url}' target='_blank'>Abrir API no navegador</a></p>";

// 6. Verificar se há registros não processados
echo "<h2>6. Diagnóstico de Processamento</h2>";
$dates_with_entries = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT shift_date FROM $table_entries WHERE employee_id = %d ORDER BY shift_date DESC LIMIT 10",
    $employee_id
));

$dates_with_days = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT shift_date FROM $table_days WHERE employee_id = %d ORDER BY shift_date DESC LIMIT 10",
    $employee_id
));

$unprocessed_dates = array_diff($dates_with_entries, $dates_with_days);

if (count($unprocessed_dates) > 0) {
    echo "<p style='color:orange;'>⚠️ Existem " . count($unprocessed_dates) . " datas com registros de ponto mas SEM dados processados:</p>";
    echo "<ul>";
    foreach ($unprocessed_dates as $date) {
        echo "<li>$date</li>";
    }
    echo "</ul>";
    echo "<p><strong>Solução:</strong> Executar o processamento de pontos para estas datas.</p>";
} else {
    echo "<p style='color:green;'>✅ Todos os registros de ponto foram processados</p>";
}

echo "<hr>";
echo "<p><small>Gerado em: " . current_time('Y-m-d H:i:s') . "</small></p>";
