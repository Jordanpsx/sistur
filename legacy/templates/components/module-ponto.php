<?php
/**
 * Componente: Módulo de Ponto Eletrônico
 *
 * Design baseado na página de Registro de Ponto (/registrar-ponto):
 * - Botão único dinâmico (entende qual batida registrar)
 * - Resumo do banco de horas (Hoje / Semana)
 * - Lista de registros do dia
 * - Link para página de banco de horas completo
 * 
 * @package SISTUR
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$session = SISTUR_Session::get_instance();
$employee_id = $session->get_employee_id();
$employee_data = $session->get_employee_data();
$employee = sistur_get_current_employee();

// Obter registros de ponto do dia
global $wpdb;
$table = $wpdb->prefix . 'sistur_time_entries';
$today = current_time('Y-m-d');

$today_entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
    $employee_id,
    $today
), ARRAY_A);

// Determinar próximo tipo de registro
$next_punch_type = 'clock_in';
$punch_count = count($today_entries);
$types = ['clock_in', 'lunch_start', 'lunch_end', 'clock_out'];

if ($punch_count > 0 && $punch_count < count($types)) {
    $next_punch_type = $types[$punch_count];
} elseif ($punch_count >= count($types)) {
    $next_punch_type = 'extra';
}

// Traduzir tipos
$type_labels = [
    'clock_in' => __('Entrada', 'sistur'),
    'lunch_start' => __('Início Almoço', 'sistur'),
    'lunch_end' => __('Fim Almoço', 'sistur'),
    'clock_out' => __('Saída', 'sistur'),
    'extra' => __('Extra', 'sistur')
];
$next_punch_label = $type_labels[$next_punch_type] ?? $next_punch_type;
?>

<div class="module-ponto-v2">
    <!-- VISÃO PRINCIPAL (Relógio + Widget Semanal) -->
    <div id="ponto-main-view">
        <!-- Relógio e Botão Principal -->
        <div class="ponto-clock-section">
            <div class="ponto-clock" id="ponto-clock">--:--:--</div>
            <div class="ponto-date" id="ponto-date">--</div>

            <button type="button" id="ponto-register-btn" class="ponto-register-btn">
                <span class="ponto-register-btn__icon">🕐</span>
                <span class="ponto-register-btn__text"
                    id="ponto-btn-text"><?php _e('Registrar Ponto', 'sistur'); ?></span>
                <span class="ponto-register-btn__type"
                    id="ponto-btn-type"><?php echo esc_html($next_punch_label); ?></span>
            </button>
        </div>

        <!-- Resumo Banco de Horas (Hoje / Semana) -->
        <div class="ponto-bank-grid">
            <div class="ponto-bank-card ponto-bank-card--today">
                <div class="ponto-bank-card__icon">⏱️</div>
                <div class="ponto-bank-card__content">
                    <span class="ponto-bank-card__label"><?php _e('Hoje', 'sistur'); ?></span>
                    <span class="ponto-bank-card__value" id="ponto-today-worked">--</span>
                    <span class="ponto-bank-card__deviation" id="ponto-today-deviation">--</span>
                </div>
            </div>
            <div class="ponto-bank-card ponto-bank-card--week">
                <div class="ponto-bank-card__icon">📅</div>
                <div class="ponto-bank-card__content">
                    <span class="ponto-bank-card__label"><?php _e('Semana', 'sistur'); ?></span>
                    <span class="ponto-bank-card__value" id="ponto-week-worked">--</span>
                    <span class="ponto-bank-card__deviation" id="ponto-week-deviation">--</span>
                </div>
            </div>
        </div>

        <!-- Registros de Hoje -->
        <div class="ponto-today-section">
            <h3><?php _e('Registros de Hoje', 'sistur'); ?></h3>

            <?php if (empty($today_entries)): ?>
                <div class="ponto-empty-entries">
                    <span class="ponto-empty-entries__icon">🕐</span>
                    <p><?php _e('Nenhum registro ainda hoje', 'sistur'); ?></p>
                </div>
            <?php else: ?>
                <div class="ponto-entries-list" id="ponto-entries-list">
                    <?php foreach ($today_entries as $entry):
                        $punch_time = new DateTime($entry['punch_time']);
                        $type_icons = [
                            'clock_in' => '➡️',
                            'lunch_start' => '⏸️',
                            'lunch_end' => '▶️',
                            'clock_out' => '⬅️'
                        ];
                        $icon = $type_icons[$entry['punch_type']] ?? '⏺️';
                        $label = $type_labels[$entry['punch_type']] ?? $entry['punch_type'];
                        ?>
                        <div class="ponto-entry ponto-entry--<?php echo esc_attr($entry['punch_type']); ?>">
                            <span class="ponto-entry__icon"><?php echo $icon; ?></span>
                            <span class="ponto-entry__type"><?php echo esc_html($label); ?></span>
                            <span class="ponto-entry__time"><?php echo $punch_time->format('H:i:s'); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Widget de Banco de Horas Semanal -->
        <?php include(SISTUR_PLUGIN_DIR . 'templates/components/time-bank-widget.php'); ?>
    </div>

    <!-- VISÃO DE HISTÓRICO (Carregada via Include) -->
    <?php include(SISTUR_PLUGIN_DIR . 'templates/components/module-ponto-history.php'); ?>
</div>

<script>
    (function ($) {
        var employeeId = <?php echo intval($employee_id); ?>;
        var restBase = '<?php echo rest_url('sistur/v1/time-bank/'); ?>';
        var typeLabels = <?php echo json_encode($type_labels); ?>;
        var punchSequence = ['clock_in', 'lunch_start', 'lunch_end', 'clock_out'];
        var currentPunchIndex = <?php echo min($punch_count, 3); ?>;

        // --- SPA Navigation Logic ---
        $(document).on('click', '.view-full-btn, .ponto-bank-link', function (e) {
            e.preventDefault();
            $('#ponto-main-view').hide();
            $('#ponto-history-view').show();
            // Load history data if available
            if (window.sisturLoadHistory) {
                window.sisturLoadHistory();
            }
        });

        $(document).on('click', '#back-to-ponto-btn', function (e) {
            e.preventDefault();
            $('#ponto-history-view').hide();
            $('#ponto-main-view').show();
        });

        // Relógio em tempo real
        function updateClock() {
            var now = new Date();
            var hours = String(now.getHours()).padStart(2, '0');
            var minutes = String(now.getMinutes()).padStart(2, '0');
            var seconds = String(now.getSeconds()).padStart(2, '0');
            $('#ponto-clock').text(hours + ':' + minutes + ':' + seconds);

            var days = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
            var months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
            var dateStr = days[now.getDay()] + ', ' + now.getDate() + ' de ' + months[now.getMonth()];
            $('#ponto-date').text(dateStr);
        }
        updateClock();
        setInterval(updateClock, 1000);

        // Registrar ponto
        $('#ponto-register-btn').on('click', function () {
            var $btn = $(this);
            var nextType = currentPunchIndex < 4 ? punchSequence[currentPunchIndex] : 'extra';

            $btn.prop('disabled', true).addClass('ponto-register-btn--loading');
            $('#ponto-btn-text').text('<?php _e('Registrando...', 'sistur'); ?>');

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'sistur_time_public_clock',
                    punch_type: nextType,
                    nonce: '<?php echo wp_create_nonce('sistur_time_public_nonce'); ?>'
                },
                success: function (response) {
                    if (response.success) {
                        $btn.addClass('ponto-register-btn--success');
                        $('#ponto-btn-text').text('<?php _e('Ponto Registrado!', 'sistur'); ?>');

                        // Atualizar para próximo tipo
                        currentPunchIndex++;
                        if (currentPunchIndex < 4) {
                            var nextLabel = typeLabels[punchSequence[currentPunchIndex]] || 'Extra';
                            $('#ponto-btn-type').text(nextLabel);
                        } else {
                            $('#ponto-btn-type').text('<?php _e('Concluído', 'sistur'); ?>');
                        }

                        // Recarregar após 1.5s para atualizar a lista
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        alert(response.data.message || '<?php _e('Erro ao registrar', 'sistur'); ?>');
                        $btn.prop('disabled', false).removeClass('ponto-register-btn--loading');
                        $('#ponto-btn-text').text('<?php _e('Registrar Ponto', 'sistur'); ?>');
                    }
                },
                error: function () {
                    alert('<?php _e('Erro de conexão', 'sistur'); ?>');
                    $btn.prop('disabled', false).removeClass('ponto-register-btn--loading');
                    $('#ponto-btn-text').text('<?php _e('Registrar Ponto', 'sistur'); ?>');
                }
            });
        });

        // Carregar resumo do banco de horas
        function loadBankSummary() {
            // Dados de hoje
            $.getJSON(restBase + employeeId + '/current')
                .done(function (response) {
                    if (response) {
                        var workedMins = response.worked_minutes || 0;
                        var hours = Math.floor(workedMins / 60);
                        var mins = workedMins % 60;
                        $('#ponto-today-worked').text(hours + 'h' + String(mins).padStart(2, '0'));

                        var deviation = response.deviation_minutes || 0;
                        var devFormatted = (deviation >= 0 ? '+' : '') + Math.floor(deviation / 60) + 'h' + String(Math.abs(deviation) % 60).padStart(2, '0');
                        var $dev = $('#ponto-today-deviation');
                        $dev.text(devFormatted);
                        $dev.removeClass('positive negative').addClass(deviation >= 0 ? 'positive' : 'negative');
                    }
                })
                .fail(function () {
                    $('#ponto-today-worked').text('--');
                });

            // Dados da semana
            $.getJSON(restBase + employeeId + '/weekly')
                .done(function (response) {
                    if (response && response.summary) {
                        $('#ponto-week-worked').text(response.summary.total_worked_formatted || '--');

                        var weekDev = response.summary.week_deviation_formatted || '--';
                        var $weekDev = $('#ponto-week-deviation');
                        $weekDev.text(weekDev);
                        $weekDev.removeClass('positive negative').addClass(
                            response.summary.week_deviation_minutes >= 0 ? 'positive' : 'negative'
                        );
                    }
                })
                .fail(function () {
                    $('#ponto-week-worked').text('--');
                });
        }

        loadBankSummary();
        // Atualizar a cada 30s
        setInterval(loadBankSummary, 30000);
    })(jQuery);
</script>

<style>
    /* ========================================
   Module Ponto v2 - Portal Colaborador
   Single Button + Bank Summary + Entries
   ======================================== */

    .module-ponto-v2 {
        max-width: 100%;
        margin: 0 auto;
    }

    /* Overrides for embedded time-bank-widget */
    .module-ponto-v2 .time-bank-widget {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-top: 24px;
        border-top: none;
    }

    .module-ponto-v2 .week-summary {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .module-ponto-v2 .summary-card {
        padding: 12px;
    }

    .module-ponto-v2 .summary-value {
        font-size: 1.25rem;
    }

    .module-ponto-v2 .week-table-container {
        overflow-x: auto;
    }

    .module-ponto-v2 .week-table {
        font-size: 0.85rem;
        min-width: 700px;
    }

    .module-ponto-v2 .week-table th,
    .module-ponto-v2 .week-table td {
        padding: 10px 6px;
    }

    @media (max-width: 900px) {
        .module-ponto-v2 .week-summary {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 600px) {
        .module-ponto-v2 .week-summary {
            grid-template-columns: 1fr;
        }
    }

    /* Clock Section */
    .ponto-clock-section {
        background: white;
        border-radius: 20px;
        padding: 40px 30px;
        text-align: center;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-bottom: 24px;
    }

    .ponto-clock {
        font-size: 4rem;
        font-weight: 800;
        color: #1e293b;
        letter-spacing: -3px;
        font-variant-numeric: tabular-nums;
        line-height: 1;
        margin-bottom: 8px;
    }

    .ponto-date {
        font-size: 1rem;
        color: #64748b;
        margin-bottom: 30px;
    }

    /* Register Button */
    .ponto-register-btn {
        width: 100%;
        padding: 28px 24px;
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        border: none;
        border-radius: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.4);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        color: white;
    }

    .ponto-register-btn:hover:not(:disabled) {
        transform: translateY(-4px);
        box-shadow: 0 15px 35px -5px rgba(99, 102, 241, 0.5);
    }

    .ponto-register-btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .ponto-register-btn--loading {
        background: #94a3b8;
    }

    .ponto-register-btn--success {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .ponto-register-btn__icon {
        font-size: 2rem;
    }

    .ponto-register-btn__text {
        font-size: 1.4rem;
        font-weight: 700;
    }

    .ponto-register-btn__type {
        font-size: 0.9rem;
        background: rgba(255, 255, 255, 0.2);
        padding: 6px 16px;
        border-radius: 20px;
        margin-top: 4px;
    }

    /* Bank Grid */
    .ponto-bank-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }

    .ponto-bank-card {
        background: white;
        border-radius: 16px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        border-bottom: 3px solid #6366f1;
    }

    .ponto-bank-card--today {
        border-bottom-color: #10b981;
    }

    .ponto-bank-card--week {
        border-bottom-color: #f59e0b;
    }

    .ponto-bank-card__icon {
        font-size: 2rem;
        width: 50px;
        height: 50px;
        background: #f8fafc;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ponto-bank-card__content {
        display: flex;
        flex-direction: column;
    }

    .ponto-bank-card__label {
        font-size: 0.8rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .ponto-bank-card__value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
    }

    .ponto-bank-card__deviation {
        font-size: 0.85rem;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 8px;
        display: inline-block;
        margin-top: 4px;
    }

    .ponto-bank-card__deviation.positive {
        color: #10b981;
        background: #dcfce7;
    }

    .ponto-bank-card__deviation.negative {
        color: #ef4444;
        background: #fee2e2;
    }

    /* Today Section */
    .ponto-today-section {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        margin-bottom: 24px;
    }

    .ponto-today-section h3 {
        margin: 0 0 16px 0;
        font-size: 1rem;
        color: #64748b;
        font-weight: 600;
    }

    .ponto-empty-entries {
        text-align: center;
        padding: 30px;
        color: #94a3b8;
    }

    .ponto-empty-entries__icon {
        font-size: 3rem;
        display: block;
        margin-bottom: 12px;
        opacity: 0.5;
    }

    .ponto-entries-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .ponto-entry {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 18px;
        background: #f8fafc;
        border-radius: 12px;
        transition: transform 0.2s;
    }

    .ponto-entry:hover {
        transform: translateX(4px);
    }

    .ponto-entry__icon {
        font-size: 1.2rem;
    }

    .ponto-entry__type {
        flex: 1;
        font-weight: 600;
        color: #334155;
    }

    .ponto-entry__time {
        font-size: 1.1rem;
        font-weight: 700;
        color: #6366f1;
        font-variant-numeric: tabular-nums;
    }

    /* Entry type colors */
    .ponto-entry--clock_in {
        border-left: 4px solid #10b981;
    }

    .ponto-entry--lunch_start {
        border-left: 4px solid #f59e0b;
    }

    .ponto-entry--lunch_end {
        border-left: 4px solid #f59e0b;
    }

    .ponto-entry--clock_out {
        border-left: 4px solid #ef4444;
    }

    /* Link Section */
    .ponto-link-section {
        text-align: center;
    }

    .ponto-bank-link {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        padding: 16px 28px;
        background: white;
        border-radius: 12px;
        text-decoration: none;
        color: #6366f1;
        font-weight: 600;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
    }

    .ponto-bank-link:hover {
        background: #6366f1;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 8px 15px -3px rgba(99, 102, 241, 0.3);
    }

    .ponto-bank-link__icon {
        font-size: 1.3rem;
    }

    .ponto-bank-link__arrow {
        font-size: 1.2rem;
    }

    /* Responsive */
    @media (max-width: 600px) {
        .ponto-clock {
            font-size: 3rem;
        }

        .ponto-bank-grid {
            grid-template-columns: 1fr;
        }
    }
</style>