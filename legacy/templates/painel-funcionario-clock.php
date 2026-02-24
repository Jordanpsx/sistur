<?php
/**
 * Template Simplificado de Registro de Ponto - Com Validação Wi-Fi
 *
 * @package SISTUR
 */

// Verificar se está logado
$session = SISTUR_Session::get_instance();
$is_logged_in = $session->is_employee_logged_in();
$employee = sistur_get_current_employee();

// Se não estiver logado, mostrar formulário de login
if (!$is_logged_in || !$employee) {
    include SISTUR_PLUGIN_DIR . 'templates/login-funcionario.php';
    return;
}

// Instanciar classes de validação
// WiFi validation removido - mantendo apenas geolocalização
$location_manager = SISTUR_Authorized_Locations::get_instance();

$location_enabled = $location_manager->is_location_validation_enabled();
$has_locations = $location_manager->has_authorized_locations();

// Só exigir validação se houver localizações cadastradas
$requires_validation = ($location_enabled && $has_locations);

// Obter registros de ponto do dia
global $wpdb;
$table = $wpdb->prefix . 'sistur_time_entries';
$today = current_time('Y-m-d');

$today_entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
    $employee['id'],
    $today
), ARRAY_A);

// Determinar próximo tipo de registro (USANDO TIPOS PADRONIZADOS)
$next_punch_type = 'clock_in';
$punch_count = count($today_entries);

if ($punch_count > 0) {
    $types = ['clock_in', 'lunch_start', 'lunch_end', 'clock_out'];
    if ($punch_count < count($types)) {
        $next_punch_type = $types[$punch_count];
    } else {
        $next_punch_type = 'extra';
    }
}

// Traduzir o tipo para exibição
$next_punch_type_label = SISTUR_Time_Tracking::translate_punch_type($next_punch_type);
?>

<div class="sistur-clock-container">
    <div class="sistur-clock-card">
        <!-- Cabeçalho com informações do funcionário -->
        <div class="clock-header">
            <div class="employee-info">
                <div class="employee-avatar">
                    <?php echo strtoupper(substr($employee['nome'], 0, 1)); ?>
                </div>
                <div class="employee-details">
                    <h2 class="employee-name"><?php echo esc_html($employee['nome']); ?></h2>
                    <p class="employee-subtitle">Registro de Ponto</p>
                </div>
            </div>
            <a href="<?php echo add_query_arg('sistur_logout', '1'); ?>" class="logout-btn" id="logout-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9M16 17L21 12M21 12L16 7M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Sair
            </a>
        </div>

        <!-- Relógio atual -->
        <div class="clock-display">
            <div id="current-time" class="current-time">--:--:--</div>
            <div id="current-date" class="current-date">-- de --- de ----</div>
        </div>

        <!-- Status de Localização -->
        <?php if ($requires_validation): ?>
        <div id="wifi-status" class="wifi-status checking">
            <div class="wifi-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
                </svg>
            </div>
            <div class="wifi-text">
                <div id="wifi-status-text">Verificando localização...</div>
                <div id="wifi-network-name" class="wifi-network-name"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Mensagem de erro/sucesso -->
        <div id="clock-message" class="clock-message" style="display: none;">
            <div class="message-icon" id="message-icon"></div>
            <div class="message-text" id="message-text"></div>
        </div>

        <!-- Widget de Banco de Horas em Tempo Real -->
        <div class="realtime-bank-widget" id="realtime-bank">
            <div class="realtime-grid">
                <div class="realtime-card today">
                    <div class="realtime-icon">⏱️</div>
                    <div class="realtime-content">
                        <div class="realtime-label">Hoje</div>
                        <div class="realtime-value" id="today-worked">--h--</div>
                        <div class="realtime-deviation" id="today-deviation">--</div>
                    </div>
                </div>
                <div class="realtime-card week">
                    <div class="realtime-icon">📅</div>
                    <div class="realtime-content">
                        <div class="realtime-label">Semana</div>
                        <div class="realtime-value" id="week-worked">--h--</div>
                        <div class="realtime-deviation" id="week-deviation-rt">--</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botão de registrar ponto -->
        <div class="clock-action">
            <button type="button" id="clock-punch-btn" class="punch-btn" <?php echo $requires_validation ? 'disabled' : ''; ?>>
                <div class="punch-btn-content">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span id="punch-btn-text">Registrar Ponto</span>
                </div>
                <div class="punch-type-label" id="punch-type-label">
                    <?php echo esc_html($next_punch_type_label); ?>
                </div>
            </button>
        </div>

        <!-- Registros de hoje -->
        <div class="today-entries">
            <h3 class="entries-title">Registros de Hoje</h3>

            <?php if (empty($today_entries)): ?>
            <div class="empty-entries">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="#cbd5e0" stroke-width="2"/>
                    <path d="M12 6V12L16 14" stroke="#cbd5e0" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <p>Nenhum registro ainda hoje</p>
            </div>
            <?php else: ?>
            <div class="entries-list">
                <?php foreach ($today_entries as $entry):
                    $punch_time = new DateTime($entry['punch_time']);
                    $punch_type_icons = [
                        'clock_in' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M13 16L18 12L13 8M18 12H3M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                        'clock_out' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M11 16L6 12L11 8M6 12H21M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
                        'lunch_start' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="6" y="6" width="4" height="12" fill="currentColor"/><rect x="14" y="6" width="4" height="12" fill="currentColor"/></svg>',
                        'lunch_end' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><polygon points="6,6 6,18 18,12" fill="currentColor"/></svg>'
                    ];
                    $icon = isset($punch_type_icons[$entry['punch_type']]) ? $punch_type_icons[$entry['punch_type']] : '';
                    $punch_type_label = SISTUR_Time_Tracking::translate_punch_type($entry['punch_type']);
                ?>
                <div class="entry-item">
                    <div class="entry-icon <?php echo esc_attr($entry['punch_type']); ?>">
                        <?php echo $icon; ?>
                    </div>
                    <div class="entry-details">
                        <div class="entry-type"><?php echo esc_html($punch_type_label); ?></div>
                        <div class="entry-time"><?php echo $punch_time->format('H:i:s'); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Widget de Banco de Horas -->
        <?php
        $employee_id = $employee['id'];
        include(plugin_dir_path(__FILE__) . 'components/time-bank-widget.php');
        ?>
    </div>
