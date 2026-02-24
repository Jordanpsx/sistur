<?php
/**
 * Componente: Widget de Banco de Horas Semanal
 *
 * @package SISTUR
 */

if (!defined('ABSPATH')) {
    exit;
}

$employee_id = isset($employee_id) ? $employee_id : 0;

if (!$employee_id) {
    return;
}
?>

<div class="time-bank-widget" id="time-bank-widget" data-employee-id="<?php echo esc_attr($employee_id); ?>">
    <div class="time-bank-header">
        <h3 class="time-bank-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Banco de Horas - Semana Atual
        </h3>
        <div class="time-bank-actions">
            <button type="button" id="prev-week" class="week-nav-btn" title="Semana anterior">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <button type="button" id="current-week" class="week-nav-btn week-today" title="Semana atual">Hoje</button>
            <button type="button" id="next-week" class="week-nav-btn" title="Próxima semana">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Loading State -->
    <div id="time-bank-loading" class="time-bank-loading">
        <div class="loading-spinner"></div>
        <p>Carregando banco de horas...</p>
    </div>

    <!-- Content -->
    <div id="time-bank-content" class="time-bank-content" style="display: none;">
        <!-- Resumo Semanal -->
        <div class="week-summary">
            <div class="summary-card">
                <div class="summary-label">Trabalhadas</div>
                <div class="summary-value" id="total-worked">--h--</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Esperadas</div>
                <div class="summary-value" id="total-expected">--h--</div>
            </div>
            <div class="summary-card highlight">
                <div class="summary-label">Saldo Semana</div>
                <div class="summary-value" id="week-deviation">--h--</div>
            </div>
            <div class="summary-card accent">
                <div class="summary-label">Banco Total</div>
                <div class="summary-value" id="total-bank">--h--</div>
            </div>
        </div>

        <!-- Período da Semana -->
        <div class="week-period">
            <span id="week-range">-- a --</span>
        </div>

        <!-- Tabela de Dias -->
        <div class="week-table-container">
            <table class="week-table">
                <thead>
                    <tr>
                        <th>Dia</th>
                        <th>Entrada</th>
                        <th>Saída Almoço</th>
                        <th>Volta Almoço</th>
                        <th>Saída</th>
                        <th>Trabalhadas</th>
                        <th>Desvio</th>
                        <th>Observações</th>
                    </tr>
                </thead>
                <tbody id="week-days-body">
                    <!-- Preenchido via JavaScript -->
                </tbody>
            </table>
        </div>

        <!-- Link para página completa -->
        <div class="time-bank-footer">
            <a href="<?php echo esc_url(home_url('/banco-de-horas/')); ?>" class="view-full-btn">
                Ver histórico completo
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                    <path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
        </div>
    </div>

    <!-- Error State -->
    <div id="time-bank-error" class="time-bank-error" style="display: none;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="#f56565" stroke-width="2"/>
            <path d="M12 8V12M12 16H12.01" stroke="#f56565" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <p id="error-message">Erro ao carregar dados</p>
        <button type="button" id="retry-btn" class="retry-btn">Tentar novamente</button>
    </div>
</div>

<style>
.time-bank-widget {
    margin-top: 24px;
    border-top: 1px solid #e2e8f0;
    padding-top: 24px;
}

.time-bank-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.time-bank-title {
    font-size: 18px;
    font-weight: 700;
    color: #1a202c;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.time-bank-title svg {
    stroke: #667eea;
}

.time-bank-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

.week-nav-btn {
    padding: 8px 12px;
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 14px;
    font-weight: 600;
    color: #4a5568;
    display: flex;
    align-items: center;
    justify-content: center;
}

.week-nav-btn:hover {
    background: #edf2f7;
    border-color: #cbd5e0;
}

