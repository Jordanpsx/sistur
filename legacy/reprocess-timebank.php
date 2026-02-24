<?php
/**
 * Script para reprocessar dados do banco de horas
 * Este script processa todos os registros de ponto que ainda não foram processados
 *
 * Uso: Acesse via navegador /reprocess-timebank.php?days=30
 * O parâmetro 'days' indica quantos dias retroativos processar (padrão: 30)
 */

// Carregar o WordPress
require_once(__DIR__ . '/wp-load.php');

// Prevenir acesso não autorizado
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

// Número de dias para reprocessar (padrão: 30)
$days_back = isset($_GET['days']) ? intval($_GET['days']) : 30;
$employee_id_filter = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : null;

echo "<h1>Reprocessamento do Banco de Horas</h1>";
echo "<p>Processando últimos <strong>$days_back</strong> dias...</p>";
if ($employee_id_filter) {
    echo "<p>Filtrando por funcionário ID: <strong>$employee_id_filter</strong></p>";
}
echo "<hr>";

global $wpdb;

// Buscar todos os funcionários ativos
$table_employees = $wpdb->prefix . 'sistur_employees';
$table_entries = $wpdb->prefix . 'sistur_time_entries';

if ($employee_id_filter) {
    $employees = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT employee_id FROM $table_entries WHERE employee_id = %d",
        $employee_id_filter
    ));
} else {
    $employees = $wpdb->get_results(
        "SELECT DISTINCT employee_id FROM $table_entries"
    );
}

echo "<h2>Funcionários a processar: " . count($employees) . "</h2>";

// Instanciar o processador
require_once(__DIR__ . '/includes/class-sistur-punch-processing.php');
$processor = new SISTUR_Punch_Processing();

$total_processed = 0;
$total_errors = 0;

// Para cada funcionário
foreach ($employees as $emp) {
    $employee_id = $emp->employee_id;

    // Buscar nome do funcionário
    $employee_name = $wpdb->get_var($wpdb->prepare(
        "SELECT name FROM $table_employees WHERE id = %d",
        $employee_id
    ));

    echo "<h3>Processando: $employee_name (ID: $employee_id)</h3>";
    echo "<ul>";

    // Processar últimos N dias
    for ($i = 0; $i < $days_back; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));

        // Verificar se há registros para este dia
        $has_entries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_entries WHERE employee_id = %d AND shift_date = %s",
            $employee_id, $date
        ));

        if ($has_entries > 0) {
            echo "<li>$date: ";

            // Marcar como PENDENTE para forçar reprocessamento
            $wpdb->update(
                $table_entries,
                array('processing_status' => 'PENDENTE'),
                array('employee_id' => $employee_id, 'shift_date' => $date),
                array('%s'),
                array('%d', '%s')
            );

            // Processar
            $result = $processor->process_employee_day($employee_id, $date);

            if ($result) {
                echo "<span style='color:green;'>✓ Processado ($has_entries batidas)</span>";
                $total_processed++;
            } else {
                echo "<span style='color:red;'>✗ Erro</span>";
                $total_errors++;
            }

            echo "</li>";

            // Evitar timeout em grandes volumes
            if ($total_processed % 50 == 0) {
                flush();
                ob_flush();
            }
        }
    }

    echo "</ul>";
}

echo "<hr>";
echo "<h2>Resultado Final</h2>";
echo "<p><strong>Total processado:</strong> $total_processed dias</p>";
echo "<p><strong>Erros:</strong> $total_errors</p>";

if ($total_errors == 0 && $total_processed > 0) {
    echo "<p style='color:green; font-size:18px;'><strong>✓ Reprocessamento concluído com sucesso!</strong></p>";
} elseif ($total_processed == 0) {
    echo "<p style='color:orange; font-size:18px;'><strong>⚠ Nenhum registro encontrado para processar</strong></p>";
} else {
    echo "<p style='color:orange; font-size:18px;'><strong>⚠ Reprocessamento concluído com alguns erros</strong></p>";
}

echo "<p><a href='diagnose-timebank.php?employee_id=$employee_id_filter'>← Voltar ao diagnóstico</a></p>";
echo "<p><small>Gerado em: " . current_time('Y-m-d H:i:s') . "</small></p>";
