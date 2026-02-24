<?php
/**
 * Template do Painel do Funcionário
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

// Verificar se é administrador
$is_admin = isset($employee['is_admin']) && $employee['is_admin'];

// ID do funcionário a ser visualizado
$view_employee_id = $employee['id'];

// Se for admin e houver um funcionário selecionado
if ($is_admin && isset($_GET['employee_id'])) {
    $view_employee_id = intval($_GET['employee_id']);
}

// Carregar dados do funcionário a ser visualizado
global $wpdb;
$employees_table = $wpdb->prefix . 'sistur_employees';

if ($is_admin && $view_employee_id != $employee['id']) {
    $view_employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $employees_table WHERE id = %d",
        $view_employee_id
    ), ARRAY_A);

    if (!$view_employee) {
        $view_employee_id = $employee['id'];
        $view_employee = $employee;
    } else {
        // Converter para formato esperado
        $view_employee = array(
            'id' => $view_employee['id'],
            'nome' => $view_employee['name'],
            'email' => $view_employee['email'],
            'cpf' => $view_employee['cpf'],
            'is_admin' => false
        );
    }
} else {
    $view_employee = $employee;
}

// Carregar todos os funcionários se for admin
$all_employees = array();
if ($is_admin) {
    $all_employees = $wpdb->get_results(
        "SELECT id, name, email, position, department_id FROM $employees_table WHERE status = 1 ORDER BY name ASC",
        ARRAY_A
    );
}

// Carregar departamento
$department_name = '';
if ($is_admin && $view_employee_id != $employee['id']) {
    $departments_table = $wpdb->prefix . 'sistur_departments';
    $employee_full = $wpdb->get_row($wpdb->prepare(
        "SELECT e.*, d.name as department_name
        FROM $employees_table e
        LEFT JOIN $departments_table d ON e.department_id = d.id
        WHERE e.id = %d",
        $view_employee_id
    ), ARRAY_A);

    if ($employee_full) {
        $department_name = $employee_full['department_name'];
        $view_employee['position'] = $employee_full['position'];
        $view_employee['phone'] = $employee_full['phone'];
        $view_employee['hire_date'] = $employee_full['hire_date'];
    }
}
?>

<div class="sistur-painel sistur-container">
    <div class="painel-funcionario">
        <?php if ($is_admin) : ?>
            <!-- Seletor de Funcionário para Admins -->
            <div class="admin-employee-selector" style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffc107;">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <span style="font-weight: bold; color: #856404;">
                        <span class="dashicons dashicons-admin-users"></span>
                        <?php _e('Modo Administrador:', 'sistur'); ?>
                    </span>

                    <select id="admin-employee-selector" style="min-width: 300px; padding: 8px;">
                        <option value="<?php echo $employee['id']; ?>" <?php selected($view_employee_id, $employee['id']); ?>>
                            <?php printf(__('Meus Dados (%s)', 'sistur'), esc_html($employee['nome'])); ?>
                        </option>
                        <optgroup label="<?php _e('Outros Funcionários:', 'sistur'); ?>">
                            <?php foreach ($all_employees as $emp) : ?>
                                <?php if ($emp['id'] != $employee['id']) : ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php selected($view_employee_id, $emp['id']); ?>>
                                        <?php echo esc_html($emp['name']); ?>
                                        <?php if ($emp['position']) : ?>
                                            - <?php echo esc_html($emp['position']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>

                    <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-admin-generic" style="vertical-align: middle;"></span>
                        <?php _e('Gerenciar Funcionários', 'sistur'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="painel-welcome">
            <?php if ($is_admin && $view_employee_id != $employee['id']) : ?>
                <div style="background: rgba(255,255,255,0.2); padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <small><?php _e('Visualizando como administrador:', 'sistur'); ?></small>
                </div>
            <?php endif; ?>

            <h1><?php printf(__('Olá, %s!', 'sistur'), esc_html($view_employee['nome'])); ?></h1>

            <?php if ($is_admin && $view_employee_id != $employee['id']) : ?>
                <p>
                    <?php _e('Visualizando dados de:', 'sistur'); ?>
                    <strong><?php echo esc_html($view_employee['nome']); ?></strong>
                </p>
            <?php else : ?>
                <p><?php _e('Bem-vindo ao seu painel de funcionário.', 'sistur'); ?></p>
            <?php endif; ?>

            <a href="<?php echo add_query_arg('sistur_logout', '1'); ?>" class="sistur-btn sistur-btn-secondary">
                <?php _e('Sair', 'sistur'); ?>
            </a>
        </div>

        <div class="timebank-summary-grid">
            <div class="timebank-summary-card total">
                <div class="summary-icon">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div>
                    <p class="summary-label"><?php _e('Saldo Total', 'sistur'); ?></p>
                    <p class="summary-value" id="tb-total-balance">--</p>
                    <small id="tb-total-produced" class="summary-subvalue">--</small>
                </div>
            </div>

            <div class="timebank-summary-card week">
                <div class="summary-icon">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </div>
                <div>
                    <p class="summary-label"><?php _e('Semana Atual', 'sistur'); ?></p>
                    <p class="summary-value" id="tb-week-worked">--</p>
                    <small id="tb-week-deviation" class="summary-subvalue">--</small>
                </div>
            </div>

            <div class="timebank-summary-card month">
                <div class="summary-icon">
                    <span class="dashicons dashicons-chart-line"></span>
                </div>
                <div>
                    <p class="summary-label"><?php _e('Mês Atual', 'sistur'); ?></p>
                    <p class="summary-value" id="tb-month-worked">--</p>
                    <small id="tb-month-deviation" class="summary-subvalue">--</small>
                </div>
            </div>
        </div>

        <div class="sistur-tabs">
            <button class="sistur-tab active" data-tab="tab-ponto">
                <?php _e('Registro de Ponto', 'sistur'); ?>
            </button>
            <button class="sistur-tab" data-tab="tab-perfil">
                <?php _e('Perfil', 'sistur'); ?>
            </button>
            <button class="sistur-tab" data-tab="tab-atividades">
                <?php _e('Atividades', 'sistur'); ?>
            </button>
            <?php if ($is_admin) : ?>
                <button class="sistur-tab" data-tab="tab-historico">
                    <?php _e('Histórico Completo', 'sistur'); ?>
                </button>
            <?php endif; ?>
        </div>

        <!-- Tab: Registro de Ponto -->
        <div class="sistur-tab-content active" id="tab-ponto">
            <div class="painel-info-card">
                <h2><?php _e('Registro de Ponto', 'sistur'); ?></h2>
                <div class="time-clock-display" id="current-time"></div>

                <?php if (!$is_admin || $view_employee_id == $employee['id']) : ?>
                    <!-- Botões de ponto apenas para o próprio funcionário -->
                    <div class="time-punch-buttons">
                        <button class="time-punch-btn clock-in" data-type="clock_in">
                            <?php _e('Entrada', 'sistur'); ?>
                        </button>
                        <button class="time-punch-btn lunch" data-type="lunch_start">
                            <?php _e('Início Almoço', 'sistur'); ?>
                        </button>
                        <button class="time-punch-btn lunch" data-type="lunch_end">
                            <?php _e('Fim Almoço', 'sistur'); ?>
                        </button>
                        <button class="time-punch-btn clock-out" data-type="clock_out">
                            <?php _e('Saída', 'sistur'); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 4px; margin: 20px 0;">
                        <p style="margin: 0;">
                            <span class="dashicons dashicons-info"></span>
                            <?php _e('Visualizando em modo somente leitura. Para registrar ponto, volte para seus próprios dados.', 'sistur'); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="time-history" id="time-history">
                    <h3><?php _e('Registros de Hoje', 'sistur'); ?></h3>
                    <div id="today-entries"></div>
                </div>

                <?php if ($is_admin) : ?>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f0f0f1;">
                        <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <?php _e('Gerenciar Folha de Ponto Completa', 'sistur'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Perfil -->
        <div class="sistur-tab-content" id="tab-perfil">
            <div class="painel-info-card">
                <h2><?php _e('Informações do Funcionário', 'sistur'); ?></h2>

                <table class="widefat" style="margin-top: 20px;">
                    <tbody>
                        <tr>
                            <td style="width: 200px;"><strong><?php _e('Nome:', 'sistur'); ?></strong></td>
                            <td><?php echo esc_html($view_employee['nome']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Email:', 'sistur'); ?></strong></td>
                            <td><?php echo esc_html($view_employee['email']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('CPF:', 'sistur'); ?></strong></td>
                            <td><?php echo esc_html($view_employee['cpf']); ?></td>
                        </tr>
                        <?php if (!empty($view_employee['position'])) : ?>
                            <tr>
                                <td><strong><?php _e('Cargo:', 'sistur'); ?></strong></td>
                                <td><?php echo esc_html($view_employee['position']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($department_name)) : ?>
                            <tr>
                                <td><strong><?php _e('Departamento:', 'sistur'); ?></strong></td>
                                <td><?php echo esc_html($department_name); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($view_employee['phone'])) : ?>
                            <tr>
                                <td><strong><?php _e('Telefone:', 'sistur'); ?></strong></td>
                                <td><?php echo esc_html($view_employee['phone']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($view_employee['hire_date'])) : ?>
                            <tr>
                                <td><strong><?php _e('Data de Contratação:', 'sistur'); ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($view_employee['hire_date'])); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($is_admin) : ?>
                    <div style="margin-top: 20px;">
                        <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-edit"></span>
                            <?php _e('Editar Funcionário', 'sistur'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Atividades -->
        <div class="sistur-tab-content" id="tab-atividades">
            <div class="painel-info-card">
                <h2><?php _e('Atividades Recentes', 'sistur'); ?></h2>
                <div id="employee-activities">
                    <p><?php _e('Carregando atividades...', 'sistur'); ?></p>
                </div>
            </div>
        </div>

        <!-- Tab: Histórico Completo (somente admin) -->
        <?php if ($is_admin) : ?>
            <div class="sistur-tab-content" id="tab-historico">
                <div class="painel-info-card">
                    <h2><?php _e('Histórico Completo de Ponto', 'sistur'); ?></h2>

                    <div style="margin-bottom: 20px;">
                        <label><?php _e('Período:', 'sistur'); ?></label>
                        <input type="date" id="history-start-date" value="<?php echo date('Y-m-01'); ?>" />
                        <span> até </span>
                        <input type="date" id="history-end-date" value="<?php echo date('Y-m-t'); ?>" />
                        <button type="button" class="button" id="load-history">
                            <?php _e('Carregar', 'sistur'); ?>
                        </button>
                    </div>

                    <div id="employee-history">
                        <p><?php _e('Selecione um período e clique em Carregar.', 'sistur'); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentEmployeeId = <?php echo intval($view_employee_id); ?>;
    var isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
    var canPunch = <?php echo (!$is_admin || $view_employee_id == $employee['id']) ? 'true' : 'false'; ?>;
    var restBase = '<?php echo rest_url('sistur/v1/time-bank/'); ?>';

    // Seletor de funcionário (admin)
    <?php if ($is_admin) : ?>
        $('#admin-employee-selector').on('change', function() {
            var employeeId = $(this).val();
            var currentUrl = window.location.href.split('?')[0];
            window.location.href = currentUrl + '?employee_id=' + employeeId;
        });
    <?php endif; ?>

    // Relógio
    function updateClock() {
        var now = new Date();
        var timeStr = now.toLocaleTimeString('pt-BR');
        $('#current-time').text(timeStr);
    }
    updateClock();
    setInterval(updateClock, 1000);

    // Registrar ponto (apenas se permitido)
    if (canPunch) {
        $('.time-punch-btn').on('click', function() {
            var type = $(this).data('type');

            if (!confirm('<?php _e('Confirma o registro de ponto?', 'sistur'); ?>')) {
                return;
            }

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'sistur_time_public_clock',
                    punch_type: type,
                    nonce: '<?php echo wp_create_nonce('sistur_time_public_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        loadTodayEntries();
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });
    }

    // Carregar registros de hoje
    function loadTodayEntries() {
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'GET',
            data: {
                action: 'sistur_time_public_status',
                nonce: '<?php echo wp_create_nonce('sistur_time_public_nonce'); ?>',
                employee_id: currentEmployeeId
            },
            success: function(response) {
                if (response.success && response.data.entries && response.data.entries.length > 0) {
                    var html = '<ul style="list-style: none; padding: 0;">';
                    response.data.entries.forEach(function(entry) {
                        var time = entry.punch_time.split(' ')[1].substring(0, 5);
                        var typeLabel = getTypeLabel(entry.punch_type);
                        html += '<li style="padding: 10px; border-bottom: 1px solid #f0f0f1;">';
                        html += '<strong>' + typeLabel + ':</strong> ' + time;
                        html += '</li>';
                    });
                    html += '</ul>';
                    $('#today-entries').html(html);
                } else {
                    $('#today-entries').html('<p><?php _e('Nenhum registro hoje.', 'sistur'); ?></p>');
                }
            }
        });
    }

    function getTypeLabel(type) {
        var labels = {
            'clock_in': '<?php _e('Entrada', 'sistur'); ?>',
            'lunch_start': '<?php _e('Início Almoço', 'sistur'); ?>',
            'lunch_end': '<?php _e('Fim Almoço', 'sistur'); ?>',
            'clock_out': '<?php _e('Saída', 'sistur'); ?>'
        };
        return labels[type] || type;
    }

    loadTodayEntries();
    loadTimebankSummary();

    // Carregar histórico (admin)
    <?php if ($is_admin) : ?>
        $('#load-history').on('click', function() {
            var startDate = $('#history-start-date').val();
            var endDate = $('#history-end-date').val();

            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'sistur_time_get_sheet',
                    nonce: '<?php echo wp_create_nonce('sistur_time_tracking_nonce'); ?>',
                    employee_id: currentEmployeeId,
                    start_date: startDate,
                    end_date: endDate
                },
                beforeSend: function() {
                    $('#employee-history').html('<p><?php _e('Carregando...', 'sistur'); ?></p>');
                },
                success: function(response) {
                    if (response.success) {
                        renderHistory(response.data.sheet);
                    } else {
                        $('#employee-history').html('<p><?php _e('Erro ao carregar histórico.', 'sistur'); ?></p>');
                    }
                }
            });
        });

        function renderHistory(sheet) {
            if (sheet.length === 0) {
                $('#employee-history').html('<p><?php _e('Nenhum registro encontrado no período.', 'sistur'); ?></p>');
                return;
            }

            var html = '<table class="widefat striped">';
            html += '<thead><tr>';
            html += '<th><?php _e('Data', 'sistur'); ?></th>';
            html += '<th><?php _e('Entrada', 'sistur'); ?></th>';
            html += '<th><?php _e('Início Almoço', 'sistur'); ?></th>';
            html += '<th><?php _e('Fim Almoço', 'sistur'); ?></th>';
            html += '<th><?php _e('Saída', 'sistur'); ?></th>';
            html += '<th><?php _e('Status', 'sistur'); ?></th>';
            html += '</tr></thead><tbody>';

            sheet.forEach(function(day) {
                html += '<tr>';
                html += '<td>' + formatDate(day.date) + '</td>';

                var clockIn = findEntry(day.entries, 'clock_in');
                var lunchStart = findEntry(day.entries, 'lunch_start');
                var lunchEnd = findEntry(day.entries, 'lunch_end');
                var clockOut = findEntry(day.entries, 'clock_out');

                html += '<td>' + (clockIn || '-') + '</td>';
                html += '<td>' + (lunchStart || '-') + '</td>';
                html += '<td>' + (lunchEnd || '-') + '</td>';
                html += '<td>' + (clockOut || '-') + '</td>';
                html += '<td>' + (day.status || 'present') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            $('#employee-history').html(html);
        }

        function findEntry(entries, type) {
            if (!entries) return null;
            var entry = entries.find(function(e) { return e.punch_type === type; });
            return entry ? entry.punch_time.split(' ')[1].substring(0, 5) : null;
        }

        function formatDate(dateStr) {
            var parts = dateStr.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
    <?php endif; ?>

    // Logout
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
    <?php endif; ?>

    function loadTimebankSummary() {
        if (!currentEmployeeId) {
            return;
        }

        $.getJSON(restBase + currentEmployeeId + '/weekly')
            .done(function(response) {
                if (!response || !response.summary) {
                    return;
                }

                $('#tb-total-balance').text(response.summary.accumulated_bank_formatted || '--');

                var produced = response.summary.total_produced_minutes ? response.summary.total_produced_minutes : 0;
                var paid = response.summary.total_paid_minutes ? response.summary.total_paid_minutes : 0;
                $('#tb-total-produced').text('<?php echo esc_js(__('Produzido: %s | Compensado: %s', 'sistur')); ?>'
                    .replace('%s', response.summary.total_worked_formatted || '--')
                    .replace('%s', response.summary.week_deviation_formatted || '--'));

                $('#tb-week-worked').text(response.summary.total_worked_formatted || '--');
                var weekDev = response.summary.week_deviation_formatted || '--';
                $('#tb-week-deviation').text('<?php echo esc_js(__('Desvio: %s', 'sistur')); ?>'.replace('%s', weekDev));
            })
            .fail(function() {
                $('#tb-total-balance, #tb-week-worked').text('--');
                $('#tb-week-deviation, #tb-total-produced').text('--');
            });

        $.getJSON(restBase + currentEmployeeId + '/monthly')
            .done(function(response) {
                if (!response || !response.summary) {
                    return;
                }

                $('#tb-month-worked').text(response.summary.total_worked_formatted || '--');
                $('#tb-month-deviation').text('<?php echo esc_js(__('Desvio: %s', 'sistur')); ?>'
                    .replace('%s', response.summary.total_deviation_formatted || '--'));
            })
            .fail(function() {
                $('#tb-month-worked').text('--');
                $('#tb-month-deviation').text('--');
            });
    }
});
</script>

<style>
/* Modern & Relaxing Theme Variables */
:root {
    --sistur-relax-primary: #0d9488; /* Teal 600 */
    --sistur-relax-primary-dark: #0f766e; /* Teal 700 */
    --sistur-relax-primary-light: #ccfbf1; /* Teal 100 */
    
    --sistur-relax-bg: #f0fdfa; /* Mint Cream */
    --sistur-relax-card-bg: #ffffff;
    
    --sistur-relax-text-main: #334155; /* Slate 700 */
    --sistur-relax-text-muted: #64748b; /* Slate 500 */
    
    --sistur-status-success: #10b981;
    --sistur-status-warning: #f59e0b;
    --sistur-status-error: #ef4444;
    
    --sistur-shadow-soft: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    --sistur-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
}