</div>

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

    const requiresValidation = <?php echo $requires_validation ? 'true' : 'false'; ?>;
    const hasLocations = <?php echo $has_locations ? 'true' : 'false'; ?>;

    let isLocationAuthorized = !requiresValidation;
    let currentLocation = null;

    // Atualizar relógio
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');

        $('#current-time').text(`${hours}:${minutes}:${seconds}`);

        const days = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

        const dateStr = `${days[now.getDay()]}, ${now.getDate()} de ${months[now.getMonth()]} de ${now.getFullYear()}`;
        $('#current-date').text(dateStr);
    }

    updateClock();
    setInterval(updateClock, 1000);

    function updateLocationStatus(status, message) {
        const statusDiv = $('#wifi-status');
        const textDiv = $('#wifi-status-text');
        const networkDiv = $('#wifi-network-name');

        statusDiv.removeClass('checking authorized error').addClass(status);
        textDiv.text(message);

        if (status === 'authorized') {
            networkDiv.text('');
        } else {
            networkDiv.text('');
        }
    }

    // Verificar Geolocalização
    function checkGeolocation() {
        if (!requiresValidation) {
            return;
        }

        // Se não há localizações cadastradas, libera o botão
        if (!hasLocations) {
            isLocationAuthorized = true;
            $('#clock-punch-btn').prop('disabled', false);
            return;
        }

        updateLocationStatus('checking', 'Verificando localização...');

        if (!navigator.geolocation) {
            // Navegador não suporta geolocalização
            console.log('Navegador não suporta geolocalização');
            updateLocationStatus('error', 'Navegador não suporta geolocalização');
            isLocationAuthorized = false;
            $('#clock-punch-btn').prop('disabled', true);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            function(position) {
                currentLocation = {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                };

                verifyLocation(currentLocation.latitude, currentLocation.longitude);
            },
            function(error) {
                console.log('Erro ao obter localização:', error);
                updateLocationStatus('error', 'Não foi possível obter sua localização. Por favor, permita o acesso à localização.');
                isLocationAuthorized = false;
                $('#clock-punch-btn').prop('disabled', true);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 30000
            }
        );
    }

    function verifyLocation(latitude, longitude) {
        updateLocationStatus('checking', 'Validando localização...');

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
                    const locationName = response.data.location ? response.data.location.location_name : 'Localização';
                    updateLocationStatus('authorized', 'Localização autorizada: ' + locationName);

                    // Se localização está OK, libera o botão
                    $('#clock-punch-btn').prop('disabled', false);
                } else {
                    isLocationAuthorized = false;
                    updateLocationStatus('error', 'Você não está em uma localização autorizada');
                    $('#clock-punch-btn').prop('disabled', true);
                }
            },
            error: function() {
                console.log('Erro ao validar localização');
                isLocationAuthorized = false;
                updateLocationStatus('error', 'Erro ao validar localização');
                $('#clock-punch-btn').prop('disabled', true);
            }
        });
    }

    // Verificar Localização ao carregar
    if (requiresValidation) {
        // Tenta geolocalização se houver localizações cadastradas
        if (hasLocations) {
            setTimeout(checkGeolocation, 1000);
        }
    }

    // Registrar ponto
    $('#clock-punch-btn').on('click', function() {
        if (requiresValidation && !isLocationAuthorized) {
            showMessage('error', 'Você precisa estar em uma localização autorizada');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).addClass('loading');
        $('#punch-btn-text').text('Registrando...');

        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'sistur_time_public_clock',
                employee_id: <?php echo $employee['id']; ?>,
                latitude: currentLocation ? currentLocation.latitude : null,
                longitude: currentLocation ? currentLocation.longitude : null,
                nonce: '<?php echo wp_create_nonce('sistur_time_public_nonce'); ?>'
            },
            success: function(response) {
                btn.removeClass('loading');

                if (response.success) {
                    btn.addClass('success');
                    $('#punch-btn-text').text('Ponto Registrado!');
                    showMessage('success', response.data.message || 'Ponto registrado com sucesso!');

                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    btn.prop('disabled', false);
                    $('#punch-btn-text').text('Registrar Ponto');
                    showMessage('error', response.data.message || 'Erro ao registrar ponto');
                }
            },
            error: function() {
                btn.removeClass('loading').prop('disabled', false);
                $('#punch-btn-text').text('Registrar Ponto');
                showMessage('error', 'Erro de conexão. Verifique sua internet e tente novamente.');
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

        setTimeout(function() {
            messageDiv.fadeOut();
        }, 5000);
    }

    // ===== BANCO DE HORAS EM TEMPO REAL =====
    let lastPunchTime = null;
    let lastWorkedMinutes = 0;
    let expectedMinutes = <?php 
        // Buscar carga horária esperada do funcionário
        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT ct.carga_horaria_diaria_minutos 
             FROM {$wpdb->prefix}sistur_employees e
             LEFT JOIN {$wpdb->prefix}sistur_contract_types ct ON e.contract_type_id = ct.id
             WHERE e.id = %d",
            $employee['id']
        ));
        echo $contract && $contract->carga_horaria_diaria_minutos ? $contract->carga_horaria_diaria_minutos : 480;
    ?>;

    function formatMinutes(minutes) {
        const hours = Math.floor(Math.abs(minutes) / 60);
        const mins = Math.abs(minutes) % 60;
        return hours + 'h' + String(mins).padStart(2, '0');
    }

    function updateRealtimeCounter() {
        if (!lastPunchTime) return;

        const todayEntries = <?php echo json_encode($today_entries); ?>;
        
        // Calcular tempo trabalhado até agora
        let totalMinutes = lastWorkedMinutes;

        // Se há uma batida ímpar (funcionário está no trabalho), adicionar tempo desde a última batida
        if (todayEntries.length % 2 === 1) {
            const lastPunch = new Date(lastPunchTime);
            const now = new Date();
            const diffMs = now - lastPunch;
            const diffMinutes = Math.floor(diffMs / (1000 * 60));
            totalMinutes = lastWorkedMinutes + diffMinutes;
        }

        // Atualizar display de horas trabalhadas
        $('#today-worked').text(formatMinutes(totalMinutes));

        // Calcular desvio
        const deviation = totalMinutes - expectedMinutes;
        const deviationFormatted = (deviation >= 0 ? '+' : '') + formatMinutes(deviation);
        
        const todayDev = $('#today-deviation');
        todayDev.text(deviationFormatted);
        todayDev.removeClass('positive negative zero');

        if (deviation > 0) {
            todayDev.addClass('positive');
        } else if (deviation < 0) {
            todayDev.addClass('negative');
        } else {
            todayDev.addClass('zero');
        }
    }

    function loadRealtimeBank() {
        const employeeId = <?php echo $employee['id']; ?>;

        // Verificar se é admin (ID = 0) ou ID inválido
        if (!employeeId || employeeId === 0) {
            console.log('Employee ID inválido ou usuário admin - não carrega banco de horas');
            $('#today-worked').text('N/A');
            $('#today-deviation').text('Admin');
            $('#week-worked').text('N/A');
            $('#week-deviation-rt').text('Admin');
            return;
        }

        // Carregar dados de hoje
        $.ajax({
            url: '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/current',
            type: 'GET',
            success: function(response) {
                // Armazenar dados para contador em tempo real
                lastWorkedMinutes = response.worked_minutes || 0;
                
                // Se há batidas, pegar a última
                if (response.last_punch_time) {
                    lastPunchTime = response.last_punch_time;
                }

                // Atualizar exibição
                updateRealtimeCounter();
            },
            error: function() {
                $('#today-worked').text('--');
                $('#today-deviation').text('--');
            }
        });

        // Carregar dados da semana
        $.ajax({
            url: '<?php echo rest_url('sistur/v1/time-bank/'); ?>' + employeeId + '/weekly',
            type: 'GET',
            success: function(response) {
                $('#week-worked').text(response.summary.total_worked_formatted);

                const weekDev = $('#week-deviation-rt');
                weekDev.text(response.summary.week_deviation_formatted);
                weekDev.removeClass('positive negative zero');

                if (response.summary.week_deviation_minutes > 0) {
                    weekDev.addClass('positive');
                } else if (response.summary.week_deviation_minutes < 0) {
                    weekDev.addClass('negative');
                } else {
                    weekDev.addClass('zero');
                }
            },
            error: function() {
                $('#week-worked').text('--');
                $('#week-deviation-rt').text('--');
            }
        });
    }

    // Carregar dados ao iniciar
    loadRealtimeBank();

    // Atualizar contador local a cada minuto
    setInterval(updateRealtimeCounter, 60000);

    // Sincronizar com servidor a cada 30 segundos
    setInterval(loadRealtimeBank, 30000);
});
</script>

