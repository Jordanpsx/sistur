<?php
/**
 * Dashboard Principal do SISTUR - Redesign Profissional
 *
 * @package SISTUR
 * @version 1.2.0
 */

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

// Obter estatísticas
global $wpdb;

$employees_table = $wpdb->prefix . 'sistur_employees';
$departments_table = $wpdb->prefix . 'sistur_departments';
$leads_table = $wpdb->prefix . 'sistur_leads';
$products_table = $wpdb->prefix . 'sistur_products';
$time_entries_table = $wpdb->prefix . 'sistur_time_entries';

// Estatísticas principais
$total_employees = $wpdb->get_var("SELECT COUNT(*) FROM $employees_table WHERE status = 1");
$total_departments = $wpdb->get_var("SELECT COUNT(*) FROM $departments_table WHERE status = 1");
$total_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table");
$total_products = $wpdb->get_var("SELECT COUNT(*) FROM $products_table WHERE status = 'active'");

// Leads por período
$leads_today = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $leads_table WHERE DATE(created_at) = %s",
    current_time('Y-m-d')
));

$leads_week = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $leads_table WHERE created_at >= %s",
    date('Y-m-d', strtotime('-7 days'))
));

$leads_month = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM $leads_table WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
    date('n'),
    date('Y')
));

// Registros de ponto hoje
$clock_ins_today = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT employee_id) FROM $time_entries_table WHERE DATE(punch_time) = %s AND punch_type = 'in'",
    current_time('Y-m-d')
));

// Leads novos (não contatados)
$new_leads = $wpdb->get_var("SELECT COUNT(*) FROM $leads_table WHERE status = 'novo'");

// Status do sistema
$business_hours = get_option('sistur_business_hours', array());
$current_day = strtolower(date('l'));
$is_open = false;
if (isset($business_hours[$current_day])) {
    $hours = $business_hours[$current_day];
    if ($hours['open'] !== 'closed') {
        $current_time = current_time('H:i');
        $is_open = ($current_time >= $hours['open'] && $current_time <= $hours['close']);
    }
}

// Dados para gráficos Chart.js

// Gráfico de Leads por dia (últimos 7 dias)
$leads_by_day = array();
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $leads_table WHERE DATE(created_at) = %s",
        $date
    ));
    $leads_by_day[] = array(
        'date' => date('d/m', strtotime($date)),
        'count' => (int) $count
    );
}

// Gráfico de Funcionários por Departamento
$employees_by_dept = $wpdb->get_results(
    "SELECT d.name as department, COUNT(e.id) as total
     FROM $departments_table d
     LEFT JOIN $employees_table e ON d.id = e.department_id AND e.status = 1
     WHERE d.status = 1
     GROUP BY d.id, d.name
     ORDER BY total DESC",
    ARRAY_A
);

// Gráfico de Leads por Status
$leads_by_status = $wpdb->get_results(
    "SELECT status, COUNT(*) as total
     FROM $leads_table
     GROUP BY status
     ORDER BY total DESC",
    ARRAY_A
);

// Gráfico de Presença (últimos 7 dias)
$attendance_by_day = array();
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $present = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT employee_id)
         FROM $time_entries_table
         WHERE DATE(punch_time) = %s AND punch_type = 'in'",
        $date
    ));
    $attendance_by_day[] = array(
        'date' => date('d/m', strtotime($date)),
        'count' => (int) $present
    );
}
?>