/* Reset & Base */
.painel-funcionario {
    max-width: 1200px;
    margin: 40px auto;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: var(--sistur-relax-text-main);
}

/* Page Title Fix */
.page-title, .entry-title, h1 {
    color: var(--sistur-relax-text-main);
}

/* Welcome Section */
.painel-welcome {
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); /* Teal Gradient */
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 30px;
    box-shadow: 0 10px 25px -5px rgba(13, 148, 136, 0.3);
    position: relative;
    overflow: hidden;
}

.painel-welcome::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 40%;
    background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.1) 100%);
    clip-path: polygon(20% 0, 100% 0, 100% 100%, 0% 100%);
    pointer-events: none;
}

.painel-welcome h1 {
    margin: 0 0 10px 0;
    color: white;
    font-size: 2rem;
    font-weight: 700;
}

.painel-welcome p {
    font-size: 1.1rem;
    opacity: 0.95;
    margin-bottom: 20px;
}

.painel-welcome .sistur-btn-secondary {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.4);
    color: white;
    backdrop-filter: blur(5px);
    transition: all 0.2s ease;
}

.painel-welcome .sistur-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-2px);
}

/* Cards */
.painel-info-card {
    background: var(--sistur-relax-card-bg);
    padding: 30px;
    border-radius: 16px;
    box-shadow: var(--sistur-shadow-soft);
    margin-bottom: 24px;
    border: 1px solid #f1f5f9;
}