<style>
/* Modern & Relaxing Theme Variables */
:root {
    --sistur-relax-primary: #0d9488; /* Teal 600 */
    --sistur-relax-primary-dark: #0f766e; /* Teal 700 */
    --sistur-relax-primary-light: #ccfbf1; /* Teal 100 */
    
    --sistur-relax-bg: #f8fafc; /* Slate 50 */
    --sistur-relax-card-bg: #ffffff;
    
    --sistur-relax-text-main: #334155; /* Slate 700 */
    --sistur-relax-text-muted: #64748b; /* Slate 500 */
    
    --sistur-status-success: #10b981;
    --sistur-status-warning: #f59e0b;
    --sistur-status-error: #ef4444;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background-color: var(--sistur-relax-bg);
}

.sistur-clock-container {
    min-height: 100vh;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 40px;
    padding-bottom: 40px;
}

.sistur-clock-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.08); /* Softer shadow */
    max-width: 500px; /* Slightly narrower for better focus */
    width: 100%;
    overflow: hidden;
    border: 1px solid #f1f5f9;
}

/* Cabeçalho */
.clock-header {
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); /* Teal Gradient */
    color: white;
    padding: 32px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.employee-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.employee-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    border: 3px solid rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(5px);
}

.employee-details {
    flex: 1;
}

