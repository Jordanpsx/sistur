<?php
/**
 * View de geração de Folha de Ponto Individual
 *
 * @package SISTUR
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Buscar lista de funcionários ativos
global $wpdb;
$table_employees = $wpdb->prefix . 'sistur_employees';
$employees = $wpdb->get_results(
    "SELECT id, name, cpf FROM $table_employees WHERE status = 1 ORDER BY name ASC",
    ARRAY_A
);

// Gerar lista de meses (últimos 12 meses)
$months = array();
for ($i = 0; $i < 12; $i++) {
    $date = new DateTime();
    $date->modify("-$i month");
    $months[] = array(
        'value' => $date->format('Y-m'),
        'label' => strftime('%B de %Y', $date->getTimestamp())
    );
}

// Definir locale para português
setlocale(LC_TIME, 'pt_BR.UTF-8', 'pt_BR', 'portuguese');
?>

<div class="wrap sistur-wrap">
    <h1 class="wp-heading-inline">
        <i class="dashicons dashicons-media-spreadsheet"></i>
        Folha de Ponto Individual
    </h1>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Gerar Folha de Presença</h2>
            <p class="description">
                Selecione o funcionário e o período para gerar a folha de ponto para impressão.
            </p>
        </div>

        <div class="card-body">
            <form id="attendance-sheet-form" method="get" action="<?php echo admin_url('admin.php'); ?>" target="_blank">
                <input type="hidden" name="page" value="sistur-attendance-sheet-print">

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="employee_id">Funcionário <span class="required">*</span></label>
                            </th>
                            <td>
                                <select id="employee_id" name="employee_id" class="regular-text" required>
                                    <option value="">Selecione um funcionário...</option>
                                    <?php foreach ($employees as $employee) : ?>
                                        <option value="<?php echo esc_attr($employee['id']); ?>">
                                            <?php echo esc_html($employee['name']); ?>
                                            <?php if (!empty($employee['cpf'])) : ?>
                                                - CPF: <?php echo esc_html(substr($employee['cpf'], 0, 3) . '.' . substr($employee['cpf'], 3, 3) . '.' . substr($employee['cpf'], 6, 3) . '-' . substr($employee['cpf'], 9, 2)); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Selecione o funcionário para gerar a folha de ponto</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="period_type">Tipo de Período <span class="required">*</span></label>
                            </th>
                            <td>
                                <select id="period_type" name="period_type" class="regular-text" required>
                                    <option value="monthly">Mensal</option>
                                    <option value="weekly">Semanal</option>
                                </select>
                                <p class="description">Selecione se deseja gerar folha mensal ou semanal</p>
                            </td>
                        </tr>

                        <tr id="monthly-period-row">
                            <th scope="row">
                                <label for="month">Mês/Ano <span class="required">*</span></label>
                            </th>
                            <td>
                                <select id="month" name="month" class="regular-text">
                                    <option value="">Selecione o mês...</option>
                                    <?php foreach ($months as $month) : ?>
                                        <option value="<?php echo esc_attr($month['value']); ?>">
                                            <?php echo esc_html(ucfirst($month['label'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Selecione o mês e ano de referência</p>
                            </td>
                        </tr>

                        <tr id="weekly-period-row" style="display: none;">
                            <th scope="row">
                                <label for="start_date">Período <span class="required">*</span></label>
                            </th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div>
                                        <label for="start_date" style="display: block; margin-bottom: 5px; font-weight: normal;">Data Inicial:</label>
                                        <input type="date" id="start_date" name="start_date" class="regular-text" />
                                    </div>
                                    <div>
                                        <label for="end_date" style="display: block; margin-bottom: 5px; font-weight: normal;">Data Final:</label>
                                        <input type="date" id="end_date" name="end_date" class="regular-text" />
                                    </div>
                                </div>
                                <p class="description">Selecione o período da semana (geralmente 7 dias)</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="btn-generate-sheet">
                        <i class="dashicons dashicons-printer"></i>
                        Gerar Folha de Ponto
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div class="sistur-card" style="margin-top: 20px;">
        <div class="card-header">
            <h2>Instruções</h2>
        </div>
        <div class="card-body">
            <ol style="margin-left: 20px;">
                <li>Selecione o funcionário desejado</li>
                <li>Escolha o tipo de período (Mensal ou Semanal)</li>
                <li>Se mensal, selecione o mês e ano de referência</li>
                <li>Se semanal, selecione a data inicial e final do período (normalmente 7 dias)</li>
                <li>Clique em "Gerar Folha de Ponto"</li>
                <li>Uma nova janela será aberta com a folha pronta para impressão</li>
                <li>Use a função de impressão do navegador (Ctrl+P ou Cmd+P) para imprimir ou salvar em PDF</li>
            </ol>

            <p style="margin-top: 15px;">
                <strong>Importante:</strong> Certifique-se de que as
                <a href="<?php echo admin_url('admin.php?page=sistur-company-settings'); ?>">Configurações da Empresa</a>
                estão preenchidas corretamente, pois esses dados serão usados no cabeçalho da folha.
            </p>
        </div>
    </div>
</div>

<style>
.sistur-wrap .required {
    color: #d63638;
}

.sistur-wrap .button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    margin-right: 5px;
    vertical-align: middle;
}

.sistur-card {
    background: white;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-top: 20px;
}

.sistur-card .card-header {
    padding: 20px;
    border-bottom: 1px solid #c3c4c7;
}

.sistur-card .card-header h2 {
    margin: 0 0 8px 0;
    font-size: 18px;
}

.sistur-card .card-header .description {
    margin: 0;
    color: #646970;
}

.sistur-card .card-body {
    padding: 20px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Controlar exibição dos campos baseado no tipo de período
    $('#period_type').on('change', function() {
        var periodType = $(this).val();

        if (periodType === 'monthly') {
            $('#monthly-period-row').show();
            $('#weekly-period-row').hide();
            $('#month').attr('required', true);
            $('#start_date').removeAttr('required');
            $('#end_date').removeAttr('required');
        } else {
            $('#monthly-period-row').hide();
            $('#weekly-period-row').show();
            $('#month').removeAttr('required');
            $('#start_date').attr('required', true);
            $('#end_date').attr('required', true);
        }
    });

    // Validação no envio do formulário
    $('#attendance-sheet-form').on('submit', function(e) {
        var employeeId = $('#employee_id').val();
        var periodType = $('#period_type').val();

        if (!employeeId) {
            e.preventDefault();
            alert('Por favor, selecione o funcionário.');
            return false;
        }

        if (periodType === 'monthly') {
            var month = $('#month').val();
            if (!month) {
                e.preventDefault();
                alert('Por favor, selecione o mês/ano.');
                return false;
            }
        } else {
            var startDate = $('#start_date').val();
            var endDate = $('#end_date').val();

            if (!startDate || !endDate) {
                e.preventDefault();
                alert('Por favor, selecione a data inicial e final.');
                return false;
            }

            // Validar se data final é maior que inicial
            if (new Date(endDate) < new Date(startDate)) {
                e.preventDefault();
                alert('A data final deve ser maior ou igual à data inicial.');
                return false;
            }
        }
    });
});
</script>