.week-nav-btn.week-today {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

.week-nav-btn.week-today:hover {
    background: #5a67d8;
}

/* Loading */
.time-bank-loading {
    text-align: center;
    padding: 40px 20px;
    color: #718096;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    margin: 0 auto 16px;
    border: 4px solid #e2e8f0;
    border-top-color: #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Resumo Semanal */
.week-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.summary-card {
    background: #f7fafc;
    padding: 16px;
    border-radius: 12px;
    border: 2px solid #e2e8f0;
    text-align: center;
}

.summary-card.highlight {
    background: linear-gradient(135deg, #fef5e7 0%, #fad390 100%);
    border-color: #f39c12;
}

.summary-card.accent {
    background: linear-gradient(135deg, #e3f2fd 0%, #90caf9 100%);
    border-color: #2196f3;
}

.summary-label {
    font-size: 12px;
    font-weight: 600;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.summary-card.highlight .summary-label,
.summary-card.accent .summary-label {
    color: #2d3748;
}

.summary-value {
    font-size: 24px;
    font-weight: 700;
    color: #1a202c;
    font-variant-numeric: tabular-nums;
}

/* Período */
.week-period {
    text-align: center;
    margin-bottom: 16px;
    font-size: 14px;
    color: #718096;
    font-weight: 600;
}

/* Tabela */
.week-table-container {
    overflow-x: auto;
    margin-bottom: 16px;
}

.week-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.week-table th {
    background: #f7fafc;
    padding: 12px 8px;
    text-align: left;
    font-weight: 600;
    color: #4a5568;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}

.week-table td {
    padding: 12px 8px;
    border-bottom: 1px solid #e2e8f0;
    color: #2d3748;
}

.week-table tbody tr:hover {
    background: #f7fafc;
}

.week-table tbody tr.today {
    background: #ebf4ff;
}

.day-name {
    font-weight: 600;
    color: #1a202c;
    white-space: nowrap;
}

.day-date {
    font-size: 12px;
    color: #718096;
    display: block;
    margin-top: 2px;
}

.punch-time {
    font-variant-numeric: tabular-nums;
    font-weight: 500;
}

.punch-time.missing {
    color: #cbd5e0;
    font-style: italic;
}

.worked-hours {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.deviation {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    padding: 4px 8px;
    border-radius: 6px;
    display: inline-block;
}

.deviation.positive {
    color: #22543d;
    background: #c6f6d5;
}

.deviation.negative {
    color: #c53030;
    background: #fed7d7;
}

.deviation.zero {
    color: #4a5568;
    background: #e2e8f0;
}

/* Observações */
.day-notes {
    font-size: 12px;
    color: #4a5568;
    max-width: 150px;
    display: inline-block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: help;
}

.day-notes.empty {
    color: #cbd5e0;
    font-style: italic;
}

/* Footer */
.time-bank-footer {
    text-align: center;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
}

.view-full-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
    color: white !important;
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s;
    box-shadow: 0 4px 6px -1px rgba(13, 148, 136, 0.3);
}

.view-full-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

/* Error State */
.time-bank-error {
    text-align: center;
    padding: 40px 20px;
    color: #718096;
}

.time-bank-error svg {
    margin-bottom: 16px;
}

.time-bank-error p {
    margin-bottom: 16px;
    font-size: 14px;
}

.retry-btn {
    padding: 10px 20px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.retry-btn:hover {
    background: #5a67d8;
}

/* Responsividade */
@media (max-width: 768px) {
    .week-summary {
        grid-template-columns: repeat(2, 1fr);
    }

    .week-table {
        font-size: 12px;
    }

    .week-table th,
    .week-table td {
        padding: 8px 4px;
    }

    .summary-value {
        font-size: 20px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    const widget = $('#time-bank-widget');
    const employeeId = widget.data('employee-id');
    let currentWeek = null; // null = semana atual

    // Verificar se é admin (ID = 0) ou ID inválido
    if (!employeeId || employeeId === 0) {
        console.log('Employee ID inválido ou usuário admin - não carrega widget banco de horas');
        showError('Disponível apenas para funcionários');
        return;
    }

    // Inicializar
    loadWeekData();

    // Navegação de semanas
    $('#prev-week').on('click', function() {
        if (currentWeek === null) {
            currentWeek = new Date();
        }
        currentWeek.setDate(currentWeek.getDate() - 7);
        loadWeekData(formatDate(currentWeek));
    });

    $('#next-week').on('click', function() {
        if (currentWeek === null) {
            currentWeek = new Date();
        }
        currentWeek.setDate(currentWeek.getDate() + 7);
        loadWeekData(formatDate(currentWeek));
    });

    $('#current-week').on('click', function() {
        currentWeek = null;
        loadWeekData();
    });

    $('#retry-btn').on('click', function() {
        loadWeekData();
    });

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function loadWeekData(week) {
        showLoading();

        const url = '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/weekly' + (week ? '?week=' + week : '');

        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                renderWeekData(response);
                showContent();
            },
            error: function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Erro ao carregar dados do banco de horas';
                showError(message);
            }
        });
    }

    function renderWeekData(data) {
        // Resumo
        $('#total-worked').text(data.summary.total_worked_formatted);
        $('#total-expected').text(data.summary.total_expected_formatted);
        $('#week-deviation').text(data.summary.week_deviation_formatted);
        $('#total-bank').text(data.summary.accumulated_bank_formatted);

        // Período
        const startDate = new Date(data.week_start + 'T00:00:00');
        const endDate = new Date(data.week_end + 'T00:00:00');
        const startFormatted = formatDateBR(startDate);
        const endFormatted = formatDateBR(endDate);
        $('#week-range').text(`${startFormatted} a ${endFormatted}`);

        // Tabela de dias
        const tbody = $('#week-days-body');
        tbody.empty();

        // Usar data do servidor para evitar problemas de timezone
        const todayFromServer = '<?php echo current_time('Y-m-d'); ?>';

        data.days.forEach(function(day) {
            const isToday = day.date === todayFromServer;
            const tr = $('<tr></tr>').addClass(isToday ? 'today' : '');

            // Dia
            const dayCell = $('<td></td>');
            dayCell.html(`
                <span class="day-name">${day.day_abbr}</span>
                <span class="day-date">${formatDateBR(new Date(day.date + 'T00:00:00'))}</span>
            `);
            tr.append(dayCell);

            // Batidas
            tr.append(renderPunchCell(day.punches.clock_in));
            tr.append(renderPunchCell(day.punches.lunch_start));
            tr.append(renderPunchCell(day.punches.lunch_end));
            tr.append(renderPunchCell(day.punches.clock_out));

            // Horas trabalhadas
            const workedCell = $('<td></td>');
            workedCell.html(`<span class="worked-hours">${day.worked_formatted || '--'}</span>`);
            tr.append(workedCell);

            // Desvio
            const deviationCell = $('<td></td>');
            let deviationClass = 'zero';
            if (day.deviation_minutes > 0) deviationClass = 'positive';
            if (day.deviation_minutes < 0) deviationClass = 'negative';

            deviationCell.html(`<span class="deviation ${deviationClass}">${day.deviation_formatted || '--'}</span>`);
            tr.append(deviationCell);

            // Observações
            const notesCell = $('<td></td>');
            const notes = day.supervisor_notes || day.notes || '';
            if (notes) {
                const shortNotes = notes.length > 50 ? notes.substring(0, 50) + '...' : notes;
                notesCell.html(`<span class="day-notes" title="${notes.replace(/"/g, '&quot;')}">${shortNotes}</span>`);
            } else {
                notesCell.html('<span class="day-notes empty">--</span>');
            }
            tr.append(notesCell);

            tbody.append(tr);
        });
    }

    function renderPunchCell(punchTime) {
        const cell = $('<td></td>');
        if (punchTime) {
            cell.html(`<span class="punch-time">${punchTime}</span>`);
        } else {
            cell.html(`<span class="punch-time missing">--:--</span>`);
        }
        return cell;
    }

    function formatDateBR(date) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return `${day}/${month}`;
    }

    function showLoading() {
        $('#time-bank-loading').show();
        $('#time-bank-content').hide();
        $('#time-bank-error').hide();
    }

    function showContent() {
        $('#time-bank-loading').hide();
        $('#time-bank-content').show();
        $('#time-bank-error').hide();
    }

    function showError(message) {
        $('#error-message').text(message);
        $('#time-bank-loading').hide();
        $('#time-bank-content').hide();
        $('#time-bank-error').show();
    }
});
</script>