.employee-name {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
    line-height: 1.2;
    color: white; /* Force white text */
}

.employee-subtitle {
    font-size: 14px;
    opacity: 0.9;
    margin: 4px 0 0 0;
    font-weight: 500;
    color: white; /* Force white text */
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 12px;
    color: white !important; /* Force white text */
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s;
    backdrop-filter: blur(5px);
}

.logout-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
    color: white !important;
}

/* Relógio */
.clock-display {
    padding: 30px 24px;
    text-align: center;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.current-time {
    font-size: 64px;
    font-weight: 800;
    color: var(--sistur-relax-text-main);
    letter-spacing: -2px;
    margin-bottom: 8px;
    font-variant-numeric: tabular-nums;
    line-height: 1;
}

.current-date {
    font-size: 16px;
    color: var(--sistur-relax-text-muted);
    font-weight: 500;
}

/* Realtime Widget (Overrides) */
.realtime-bank-widget {
    padding: 0 24px;
    margin-top: -24px; /* Pull up to overlap */
    position: relative;
    z-index: 10;
}

.realtime-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.realtime-card {
    background: white;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.realtime-label {
    font-size: 12px;
    color: var(--sistur-relax-text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.realtime-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--sistur-relax-text-main);
}

.realtime-deviation {
    font-size: 13px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    margin-top: 4px;
}

.realtime-deviation.positive { color: var(--sistur-status-success); background: #dcfce7; }
.realtime-deviation.negative { color: var(--sistur-status-error); background: #fee2e2; }
.realtime-deviation.zero { color: var(--sistur-relax-text-muted); background: #f1f5f9; }


/* Botão de Ponto */
.clock-action {
    padding: 32px 24px 24px 24px;
}

.punch-btn {
    width: 100%;
    padding: 28px;
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); /* Teal */
    border: none;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    box-shadow: 0 10px 20px -5px rgba(13, 148, 136, 0.3);
    overflow: hidden;
}

.punch-btn:hover:not(:disabled) {
    transform: translateY(-4px);
    box-shadow: 0 15px 30px -5px rgba(13, 148, 136, 0.4);
}

.punch-btn:active:not(:disabled) {
    transform: scale(0.98);
}

.punch-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    background: #94a3b8;
    box-shadow: none;
}

.punch-btn-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    color: white;
}

#punch-btn-text {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.punch-type-label {
    margin-top: 8px;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(0, 0, 0, 0.1);
    padding: 4px 12px;
    border-radius: 20px;
}

/* Registros de hoje */
.today-entries {
    padding: 24px;
    background: white;
    border-top: 1px solid #f1f5f9;
}

.entries-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--sistur-relax-text-muted);
    margin-bottom: 16px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.empty-entries {
    text-align: center;
    padding: 30px;
    color: #cbd5e1;
    border: 2px dashed #e2e8f0;
    border-radius: 12px;
}