<div class="wrap sistur-container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--sistur-space-8);">
        <div>
            <h1 style="margin: 0; font-size: var(--sistur-text-3xl);"><?php _e('Dashboard SISTUR', 'sistur'); ?></h1>
            <p style="color: var(--sistur-gray-600); margin-top: var(--sistur-space-2);">
                <?php printf(__('Bem-vindo, %s', 'sistur'), wp_get_current_user()->display_name); ?>
            </p>
        </div>
        <div>
            <div style="display: flex; align-items: center; gap: var(--sistur-space-2);">
                <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo $is_open ? 'var(--sistur-success)' : 'var(--sistur-error)'; ?>;"></span>
                <span style="font-weight: var(--sistur-font-semibold);">
                    <?php echo $is_open ? __('Sistema Aberto', 'sistur') : __('Sistema Fechado', 'sistur'); ?>
                </span>
            </div>
            <div style="text-align: right; margin-top: var(--sistur-space-1); color: var(--sistur-gray-600); font-size: var(--sistur-text-sm);">
                <?php echo current_time('d/m/Y H:i:s'); ?>
            </div>
        </div>
    </div>

    <!-- Estatísticas Principais -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: var(--sistur-space-6); margin-bottom: var(--sistur-space-8);">

        <!-- Funcionários -->
        <div class="sistur-card">
            <div class="sistur-card-body sistur-stat-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div class="sistur-stat-label"><?php _e('Funcionários Ativos', 'sistur'); ?></div>
                        <div class="sistur-stat-value"><?php echo esc_html($total_employees); ?></div>
                        <div style="margin-top: var(--sistur-space-2);">
                            <span class="sistur-badge sistur-badge-info"><?php echo esc_html($total_departments); ?> departamentos</span>
                        </div>
                    </div>
                    <div style="font-size: 3rem; opacity: 0.2;">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                </div>
            </div>
            <div class="sistur-card-footer">
                <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                    <?php _e('Gerenciar', 'sistur'); ?> →
                </a>
            </div>
        </div>

        <!-- Ponto Hoje -->
        <div class="sistur-card">
            <div class="sistur-card-body sistur-stat-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div class="sistur-stat-label"><?php _e('Registros Hoje', 'sistur'); ?></div>
                        <div class="sistur-stat-value"><?php echo esc_html($clock_ins_today); ?></div>
                        <div style="margin-top: var(--sistur-space-2);">
                            <span class="sistur-badge sistur-badge-success">
                                <?php echo round(($total_employees > 0 ? ($clock_ins_today / $total_employees) * 100 : 0), 1); ?>% presença
                            </span>
                        </div>
                    </div>
                    <div style="font-size: 3rem; opacity: 0.2;">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                </div>
            </div>
            <div class="sistur-card-footer">
                <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                    <?php _e('Ver Registros', 'sistur'); ?> →
                </a>
            </div>
        </div>

        <!-- Leads -->
        <div class="sistur-card">
            <div class="sistur-card-body sistur-stat-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div class="sistur-stat-label"><?php _e('Total de Leads', 'sistur'); ?></div>
                        <div class="sistur-stat-value"><?php echo esc_html($total_leads); ?></div>
                        <div style="margin-top: var(--sistur-space-2);">
                            <?php if ($new_leads > 0): ?>
                                <span class="sistur-badge sistur-badge-warning"><?php echo esc_html($new_leads); ?> novos</span>
                            <?php else: ?>
                                <span class="sistur-badge sistur-badge-neutral">Todos contatados</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size: 3rem; opacity: 0.2;">
                        <span class="dashicons dashicons-email-alt"></span>
                    </div>
                </div>
            </div>
            <div class="sistur-card-footer">
                <a href="<?php echo admin_url('admin.php?page=sistur-leads'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                    <?php _e('Ver Leads', 'sistur'); ?> →
                </a>
            </div>
        </div>

        <!-- Restaurante -->
        <div class="sistur-card">
            <div class="sistur-card-body sistur-stat-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div class="sistur-stat-label"><?php _e('Restaurante', 'sistur'); ?></div>
                        <div class="sistur-stat-value" style="font-size: 1.5rem;">🍽️</div>
                        <div style="margin-top: var(--sistur-space-2);">
                            <span class="sistur-badge sistur-badge-info">Em breve</span>
                        </div>
                    </div>
                    <div style="font-size: 3rem; opacity: 0.2;">
                        <span class="dashicons dashicons-food"></span>
                    </div>
                </div>
            </div>
            <div class="sistur-card-footer">
                <a href="<?php echo admin_url('admin.php?page=sistur-restaurant'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                    <?php _e('Acessar', 'sistur'); ?> →
                </a>
            </div>
        </div>

        <!-- Gestão de Funcionários -->
        <div class="sistur-card" style="grid-column: span 2;">
            <div class="sistur-card-body sistur-stat-card">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="flex: 1;">
                        <div class="sistur-stat-label"><?php _e('Gestão de Funcionários', 'sistur'); ?></div>
                        <div style="display: flex; gap: 15px; margin-top: var(--sistur-space-3);">
                            <a href="<?php echo admin_url('admin.php?page=sistur-rh'); ?>" class="sistur-btn sistur-btn-primary sistur-btn-sm">
                                <span class="dashicons dashicons-heart" style="font-size: 16px; vertical-align: middle;"></span>
                                Abonos/Perdão
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=sistur-weekly-audit'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                                <span class="dashicons dashicons-chart-bar" style="font-size: 16px; vertical-align: middle;"></span>
                                Auditoria Semanal
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>" class="sistur-btn sistur-btn-secondary sistur-btn-sm">
                                <span class="dashicons dashicons-visibility" style="font-size: 16px; vertical-align: middle;"></span>
                                Ver Registros
                            </a>
                        </div>
                    </div>
                    <div style="font-size: 3rem; opacity: 0.2;">
                        <span class="dashicons dashicons-businessman"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráficos Interativos -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--sistur-space-6); margin-bottom: var(--sistur-space-8);">

        <!-- Gráfico de Leads (Últimos 7 dias) -->
        <div class="sistur-card">
            <div class="sistur-card-header">
                <h3 class="sistur-card-title"><?php _e('Leads - Últimos 7 Dias', 'sistur'); ?></h3>
            </div>
            <div class="sistur-card-body">
                <canvas id="leadsChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Ações Rápidas -->
        <div class="sistur-card">
            <div class="sistur-card-header">
                <h3 class="sistur-card-title"><?php _e('Ações Rápidas', 'sistur'); ?></h3>
            </div>
            <div class="sistur-card-body" style="display: flex; flex-direction: column; gap: var(--sistur-space-3);">
                <a href="<?php echo admin_url('admin.php?page=sistur-employees&action=add'); ?>" class="sistur-btn sistur-btn-primary">
                    <span class="dashicons dashicons-plus-alt" style="margin-right: var(--sistur-space-2);"></span>
                    <?php _e('Novo Funcionário', 'sistur'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>" class="sistur-btn sistur-btn-secondary">
                    <span class="dashicons dashicons-clock" style="margin-right: var(--sistur-space-2);"></span>
                    <?php _e('Registrar Ponto', 'sistur'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=sistur-leads'); ?>" class="sistur-btn sistur-btn-secondary">
                    <span class="dashicons dashicons-email-alt" style="margin-right: var(--sistur-space-2);"></span>
                    <?php _e('Ver Leads', 'sistur'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Mais Gráficos -->
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sistur-space-6); margin-bottom: var(--sistur-space-8);">

        <!-- Gráfico de Funcionários por Departamento -->
        <div class="sistur-card">
            <div class="sistur-card-header">
                <h3 class="sistur-card-title"><?php _e('Funcionários por Departamento', 'sistur'); ?></h3>
            </div>
            <div class="sistur-card-body">
                <canvas id="employeesChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

        <!-- Gráfico de Presença (Últimos 7 dias) -->
        <div class="sistur-card">
            <div class="sistur-card-header">
                <h3 class="sistur-card-title"><?php _e('Presença - Últimos 7 Dias', 'sistur'); ?></h3>
            </div>
            <div class="sistur-card-body">
                <canvas id="attendanceChart" style="max-height: 300px;"></canvas>
            </div>
        </div>

    </div>

    <!-- Links Rápidos -->
    <div class="sistur-card">
        <div class="sistur-card-header">
            <h3 class="sistur-card-title"><?php _e('Módulos do Sistema', 'sistur'); ?></h3>
        </div>
        <div class="sistur-card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sistur-space-4);">
                <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-groups" style="font-size: 2rem; color: var(--sistur-primary);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Funcionários', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-clock" style="font-size: 2rem; color: var(--sistur-success);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Ponto Eletrônico', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-payments'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-money-alt" style="font-size: 2rem; color: var(--sistur-warning);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Pagamentos', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-leads'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-email-alt" style="font-size: 2rem; color: var(--sistur-secondary);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Leads', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-restaurant'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-food" style="font-size: 2rem; color: var(--sistur-warning);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Restaurante', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-rh'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-businessman" style="font-size: 2rem; color: var(--sistur-success);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Gestão RH', 'sistur'); ?>
                    </div>
                </a>

                <a href="<?php echo admin_url('admin.php?page=sistur-settings'); ?>"
                   style="padding: var(--sistur-space-4); text-align: center; background: var(--sistur-gray-50); border-radius: var(--sistur-radius-lg); text-decoration: none; transition: all var(--sistur-transition-base); display: block;"
                   onmouseover="this.style.background='var(--sistur-gray-100)'"
                   onmouseout="this.style.background='var(--sistur-gray-50)'">
                    <span class="dashicons dashicons-admin-settings" style="font-size: 2rem; color: var(--sistur-gray-600);"></span>
                    <div style="margin-top: var(--sistur-space-2); font-weight: var(--sistur-font-medium); color: var(--sistur-gray-900);">
                        <?php _e('Configurações', 'sistur'); ?>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Dashboard responsivo */
@media (max-width: 1024px) {
    .wrap.sistur-container > div[style*="grid-template-columns: 2fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 768px) {
    .wrap.sistur-container > div[style*="display: flex"] {
        flex-direction: column !important;
        align-items: flex-start !important;
    }
}
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Configuração de cores
    const colors = {
        primary: getComputedStyle(document.documentElement).getPropertyValue('--sistur-primary').trim() || '#2271b1',
        success: getComputedStyle(document.documentElement).getPropertyValue('--sistur-success').trim() || '#00a32a',
        warning: getComputedStyle(document.documentElement).getPropertyValue('--sistur-warning').trim() || '#f0b849',
        error: getComputedStyle(document.documentElement).getPropertyValue('--sistur-error').trim() || '#d63638',
        secondary: getComputedStyle(document.documentElement).getPropertyValue('--sistur-secondary').trim() || '#646970'
    };

    // Configuração padrão de gráficos
    Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';
    Chart.defaults.color = '#646970';

    // 1. Gráfico de Leads (Últimos 7 dias)
    const leadsData = <?php echo json_encode($leads_by_day); ?>;
    const leadsCtx = document.getElementById('leadsChart');
    if (leadsCtx) {
        new Chart(leadsCtx, {
            type: 'line',
            data: {
                labels: leadsData.map(d => d.date),
                datasets: [{
                    label: 'Leads',
                    data: leadsData.map(d => d.count),
                    borderColor: colors.primary,
                    backgroundColor: colors.primary + '20',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14 },
                        bodyFont: { size: 13 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: '#f0f0f1'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // 2. Gráfico de Funcionários por Departamento
    const employeesData = <?php echo json_encode($employees_by_dept); ?>;
    const employeesCtx = document.getElementById('employeesChart');
    if (employeesCtx && employeesData.length > 0) {
        new Chart(employeesCtx, {
            type: 'doughnut',
            data: {
                labels: employeesData.map(d => d.department),
                datasets: [{
                    data: employeesData.map(d => d.total),
                    backgroundColor: [
                        colors.primary,
                        colors.success,
                        colors.warning,
                        colors.error,
                        colors.secondary,
                        '#8c8f94',
                        '#a7aaad'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // 3. Gráfico de Presença (Últimos 7 dias)
    const attendanceData = <?php echo json_encode($attendance_by_day); ?>;
    const attendanceCtx = document.getElementById('attendanceChart');
    if (attendanceCtx) {
        new Chart(attendanceCtx, {
            type: 'bar',
            data: {
                labels: attendanceData.map(d => d.date),
                datasets: [{
                    label: 'Funcionários Presentes',
                    data: attendanceData.map(d => d.count),
                    backgroundColor: colors.success + '80',
                    borderColor: colors.success,
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 14 },
                        bodyFont: { size: 13 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: '#f0f0f1'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
});
</script>