.painel-info-card h2 {
    color: var(--sistur-relax-primary-dark);
    font-size: 1.5rem;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--sistur-relax-primary-light);
    padding-bottom: 15px;
    display: inline-block;
}

/* Tabs */
.sistur-tabs {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    overflow-x: auto;
    padding-bottom: 5px; /* Scrollbar space */
    border-bottom: none;
}

.sistur-tab {
    padding: 12px 24px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 50px;
    cursor: pointer;
    color: var(--sistur-relax-text-muted);
    font-weight: 600;
    transition: all 0.2s ease;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

.sistur-tab:hover {
    color: var(--sistur-relax-primary);
    border-color: var(--sistur-relax-primary-light);
    transform: translateY(-1px);
}

.sistur-tab.active {
    background: var(--sistur-relax-primary);
    color: white !important;
    border-color: var(--sistur-relax-primary);
    box-shadow: 0 4px 6px -1px rgba(13, 148, 136, 0.3);
}

/* Clock Display */
.time-clock-display {
    text-align: center;
    font-size: 4rem;
    font-weight: 800;
    color: var(--sistur-relax-text-main);
    margin: 30px 0;
    font-variant-numeric: tabular-nums;
    letter-spacing: -2px;
    text-shadow: 2px 2px 0px #e2e8f0;
}

/* Punch Buttons */
.time-punch-buttons {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-top: 30px;
}

.time-punch-btn {
    padding: 20px 15px;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    color: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.time-punch-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.15);
}

