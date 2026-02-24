<?php
/**
 * Template: Página Completa de Banco de Horas
 *
 * @package SISTUR
 */

// Verificar se está logado
sistur_require_employee_login();

$employee = sistur_get_current_employee();
if (!$employee) {
    wp_redirect(home_url('/login-funcionario/'));
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banco de Horas - <?php echo esc_html($employee['nome']); ?></title>
    <?php wp_head(); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="sistur-time-bank-page">

<div class="time-bank-container">
    <!-- Header -->
    <header class="page-header">
        <div class="header-content">
            <div class="header-left">
                <a href="<?php echo esc_url(home_url('/registrar-ponto/')); ?>" class="back-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Voltar
                </a>
                <div class="header-info">
                    <h1>Banco de Horas</h1>
                    <p class="employee-name"><?php echo esc_html($employee['nome']); ?></p>
                </div>
            </div>
            <a href="<?php echo add_query_arg('sistur_logout', '1'); ?>" class="logout-btn" id="logout-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9M16 17L21 12M21 12L16 7M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Sair
            </a>
        </div>
    </header>

    <!-- Resumo Cards -->
    <section class="summary-section">
        <div class="summary-grid">
            <div class="summary-card total-bank">
                <div class="card-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="card-content">
                    <div class="card-label">Saldo Total</div>
                    <div class="card-value" id="total-bank-balance">Carregando...</div>
                </div>
            </div>

            <div class="summary-card month-worked">
                <div class="card-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <path d="M12 8V12L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div class="card-content">
                    <div class="card-label">Trabalhadas (Mês)</div>
                    <div class="card-value" id="month-worked">--h--</div>
                </div>
            </div>

            <div class="summary-card month-deviation">
                <div class="card-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M7 5L12 10L17 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="card-content">
                    <div class="card-label">Desvio (Mês)</div>
                    <div class="card-value" id="month-deviation">--h--</div>
                </div>
            </div>

            <div class="summary-card days-present">
                <div class="card-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div class="card-content">
                    <div class="card-label">Dias Trabalhados</div>
                    <div class="card-value" id="days-present">--</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Filtros -->
    <section class="filters-section">
        <div class="filters-container">
            <div class="filter-group">
                <label for="period-type">Período:</label>
                <select id="period-type" class="filter-select">
                    <option value="weekly">Semana Atual</option>
                    <option value="monthly" selected>Mês Atual</option>
                    <option value="custom">Personalizado</option>
                </select>
            </div>

            <div class="filter-group" id="month-selector" style="display: none;">
                <label for="month-input">Mês:</label>
                <input type="month" id="month-input" class="filter-input" value="<?php echo current_time('Y-m'); ?>">
            </div>

            <div class="filter-group" id="week-selector" style="display: none;">
                <label for="week-input">Semana:</label>
                <input type="date" id="week-input" class="filter-input" value="<?php echo current_time('Y-m-d'); ?>">
            </div>

            <div class="filter-group" id="custom-start" style="display: none;">
                <label for="start-date">De:</label>
                <input type="date" id="start-date" class="filter-input">
            </div>

            <div class="filter-group" id="custom-end" style="display: none;">
                <label for="end-date">Até:</label>
                <input type="date" id="end-date" class="filter-input">
            </div>

            <button type="button" id="apply-filter" class="apply-btn">Aplicar</button>
            <button type="button" id="export-pdf" class="export-btn" disabled>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15M7 10L12 15M12 15L17 10M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Exportar PDF
            </button>
        </div>
    </section>

    <!-- Gráfico -->
    <section class="chart-section">
        <div class="chart-card">
            <h2 class="chart-title">Horas Trabalhadas por Dia</h2>
            <div class="chart-container">
                <canvas id="hours-chart"></canvas>
            </div>
        </div>
    </section>

    <!-- Histórico -->
    <section class="history-section">
        <div class="history-card">
            <div class="history-header">
                <h2 class="history-title">Histórico Detalhado</h2>
                <div class="history-info">
                    <span id="period-label">--</span>
                </div>
            </div>

            <div id="history-loading" class="history-loading">
                <div class="loading-spinner"></div>
                <p>Carregando histórico...</p>
            </div>

            <div id="history-content" class="history-content" style="display: none;">
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Dia</th>
                                <th>Entrada</th>
                                <th>Saída Almoço</th>
                                <th>Volta Almoço</th>
                                <th>Saída</th>
                                <th>Trabalhadas</th>
                                <th>Esperadas</th>
                                <th>Desvio</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="history-tbody">
                            <!-- Preenchido via JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="history-empty" class="history-empty" style="display: none;">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="#cbd5e0" stroke-width="2"/>
                    <path d="M12 8V12M12 16H12.01" stroke="#cbd5e0" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <p>Nenhum registro encontrado para o período selecionado</p>
            </div>
        </div>
    </section>
</div>

<?php wp_footer(); ?>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body.sistur-time-bank-page {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f7fafc;
    color: #1a202c;
}

.time-bank-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

/* Header */
.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 24px;
    border-radius: 16px;
    margin-bottom: 24px;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.back-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    color: white;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.back-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.header-info h1 {
    font-size: 28px;
    margin-bottom: 4px;
}

.employee-name {
    font-size: 16px;
    opacity: 0.9;
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    color: white;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.logout-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

/* Summary Section */
.summary-section {
    margin-bottom: 24px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: all 0.2s;
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.summary-card.total-bank {
    border-left: 4px solid #667eea;
}

.summary-card.month-worked {
    border-left: 4px solid #48bb78;
}

.summary-card.month-deviation {
    border-left: 4px solid #f6ad55;
}

.summary-card.days-present {
    border-left: 4px solid #4299e1;
}

.card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.total-bank .card-icon {
    background: #e3e8ff;
    color: #667eea;
}

.month-worked .card-icon {
    background: #c6f6d5;
    color: #22543d;
}

.month-deviation .card-icon {
    background: #feebc8;
    color: #7c2d12;
}

.days-present .card-icon {
    background: #bee3f8;
    color: #1a365d;
}

.card-content {
    flex: 1;
}

.card-label {
    font-size: 14px;
    font-weight: 600;
    color: #718096;
    margin-bottom: 8px;
}

.card-value {
    font-size: 28px;
    font-weight: 700;
    color: #1a202c;
    font-variant-numeric: tabular-nums;
}

/* Filters Section */
.filters-section {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.filters-container {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-size: 14px;
    font-weight: 600;
    color: #4a5568;
}

.filter-select,
.filter-input {
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: #2d3748;
    background: white;
    transition: all 0.2s;
}

.filter-select:focus,
.filter-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.apply-btn,
.export-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.apply-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.apply-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.export-btn {
    background: #48bb78;
    color: white;
}

.export-btn:hover:not(:disabled) {
    background: #38a169;
}

.export-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Chart Section */
.chart-section {
    margin-bottom: 24px;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.chart-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 20px;
}

.chart-container {
    position: relative;
    height: 300px;
}

/* History Section */
.history-section {
    margin-bottom: 24px;
}

.history-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.history-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a202c;
}

.history-info {
    font-size: 14px;
    color: #718096;
    font-weight: 600;
}

.history-loading,
.history-empty {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}

.loading-spinner {
    width: 48px;
    height: 48px;
    margin: 0 auto 20px;
    border: 4px solid #e2e8f0;
    border-top-color: #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.table-responsive {
    overflow-x: auto;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.history-table th {
    background: #f7fafc;
    padding: 12px 10px;
    text-align: left;
    font-weight: 600;
    color: #4a5568;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.history-table td {
    padding: 14px 10px;
    border-bottom: 1px solid #e2e8f0;
    color: #2d3748;
}

.history-table tbody tr:hover {
    background: #f7fafc;
}

.date-cell {
    font-weight: 600;
    color: #1a202c;
}

.punch-time {
    font-variant-numeric: tabular-nums;
    font-weight: 500;
}

.punch-time.missing {
    color: #cbd5e0;
    font-style: italic;
}

.deviation-cell {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.deviation-positive {
    color: #22543d;
}

.deviation-negative {
    color: #c53030;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-block;
}

.status-present {
    background: #c6f6d5;
    color: #22543d;
}

.status-absent {
    background: #fed7d7;
    color: #c53030;
}

.status-review {
    background: #feebc8;
    color: #7c2d12;
}

/* Responsividade */
@media (max-width: 768px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .filters-container {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group {
        width: 100%;
    }

    .apply-btn,
    .export-btn {
        width: 100%;
        justify-content: center;
    }

    .header-content {
        flex-direction: column;
        align-items: flex-start;
    }

    .history-table {
        font-size: 12px;
    }

    .history-table th,
    .history-table td {
        padding: 8px 6px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Logout handler
    <?php if (isset($_GET['sistur_logout'])) : ?>
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'sistur_funcionario_logout'
            },
            success: function() {
                window.location.href = '<?php echo home_url('/login-funcionario/'); ?>';
            }
        });
        return; // Previne execução do resto do código
    <?php endif; ?>

    const employeeId = <?php echo $employee['id']; ?>;
    let currentChart = null;

    // Verificar se é admin (ID = 0) ou ID inválido
    if (!employeeId || employeeId === 0) {
        alert('Esta página está disponível apenas para funcionários. Admins devem acessar o painel administrativo.');
        window.location.href = '<?php echo admin_url(); ?>';
        return;
    }

    // Inicializar
    loadMonthlyData();
    loadBalance();

    // Controle de filtros
    $('#period-type').on('change', function() {
        const type = $(this).val();
        $('#month-selector, #week-selector, #custom-start, #custom-end').hide();

        if (type === 'monthly') {
            $('#month-selector').show();
        } else if (type === 'weekly') {
            $('#week-selector').show();
        } else if (type === 'custom') {
            $('#custom-start, #custom-end').show();
        }
    });

    // Aplicar filtro
    $('#apply-filter').on('click', function() {
        const type = $('#period-type').val();

        if (type === 'monthly') {
            const month = $('#month-input').val();
            loadMonthlyData(month);
        } else if (type === 'weekly') {
            const week = $('#week-input').val();
            loadWeeklyData(week);
        } else if (type === 'custom') {
            const start = $('#start-date').val();
            const end = $('#end-date').val();
            if (start && end) {
                loadCustomData(start, end);
            } else {
                alert('Selecione ambas as datas');
            }
        }
    });

    // Carregar saldo total
    function loadBalance() {
        $.ajax({
            url: '<?php echo rest_url('sistur/v1/balance/'); ?>' + employeeId,
            type: 'GET',
            success: function(response) {
                $('#total-bank-balance').text(response.formatted);
            },
            error: function() {
                $('#total-bank-balance').text('Erro');
            }
        });
    }

    // Carregar dados mensais
    function loadMonthlyData(month) {
        showLoading();
        const url = '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/monthly' + (month ? '?month=' + month : '');

        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                updateSummary(response.summary);
                renderHistory(response.days, 'monthly');
                renderChart(response.days);
                $('#period-label').text(response.month_name);
                showContent();
            },
            error: function() {
                showEmpty();
            }
        });
    }

    // Carregar dados semanais
    function loadWeeklyData(week) {
        showLoading();
        const url = '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/weekly' + (week ? '?week=' + week : '');

        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                updateSummary(response.summary);
                renderHistory(response.days, 'weekly');
                renderChart(response.days);
                $('#period-label').text(`${formatDateBR(response.week_start)} a ${formatDateBR(response.week_end)}`);
                showContent();
            },
            error: function() {
                showEmpty();
            }
        });
    }

    // Atualizar resumo
    function updateSummary(summary) {
        $('#month-worked').text(summary.total_worked_formatted || '--h--');
        $('#month-deviation').text(summary.total_deviation_formatted || '--h--');
        $('#days-present').text(summary.days_present || '--');
    }

    // Renderizar histórico
    function renderHistory(days, type) {
        const tbody = $('#history-tbody');
        tbody.empty();

        if (days.length === 0) {
            showEmpty();
            return;
        }

        days.forEach(function(day) {
            const tr = $('<tr></tr>');

            // Data
            tr.append(`<td class="date-cell">${formatDateBR(day.date)}</td>`);

            // Dia da semana (só para weekly)
            if (type === 'weekly') {
                tr.append(`<td>${day.day_name || '--'}</td>`);
            } else {
                tr.append(`<td>${getDayName(day.date)}</td>`);
            }

            // Batidas
            if (day.punches) {
                tr.append(renderPunchCell(day.punches.clock_in));
                tr.append(renderPunchCell(day.punches.lunch_start));
                tr.append(renderPunchCell(day.punches.lunch_end));
                tr.append(renderPunchCell(day.punches.clock_out));
            } else {
                tr.append('<td><span class="punch-time missing">--:--</span></td>'.repeat(4));
            }

            // Horas trabalhadas
            tr.append(`<td class="punch-time">${day.worked_formatted || '--'}</td>`);

            // Esperadas
            tr.append(`<td class="punch-time">${day.expected_formatted || '--'}</td>`);

            // Desvio
            const deviationClass = day.deviation_minutes > 0 ? 'deviation-positive' :
                                   day.deviation_minutes < 0 ? 'deviation-negative' : '';
            tr.append(`<td class="deviation-cell ${deviationClass}">${day.deviation_formatted || '--'}</td>`);

            // Status
            const statusClass = day.needs_review ? 'status-review' : 'status-present';
            const statusText = day.needs_review ? 'Revisão' : 'Presente';
            tr.append(`<td><span class="status-badge ${statusClass}">${statusText}</span></td>`);

            tbody.append(tr);
        });
    }

    function renderPunchCell(time) {
        if (time) {
            return `<td><span class="punch-time">${time}</span></td>`;
        }
        return `<td><span class="punch-time missing">--:--</span></td>`;
    }

    // Renderizar gráfico
    function renderChart(days) {
        if (currentChart) {
            currentChart.destroy();
        }

        const ctx = document.getElementById('hours-chart').getContext('2d');
        const labels = days.map(d => formatDateBR(d.date));
        const workedData = days.map(d => (d.worked_minutes / 60).toFixed(2));
        const expectedData = days.map(d => (d.expected_minutes / 60).toFixed(2));

        currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Horas Trabalhadas',
                    data: workedData,
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: '#667eea',
                    borderWidth: 2
                }, {
                    label: 'Horas Esperadas',
                    data: expectedData,
                    backgroundColor: 'rgba(113, 128, 150, 0.3)',
                    borderColor: '#718096',
                    borderWidth: 2,
                    borderDash: [5, 5]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + 'h';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Horas'
                        }
                    }
                }
            }
        });
    }

    function formatDateBR(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    }

    function getDayName(dateStr) {
        const days = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        const date = new Date(dateStr + 'T00:00:00');
        return days[date.getDay()];
    }

    function showLoading() {
        $('#history-loading').show();
        $('#history-content').hide();
        $('#history-empty').hide();
    }

    function showContent() {
        $('#history-loading').hide();
        $('#history-content').show();
        $('#history-empty').hide();
    }

    function showEmpty() {
        $('#history-loading').hide();
        $('#history-content').hide();
        $('#history-empty').show();
    }
});
</script>

</body>
</html>
