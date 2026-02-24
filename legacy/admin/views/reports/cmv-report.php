<?php
/**
 * Relatório de Gestão - CMV e DRE Simplificado
 * 
 * Dashboard visual com cálculo de CMV por período,
 * indicadores DRE e alertas de percentual.
 * 
 * @package SISTUR
 * @subpackage Reports
 * @since 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}
?>

<div class="wrap sistur-cmv-report">
    <h1>📊
        <?php _e('Relatório de Gestão', 'sistur'); ?>
    </h1>
    <p class="description">
        <?php _e('Análise de CMV e DRE por período selecionado', 'sistur'); ?>
    </p>

    <!-- Seletor de Período -->
    <div class="sistur-period-selector">
        <div class="period-inputs">
            <div class="period-field">
                <label for="start-date">
                    <?php _e('Data Inicial', 'sistur'); ?>
                </label>
                <input type="date" id="start-date" value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div class="period-field">
                <label for="end-date">
                    <?php _e('Data Final', 'sistur'); ?>
                </label>
                <input type="date" id="end-date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="period-field">
                <button type="button" id="btn-generate-report" class="button button-primary">
                    <?php _e('Gerar Relatório', 'sistur'); ?>
                </button>
            </div>
        </div>
        <div class="quick-periods">
            <button type="button" class="quick-period" data-period="today">
                <?php _e('Hoje', 'sistur'); ?>
            </button>
            <button type="button" class="quick-period" data-period="week">
                <?php _e('Semana', 'sistur'); ?>
            </button>
            <button type="button" class="quick-period" data-period="month">
                <?php _e('Mês', 'sistur'); ?>
            </button>
            <button type="button" class="quick-period" data-period="quarter">
                <?php _e('Trimestre', 'sistur'); ?>
            </button>
        </div>
    </div>

    <!-- Loading -->
    <div id="report-loading" class="sistur-loading" style="display: none;">
        <div class="spinner is-active"></div>
        <p>
            <?php _e('Calculando relatório...', 'sistur'); ?>
        </p>
    </div>

    <!-- Resultados -->
    <div id="report-results" style="display: none;">

        <!-- Cards DRE -->
        <div class="dre-cards">
            <div class="dre-card revenue">
                <div class="card-icon">💰</div>
                <div class="card-content">
                    <span class="card-label">
                        <?php _e('Receita Total', 'sistur'); ?>
                    </span>
                    <span class="card-value" id="dre-revenue">R$ 0,00</span>
                </div>
            </div>
            <div class="dre-card cmv">
                <div class="card-icon">📦</div>
                <div class="card-content">
                    <span class="card-label">
                        <?php _e('CMV (Custos Variáveis)', 'sistur'); ?>
                    </span>
                    <span class="card-value" id="dre-cmv">R$ 0,00</span>
                </div>
            </div>
            <div class="dre-card result">
                <div class="card-icon">📈</div>
                <div class="card-content">
                    <span class="card-label">
                        <?php _e('Resultado Bruto', 'sistur'); ?>
                    </span>
                    <span class="card-value" id="dre-result">R$ 0,00</span>
                </div>
            </div>
        </div>

        <!-- Indicador CMV% -->
        <div class="cmv-indicator">
            <h3>
                <?php _e('Indicador CMV%', 'sistur'); ?>
            </h3>
            <div class="indicator-container">
                <div class="indicator-gauge">
                    <div class="gauge-fill" id="cmv-gauge"></div>
                    <div class="gauge-value" id="cmv-percentage">0%</div>
                </div>
                <div class="indicator-legend">
                    <div class="legend-item good"><span class="dot"></span> &lt; 30% -
                        <?php _e('Boa Gestão', 'sistur'); ?>
                    </div>
                    <div class="legend-item warning"><span class="dot"></span> 30-40% -
                        <?php _e('Atenção', 'sistur'); ?>
                    </div>
                    <div class="legend-item critical"><span class="dot"></span> &gt; 40% -
                        <?php _e('Ação Necessária', 'sistur'); ?>
                    </div>
                </div>
            </div>
            <div class="indicator-status" id="cmv-status-message"></div>
        </div>

        <!-- Breakdown CMV -->
        <div class="cmv-breakdown">
            <h3>
                <?php _e('Composição do CMV', 'sistur'); ?>
            </h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>
                            <?php _e('Componente', 'sistur'); ?>
                        </th>
                        <th class="column-value">
                            <?php _e('Valor', 'sistur'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody id="cmv-breakdown-body">
                </tbody>
            </table>
        </div>

        <!-- Por Categoria -->
        <div class="category-breakdown" id="category-section" style="display: none;">
            <h3>
                <?php _e('Vendas por Categoria', 'sistur'); ?>
            </h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>
                            <?php _e('Categoria', 'sistur'); ?>
                        </th>
                        <th class="column-value">
                            <?php _e('Receita', 'sistur'); ?>
                        </th>
                        <th class="column-value">
                            <?php _e('Custo', 'sistur'); ?>
                        </th>
                        <th class="column-value">
                            <?php _e('Margem', 'sistur'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody id="category-breakdown-body">
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sem Dados -->
    <div id="report-empty" class="sistur-empty" style="display: none;">
        <div class="empty-icon">📊</div>
        <p>
            <?php _e('Selecione um período e clique em "Gerar Relatório" para visualizar os dados.', 'sistur'); ?>
        </p>
    </div>
</div>

<style>
    .sistur-cmv-report {
        max-width: 1200px;
    }

    .sistur-period-selector {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .period-inputs {
        display: flex;
        gap: 20px;
        align-items: flex-end;
        margin-bottom: 15px;
    }

    .period-field label {
        display: block;
        font-weight: 600;
        margin-bottom: 5px;
        color: #1e1e1e;
    }

    .period-field input[type="date"] {
        padding: 8px 12px;
        border: 1px solid #8c8f94;
        border-radius: 4px;
        font-size: 14px;
    }

    .quick-periods {
        display: flex;
        gap: 10px;
    }

    .quick-period {
        background: #f0f0f1;
        border: 1px solid #c3c4c7;
        padding: 6px 14px;
        border-radius: 20px;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.2s;
    }

    .quick-period:hover {
        background: #2271b1;
        color: #fff;
        border-color: #2271b1;
    }

    .sistur-loading {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 8px;
    }

    .sistur-loading .spinner {
        float: none;
        margin: 0 auto 10px;
    }

    .dre-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .dre-card {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border-radius: 12px;
        padding: 24px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border-left: 4px solid #ccc;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .dre-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    }

    .dre-card.revenue {
        border-left-color: #46b450;
    }

    .dre-card.cmv {
        border-left-color: #dc3232;
    }

    .dre-card.result {
        border-left-color: #2271b1;
    }

    .card-icon {
        font-size: 36px;
        opacity: 0.9;
    }

    .card-content {
        display: flex;
        flex-direction: column;
    }

    .card-label {
        font-size: 13px;
        color: #646970;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .card-value {
        font-size: 28px;
        font-weight: 700;
        color: #1e1e1e;
    }

    .dre-card.revenue .card-value {
        color: #46b450;
    }

    .dre-card.cmv .card-value {
        color: #dc3232;
    }

    .dre-card.result .card-value {
        color: #2271b1;
    }

    .cmv-indicator {
        background: #fff;
        padding: 24px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        margin-bottom: 30px;
    }

    .cmv-indicator h3 {
        margin: 0 0 20px 0;
        font-size: 16px;
        color: #1e1e1e;
    }

    .indicator-container {
        display: flex;
        align-items: center;
        gap: 40px;
        flex-wrap: wrap;
    }

    .indicator-gauge {
        position: relative;
        width: 200px;
        height: 16px;
        background: linear-gradient(to right, #46b450 0%, #46b450 30%, #ffb900 30%, #ffb900 40%, #dc3232 40%, #dc3232 100%);
        border-radius: 8px;
        overflow: hidden;
    }

    .gauge-fill {
        position: absolute;
        top: -4px;
        width: 4px;
        height: 24px;
        background: #1e1e1e;
        border-radius: 2px;
        transition: left 0.5s ease;
        left: 0;
    }

    .gauge-value {
        position: absolute;
        top: -30px;
        font-size: 18px;
        font-weight: 700;
        left: 0;
        transition: left 0.5s ease;
        white-space: nowrap;
    }

    .indicator-legend {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #646970;
    }

    .legend-item .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }

    .legend-item.good .dot {
        background: #46b450;
    }

    .legend-item.warning .dot {
        background: #ffb900;
    }

    .legend-item.critical .dot {
        background: #dc3232;
    }

    .indicator-status {
        margin-top: 20px;
        padding: 12px 16px;
        border-radius: 8px;
        font-weight: 500;
        display: none;
    }

    .indicator-status.good {
        background: #edfaef;
        color: #1e4620;
        border: 1px solid #46b450;
        display: block;
    }

    .indicator-status.warning {
        background: #fff8e5;
        color: #6e4600;
        border: 1px solid #ffb900;
        display: block;
    }

    .indicator-status.critical {
        background: #fcedec;
        color: #8b0000;
        border: 1px solid #dc3232;
        display: block;
    }

    .cmv-breakdown,
    .category-breakdown {
        background: #fff;
        padding: 24px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        margin-bottom: 30px;
    }

    .cmv-breakdown h3,
    .category-breakdown h3 {
        margin: 0 0 15px 0;
        font-size: 16px;
        color: #1e1e1e;
    }

    .column-value {
        width: 120px;
        text-align: right;
    }

    .sistur-empty {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 8px;
    }

    .empty-icon {
        font-size: 48px;
        margin-bottom: 15px;
        opacity: 0.6;
    }

    .sistur-empty p {
        color: #646970;
        font-size: 14px;
    }

    @media (max-width: 782px) {
        .period-inputs {
            flex-direction: column;
            align-items: stretch;
        }

        .quick-periods {
            flex-wrap: wrap;
        }

        .dre-cards {
            grid-template-columns: 1fr;
        }

        .indicator-container {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<script>
    jQuery(document).ready(function ($) {
        const apiBase = '<?php echo esc_url(rest_url('sistur/v1')); ?>';
    const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';

    function formatCurrency(value) {
        return 'R$ ' + parseFloat(value).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function setQuickPeriod(period) {
        const today = new Date();
        let start, end;

        switch (period) {
            case 'today':
                start = end = today.toISOString().split('T')[0];
                break;
            case 'week':
                const weekStart = new Date(today);
                weekStart.setDate(today.getDate() - today.getDay());
                start = weekStart.toISOString().split('T')[0];
                end = today.toISOString().split('T')[0];
                break;
            case 'month':
                start = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                end = today.toISOString().split('T')[0];
                break;
            case 'quarter':
                const quarterMonth = Math.floor(today.getMonth() / 3) * 3;
                start = new Date(today.getFullYear(), quarterMonth, 1).toISOString().split('T')[0];
                end = today.toISOString().split('T')[0];
                break;
        }

        $('#start-date').val(start);
        $('#end-date').val(end);
    }

    function generateReport() {
        const startDate = $('#start-date').val();
        const endDate = $('#end-date').val();

        if (!startDate || !endDate) {
            alert('<?php _e('Selecione as datas de início e fim', 'sistur'); ?>');
            return;
        }

        $('#report-empty').hide();
        $('#report-results').hide();
        $('#report-loading').show();

        $.ajax({
            url: apiBase + '/stock/reports/dre',
            method: 'GET',
            headers: { 'X-WP-Nonce': nonce },
            data: { start_date: startDate, end_date: endDate },
            success: function (response) {
                $('#report-loading').hide();

                if (response.success) {
                    displayReport(response);
                    $('#report-results').show();
                } else {
                    alert(response.message || 'Erro ao gerar relatório');
                    $('#report-empty').show();
                }
            },
            error: function (xhr) {
                $('#report-loading').hide();
                $('#report-empty').show();
                console.error('Erro:', xhr.responseJSON);
                alert('Erro ao conectar com a API');
            }
        });
    }

    function displayReport(data) {
        const dre = data.dre;
        const cmvData = data.cmv_breakdown;
        const categories = data.by_category;

        // Cards DRE
        $('#dre-revenue').text(formatCurrency(dre.revenue));
        $('#dre-cmv').text(formatCurrency(dre.cmv));
        $('#dre-result').text(formatCurrency(dre.gross_result));

        // Indicador CMV%
        const cmvPct = dre.cmv_percentage;
        const gaugePos = Math.min(cmvPct, 100) + '%';

        $('#cmv-percentage').text(cmvPct.toFixed(1) + '%').css('left', gaugePos);
        $('#cmv-gauge').css('left', gaugePos);

        const statusEl = $('#cmv-status-message');
        statusEl.removeClass('good warning critical').hide();

        if (dre.cmv_status === 'good') {
            statusEl.addClass('good').text('✅ Excelente! Seu CMV está abaixo de 30%, indicando boa gestão de custos.').show();
        } else if (dre.cmv_status === 'warning') {
            statusEl.addClass('warning').text('⚠️ Atenção! Seu CMV está entre 30% e 40%. Considere revisar custos.').show();
        } else if (dre.cmv_status === 'critical') {
            statusEl.addClass('critical').text('🚨 Ação Necessária! CMV acima de 40% pode comprometer a rentabilidade.').show();
        }

        // Breakdown CMV
        let breakdownHtml = `
            <tr>
                <td><?php _e('(+) Estoque Inicial', 'sistur'); ?></td>
                <td class="column-value">${formatCurrency(cmvData.opening_stock)}</td>
            </tr>
            <tr>
                <td><?php _e('(+) Compras no Período', 'sistur'); ?></td>
                <td class="column-value">${formatCurrency(cmvData.purchases)}</td>
            </tr>
            <tr>
                <td><?php _e('(-) Estoque Final', 'sistur'); ?></td>
                <td class="column-value">${formatCurrency(cmvData.closing_stock)}</td>
            </tr>
            <tr style="font-weight: 700; background: #f0f6fc;">
                <td><?php _e('(=) CMV', 'sistur'); ?></td>
                <td class="column-value">${formatCurrency(cmvData.cmv)}</td>
            </tr>
        `;

        if (cmvData.losses_included > 0) {
            breakdownHtml += `
                <tr style="color: #dc3232;">
                    <td><?php _e('↳ Perdas no Período', 'sistur'); ?></td>
                    <td class="column-value">${formatCurrency(cmvData.losses_included)}</td>
                </tr>
            `;
        }

        $('#cmv-breakdown-body').html(breakdownHtml);

        // Categorias
        if (categories && categories.length > 0) {
            let categoryHtml = '';
            categories.forEach(function (cat) {
                const margin = cat.revenue > 0 ? ((cat.revenue - cat.cost) / cat.revenue * 100).toFixed(1) : 0;
                categoryHtml += `
                    <tr>
                        <td>${cat.category}</td>
                        <td class="column-value">${formatCurrency(cat.revenue)}</td>
                        <td class="column-value">${formatCurrency(cat.cost)}</td>
                        <td class="column-value">${margin}%</td>
                    </tr>
                `;
            });
            $('#category-breakdown-body').html(categoryHtml);
            $('#category-section').show();
        } else {
            $('#category-section').hide();
        }
    }

    // Event Listeners
    $('#btn-generate-report').on('click', generateReport);

    $('.quick-period').on('click', function () {
        setQuickPeriod($(this).data('period'));
        generateReport();
    });

    // Auto-generate on load with current month
    $('#report-empty').show();
});
</script>