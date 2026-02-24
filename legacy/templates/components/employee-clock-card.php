<?php
/**
 * Componente reutilizável do cartão de registro de ponto do funcionário
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($clock_employee_display, $clock_target_employee_id, $clock_today_entries, $clock_next_punch_type_label)) {
    return;
}

$clock_can_punch = isset($clock_can_punch) ? (bool) $clock_can_punch : true;
$clock_requires_validation = isset($clock_requires_validation) ? (bool) $clock_requires_validation : false;
$clock_has_locations = isset($clock_has_locations) ? (bool) $clock_has_locations : false;
$clock_expected_minutes = isset($clock_expected_minutes) ? (int) $clock_expected_minutes : 480;
$clock_show_logout = isset($clock_show_logout) ? (bool) $clock_show_logout : true;
$clock_logout_url = isset($clock_logout_url) ? $clock_logout_url : add_query_arg('sistur_logout', '1');
$clock_readonly_notice = isset($clock_readonly_notice) ? $clock_readonly_notice : '';
$clock_ajax_nonce = isset($clock_ajax_nonce) ? $clock_ajax_nonce : wp_create_nonce('sistur_time_public_nonce');
$clock_employee_internal_id = isset($clock_employee_internal_id) ? (int) $clock_employee_internal_id : (int) $clock_target_employee_id;
?>

<div class="sistur-clock-container" data-employee-id="<?php echo esc_attr($clock_target_employee_id); ?>">
    <div class="sistur-clock-card">
        <div class="clock-header">
            <div class="employee-info">
                <div class="employee-avatar">
                    <?php echo esc_html(strtoupper(substr($clock_employee_display['nome'], 0, 1))); ?>
                </div>
                <div class="employee-details">
                    <h2 class="employee-name"><?php echo esc_html($clock_employee_display['nome']); ?></h2>
                    <p class="employee-subtitle"><?php esc_html_e('Registro de Ponto', 'sistur'); ?></p>
                </div>
            </div>
            <?php if ($clock_show_logout) : ?>
                <a href="<?php echo esc_url($clock_logout_url); ?>" class="logout-btn" id="logout-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9M16 17L21 12M21 12L16 7M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <?php esc_html_e('Sair', 'sistur'); ?>
                </a>
            <?php endif; ?>
        </div>

        <div class="clock-display">
            <div id="current-time" class="current-time">--:--:--</div>
            <div id="current-date" class="current-date">-- de --- de ----</div>
        </div>

        <?php if ($clock_requires_validation && $clock_can_punch) : ?>
            <div id="wifi-status" class="wifi-status checking">
                <div class="wifi-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2" />
                    </svg>
                </div>
                <div class="wifi-text">
                    <div id="wifi-status-text"><?php esc_html_e('Verificando localização...', 'sistur'); ?></div>
                    <div id="wifi-network-name" class="wifi-network-name"></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$clock_can_punch && $clock_readonly_notice) : ?>
            <div class="clock-readonly-notice">
                <span class="dashicons dashicons-info"></span>
                <?php echo wp_kses_post($clock_readonly_notice); ?>
            </div>
        <?php endif; ?>

        <div id="clock-message" class="clock-message" style="display: none;">
            <div class="message-icon" id="message-icon"></div>
            <div class="message-text" id="message-text"></div>
        </div>

        <div class="realtime-bank-widget" id="realtime-bank">
            <div class="realtime-grid">
                <div class="realtime-card today">
                    <div class="realtime-icon">⏱️</div>
                    <div class="realtime-content">
                        <div class="realtime-label"><?php esc_html_e('Hoje', 'sistur'); ?></div>
                        <div class="realtime-value" id="today-worked">--h--</div>
                        <div class="realtime-deviation" id="today-deviation">--</div>
                    </div>
                </div>
                <div class="realtime-card week">
                    <div class="realtime-icon">📅</div>
                    <div class="realtime-content">
                        <div class="realtime-label"><?php esc_html_e('Semana', 'sistur'); ?></div>
                        <div class="realtime-value" id="week-worked">--h--</div>
                        <div class="realtime-deviation" id="week-deviation-rt">--</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="clock-action">
            <button type="button" id="clock-punch-btn" class="punch-btn"
                <?php echo (!$clock_can_punch || $clock_requires_validation) ? 'disabled' : ''; ?>>
                <div class="punch-btn-content">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" />
                        <path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <span id="punch-btn-text"><?php esc_html_e('Registrar Ponto', 'sistur'); ?></span>
                </div>
                <div class="punch-type-label" id="punch-type-label">
                    <?php echo esc_html($clock_next_punch_type_label); ?>
                </div>
            </button>
        </div>

        <div class="today-entries">
            <h3 class="entries-title"><?php esc_html_e('Registros de Hoje', 'sistur'); ?></h3>

            <?php if (empty($clock_today_entries)) : ?>
                <div class="empty-entries">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="#cbd5e0" stroke-width="2" />
                        <path d="M12 6V12L16 14" stroke="#cbd5e0" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <p><?php esc_html_e('Nenhum registro ainda hoje', 'sistur'); ?></p>
                </div>
            <?php else : ?>
                <div class="entries-list">
                    <?php foreach ($clock_today_entries as $entry) :
                        $punch_time = new DateTime($entry['punch_time']);
                        $icons = array(
                            'clock_in' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M13 16L18 12L13 8M18 12H3M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                            'clock_out' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M11 16L6 12L11 8M6 12H21M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                            'lunch_start' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="6" y="6" width="4" height="12" fill="currentColor"/><rect x="14" y="6" width="4" height="12" fill="currentColor"/></svg>',
                            'lunch_end' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><polygon points="6,6 6,18 18,12" fill="currentColor"/></svg>'
                        );
                        $icon = isset($icons[$entry['punch_type']]) ? $icons[$entry['punch_type']] : '';
                        $label = SISTUR_Time_Tracking::translate_punch_type($entry['punch_type']);
                        ?>
                        <div class="entry-item">
                            <div class="entry-icon <?php echo esc_attr($entry['punch_type']); ?>">
                                <?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <div class="entry-details">
                                <div class="entry-type"><?php echo esc_html($label); ?></div>
                                <div class="entry-time"><?php echo esc_html($punch_time->format('H:i:s')); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        $employee_id = $clock_target_employee_id;
        include __DIR__ . '/time-bank-widget.php';
        ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    <?php if (isset($_GET['sistur_logout'])) : ?>
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: { action: 'sistur_funcionario_logout' },
            success: function() {
                window.location.href = '<?php echo home_url('/login-funcionario/'); ?>';
            }
        });
        return;
    <?php endif; ?>

    const requiresValidation = <?php echo $clock_requires_validation ? 'true' : 'false'; ?>;
    const hasLocations = <?php echo $clock_has_locations ? 'true' : 'false'; ?>;
    const canPunch = <?php echo $clock_can_punch ? 'true' : 'false'; ?>;

    let isLocationAuthorized = !requiresValidation;
    let currentLocation = null;

    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        $('#current-time').text(`${hours}:${minutes}:${seconds}`);

        const days = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        const dateStr = `${days[now.getDay()]}, ${now.getDate()} de ${months[now.getMonth()]} de ${now.getFullYear()}`;
        $('#current-date').text(dateStr);
    }

    updateClock();
    setInterval(updateClock, 1000);

    function updateLocationStatus(status, message) {
        const statusDiv = $('#wifi-status');
        if (!statusDiv.length) {
            return;
        }
        const textDiv = $('#wifi-status-text');
        const networkDiv = $('#wifi-network-name');
        statusDiv.removeClass('checking authorized error').addClass(status);
        textDiv.text(message);
        networkDiv.text('');
    }

    function checkGeolocation() {
        if (!requiresValidation || !canPunch) {
            return;
        }
        if (!hasLocations) {
            isLocationAuthorized = true;
            $('#clock-punch-btn').prop('disabled', false);
            return;
        }

        updateLocationStatus('checking', '<?php echo esc_js(__('Verificando localização...', 'sistur')); ?>');

        if (!navigator.geolocation) {
            updateLocationStatus('error', '<?php echo esc_js(__('Navegador não suporta geolocalização', 'sistur')); ?>');
            isLocationAuthorized = false;
            $('#clock-punch-btn').prop('disabled', true);
            return;
        }

        navigator.geolocation.getCurrentPosition(function(position) {
            currentLocation = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude
            };
            verifyLocation(currentLocation.latitude, currentLocation.longitude);
        }, function() {
            updateLocationStatus('error', '<?php echo esc_js(__('Não foi possível obter sua localização. Por favor, permita o acesso à localização.', 'sistur')); ?>');
            isLocationAuthorized = false;
            $('#clock-punch-btn').prop('disabled', true);
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 30000
        });
    }

    function verifyLocation(latitude, longitude) {
        updateLocationStatus('checking', '<?php echo esc_js(__('Validando localização...', 'sistur')); ?>');

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'sistur_validate_location',
                latitude: latitude,
                longitude: longitude
            },
            success: function(response) {
                if (response.success && response.data.authorized) {
                    isLocationAuthorized = true;
                    $('#clock-punch-btn').prop('disabled', false);
                    updateLocationStatus('authorized', '<?php echo esc_js(__('Localização autorizada', 'sistur')); ?>');
                } else {
                    isLocationAuthorized = false;
                    updateLocationStatus('error', '<?php echo esc_js(__('Você não está em uma localização autorizada', 'sistur')); ?>');
                    $('#clock-punch-btn').prop('disabled', true);
                }
            },
            error: function() {
                isLocationAuthorized = false;
                updateLocationStatus('error', '<?php echo esc_js(__('Erro ao validar localização', 'sistur')); ?>');
                $('#clock-punch-btn').prop('disabled', true);
            }
        });
    }

    if (requiresValidation && canPunch) {
        if (hasLocations) {
            setTimeout(checkGeolocation, 1000);
        }
    } else if (canPunch) {
        $('#clock-punch-btn').prop('disabled', false);
    }

    $('#clock-punch-btn').on('click', function() {
        if (!canPunch) {
            return;
        }
        if (requiresValidation && !isLocationAuthorized) {
            showMessage('error', '<?php echo esc_js(__('Você precisa estar em uma localização autorizada', 'sistur')); ?>');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).addClass('loading');
        $('#punch-btn-text').text('<?php echo esc_js(__('Registrando...', 'sistur')); ?>');

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'sistur_time_public_clock',
                employee_id: <?php echo (int) $clock_employee_internal_id; ?>,
                latitude: currentLocation ? currentLocation.latitude : null,
                longitude: currentLocation ? currentLocation.longitude : null,
                nonce: '<?php echo esc_js($clock_ajax_nonce); ?>'
            },
            success: function(response) {
                btn.removeClass('loading');
                if (response.success) {
                    btn.addClass('success');
                    $('#punch-btn-text').text('<?php echo esc_js(__('Ponto Registrado!', 'sistur')); ?>');
                    showMessage('success', response.data.message || '<?php echo esc_js(__('Ponto registrado com sucesso!', 'sistur')); ?>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    btn.prop('disabled', false);
                    $('#punch-btn-text').text('<?php echo esc_js(__('Registrar Ponto', 'sistur')); ?>');
                    showMessage('error', response.data.message || '<?php echo esc_js(__('Erro ao registrar ponto', 'sistur')); ?>');
                }
            },
            error: function() {
                btn.removeClass('loading').prop('disabled', false);
                $('#punch-btn-text').text('<?php echo esc_js(__('Registrar Ponto', 'sistur')); ?>');
                showMessage('error', '<?php echo esc_js(__('Erro de conexão. Verifique sua internet e tente novamente.', 'sistur')); ?>');
            }
        });
    });

    function showMessage(type, text) {
        const messageDiv = $('#clock-message');
        const iconDiv = $('#message-icon');
        const textDiv = $('#message-text');
        const icons = {
            success: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            error: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        };
        iconDiv.html(icons[type] || icons.error);
        textDiv.text(text);
        messageDiv.removeClass('success error').addClass(type).fadeIn();
        setTimeout(function() { messageDiv.fadeOut(); }, 5000);
    }

    let lastPunchTime = null;
    let lastWorkedMinutes = 0;
    let expectedMinutes = <?php echo (int) $clock_expected_minutes; ?>;
    const todayEntries = <?php echo wp_json_encode($clock_today_entries); ?>;

    function formatMinutes(minutes) {
        const hours = Math.floor(Math.abs(minutes) / 60);
        const mins = Math.abs(minutes) % 60;
        return hours + 'h' + String(mins).padStart(2, '0');
    }

    function updateRealtimeCounter() {
        if (!todayEntries) {
            return;
        }
        let totalMinutes = lastWorkedMinutes;
        if (todayEntries.length % 2 === 1 && lastPunchTime) {
            const lastPunch = new Date(lastPunchTime);
            const now = new Date();
            const diffMinutes = Math.floor((now - lastPunch) / (1000 * 60));
            totalMinutes = lastWorkedMinutes + diffMinutes;
        }
        $('#today-worked').text(formatMinutes(totalMinutes));
        const deviation = totalMinutes - expectedMinutes;
        const deviationFormatted = (deviation >= 0 ? '+' : '') + formatMinutes(deviation);
        const todayDev = $('#today-deviation');
        todayDev.text(deviationFormatted);
        todayDev.removeClass('positive negative zero');
        if (deviation > 0) todayDev.addClass('positive');
        else if (deviation < 0) todayDev.addClass('negative');
        else todayDev.addClass('zero');
    }

    function loadRealtimeBank() {
        const employeeId = <?php echo (int) $clock_target_employee_id; ?>;
        if (!employeeId) {
            $('#today-worked').text('N/A');
            $('#today-deviation').text('--');
            $('#week-worked').text('N/A');
            $('#week-deviation-rt').text('--');
            return;
        }

        $.ajax({
            url: '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/current',
            type: 'GET',
            success: function(response) {
                lastWorkedMinutes = response.worked_minutes || 0;
                if (response.last_punch_time) {
                    lastPunchTime = response.last_punch_time;
                }
                updateRealtimeCounter();
            }
        });

        $.ajax({
            url: '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/weekly',
            type: 'GET',
            success: function(response) {
                $('#week-worked').text(response.summary.total_worked_formatted);
                const weekDev = $('#week-deviation-rt');
                weekDev.text(response.summary.week_deviation_formatted);
                weekDev.removeClass('positive negative zero');
                if (response.summary.week_deviation_minutes > 0) weekDev.addClass('positive');
                else if (response.summary.week_deviation_minutes < 0) weekDev.addClass('negative');
                else weekDev.addClass('zero');
            }
        });
    }

    loadRealtimeBank();
    setInterval(updateRealtimeCounter, 60000);
    setInterval(loadRealtimeBank, 30000);
});
</script>