.entries-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.entry-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    transition: all 0.2s;
}

.entry-item:hover {
    transform: translateX(5px);
    border-color: #cbd5e1;
    background: white;
}

.entry-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: white;
}

.entry-icon.clock_in { background: #10b981; }
.entry-icon.lunch_start { background: #f59e0b; }
.entry-icon.lunch_end { background: #f59e0b; opacity: 0.8; }
.entry-icon.clock_out { background: #ef4444; }

.entry-details {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.entry-type {
    font-weight: 600;
    color: var(--sistur-relax-text-main);
    font-size: 15px;
}

.entry-time {
    font-family: monospace;
    font-size: 16px;
    font-weight: 700;
    color: var(--sistur-relax-text-main);
    background: white;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

/* Status Messages */
.clock-message {
    margin: 24px 24px 0 24px;
    padding: 16px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideDown 0.3s ease-out;
}

.clock-message.success {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #047857;
}

.clock-message.error {
    background: #fef2f2;
    border: 1px solid #ef4444;
    color: #b91c1c;
}

/* Wifi Status */
.wifi-status {
    margin: 24px 24px 0 24px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsividade */
@media (max-width: 480px) {
    .sistur-clock-container {
        padding: 0;
    }
    
    .sistur-clock-card {
        border-radius: 0;
        min-height: 100vh;
        box-shadow: none;
    }
    
    .current-time {
        font-size: 12vw;
    }
}
</style>