.time-punch-btn:active {
    transform: translateY(-1px);
}

.time-punch-btn.clock-in {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.time-punch-btn.lunch {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.time-punch-btn.clock-out {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

/* Summary Grid */
.timebank-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-bottom: 40px;
}

.timebank-summary-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: var(--sistur-shadow-soft);
    display: flex;
    align-items: center;
    gap: 20px;
    border: 1px solid #f1f5f9;
    transition: transform 0.2s;
}

.timebank-summary-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--sistur-shadow-hover);
}

.timebank-summary-card.total { border-bottom: 4px solid var(--sistur-relax-primary); }
.timebank-summary-card.week { border-bottom: 4px solid var(--sistur-status-success); }
.timebank-summary-card.month { border-bottom: 4px solid var(--sistur-status-warning); }

.summary-icon {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: var(--sistur-relax-primary-light);
    color: var(--sistur-relax-primary-dark);
    display: flex;
    align-items: center;
    justify-content: center;
}

.summary-icon .dashicons {
    font-size: 28px;
    width: 28px;
    height: 28px;
}

.summary-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--sistur-relax-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}

.summary-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--sistur-relax-text-main);
    line-height: 1.2;
}

.summary-subvalue {
    font-size: 0.85rem;
    color: var(--sistur-relax-text-muted);
    display: block;
    margin-top: 4px;
}

/* History Button Fix */
#load-history {
    background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
    color: white !important;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

#load-history:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(13, 148, 136, 0.3);
}

/* Responsive */
@media (max-width: 768px) {
    .time-punch-buttons {
        grid-template-columns: 1fr 1fr;
    }
    
    .timebank-summary-grid {
        grid-template-columns: 1fr;
    }
    
    .painel-welcome {
        padding: 30px;
    }
    
    .time-clock-display {
        font-size: 3rem;
    }
}
</style>
