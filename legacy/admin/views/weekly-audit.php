<?php
/**
 * Página de Auditoria Semanal para Escalas Flexíveis
 * 
 * @package SISTUR
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;

$employees_table = $wpdb->prefix . 'sistur_employees';
$schedules_table = $wpdb->prefix . 'sistur_employee_schedules';
$patterns_table = $wpdb->prefix . 'sistur_shift_patterns';
$time_days_table = $wpdb->prefix . 'sistur_time_days';

// Obter semana de referência
$week_param = isset($_GET['week']) ? sanitize_text_field($_GET['week']) : date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week', strtotime($week_param)));
$week_end = date('Y-m-d', strtotime('sunday this week', strtotime($week_param)));

// Navegação entre semanas
$prev_week = date('Y-m-d', strtotime('-1 week', strtotime($week_start)));
$next_week = date('Y-m-d', strtotime('+1 week', strtotime($week_start)));

// Buscar funcionários com escalas flexíveis ativas
$employees_flexible = $wpdb->get_results("
    SELECT 
        e.id,
        e.name,
        e.email,
        e.position,
        p.name AS pattern_name,
        p.weekly_hours_minutes AS expected_weekly_minutes,
        s.start_date AS schedule_start_date
    FROM $employees_table e
    INNER JOIN $schedules_table s ON e.id = s.employee_id AND s.is_active = 1
    INNER JOIN $patterns_table p ON s.shift_pattern_id = p.id
    WHERE e.status = 1
    AND p.pattern_type = 'flexible_hours'
    AND s.start_date <= '$week_end'
    AND (s.end_date IS NULL OR s.end_date >= '$week_start')
    ORDER BY e.name ASC
");

// Para cada funcionário, buscar horas trabalhadas na semana
foreach ($employees_flexible as &$emp) {
    $worked = $wpdb->get_var($wpdb->prepare("
        SELECT SUM(minutos_trabalhados)
        FROM $time_days_table
        WHERE employee_id = %d
        AND shift_date BETWEEN %s AND %s
        AND status = 'present'
    ", $emp->id, $week_start, $week_end));
    
    $emp->worked_minutes = intval($worked);
    $emp->balance_minutes = $emp->worked_minutes - $emp->expected_weekly_minutes;
}
unset($emp);

// Formatar data para exibição
function format_week_range($start, $end) {
    $start_day = date('d', strtotime($start));
    $end_day = date('d', strtotime($end));
    $month = date('M', strtotime($end));
    $year = date('Y', strtotime($end));
    return "$start_day a $end_day de $month/$year";
}

function format_minutes_audit($minutes) {
    $hours = floor(abs($minutes) / 60);
    $mins = abs($minutes) % 60;
    $sign = $minutes >= 0 ? '+' : '-';
    return $sign . $hours . 'h' . str_pad($mins, 2, '0', STR_PAD_LEFT);
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-chart-bar" style="font-size: 28px; vertical-align: middle;"></span>
        <?php _e('Auditoria Semanal - Escalas Flexíveis', 'sistur'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <!-- Navegação da Semana -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px;">
        <a href="<?php echo admin_url("admin.php?page=sistur-weekly-audit&week=$prev_week"); ?>" class="button">
            <span class="dashicons dashicons-arrow-left-alt2" style="vertical-align: middle;"></span>
            Semana Anterior
        </a>
        
        <h2 style="margin: 0; font-size: 20px; font-weight: 600;">
            📅 Semana: <?php echo format_week_range($week_start, $week_end); ?>
        </h2>
        
        <a href="<?php echo admin_url("admin.php?page=sistur-weekly-audit&week=$next_week"); ?>" class="button">
            Próxima Semana
            <span class="dashicons dashicons-arrow-right-alt2" style="vertical-align: middle;"></span>
        </a>
    </div>

    <!-- Explicação -->
    <div style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
        <strong>ℹ️ O que é esta página?</strong><br>
        Funcionários com <strong>escalas de horas flexíveis</strong> não têm expectativa diária fixa. 
        Esta auditoria mostra se eles cumpriram a carga horária <strong>semanal</strong> esperada.
    </div>

    <?php if (empty($employees_flexible)): ?>
        <div style="background: #fff; padding: 40px; text-align: center; border-radius: 8px; border: 1px solid #dcdcde;">
            <span class="dashicons dashicons-info" style="font-size: 48px; color: #646970;"></span>
            <h3>Nenhum funcionário com escala flexível</h3>
            <p>Não há funcionários com escalas do tipo "Horas Flexíveis" ativas nesta semana.</p>
            <a href="<?php echo admin_url('admin.php?page=sistur-shift-patterns'); ?>" class="button button-primary">
                Gerenciar Escalas
            </a>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped" style="background: #fff;">
            <thead>
                <tr>
                    <th style="width: 25%;">Funcionário</th>
                    <th style="width: 15%;">Escala</th>
                    <th style="width: 15%; text-align: center;">Horas Esperadas</th>
                    <th style="width: 15%; text-align: center;">Horas Trabalhadas</th>
                    <th style="width: 15%; text-align: center;">Saldo Semanal</th>
                    <th style="width: 15%; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees_flexible as $emp): 
                    $expected_hours = $emp->expected_weekly_minutes / 60;
                    $worked_hours = $emp->worked_minutes / 60;
                    $balance = $emp->balance_minutes;
                    
                    // Determinar status
                    if ($balance >= 0) {
                        $status_class = 'color: #46b450; background: #edfaef;';
                        $status_text = '✅ OK';
                    } elseif ($balance >= -60) { // Até 1h de débito
                        $status_class = 'color: #dba617; background: #fff3cd;';
                        $status_text = '⚠️ Atenção';
                    } else {
                        $status_class = 'color: #dc3545; background: #fce4e4;';
                        $status_text = '❌ Déficit';
                    }
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($emp->name); ?></strong>
                            <br><small style="color: #666;"><?php echo esc_html($emp->position); ?></small>
                        </td>
                        <td><?php echo esc_html($emp->pattern_name); ?></td>
                        <td style="text-align: center; font-weight: 600;">
                            <?php echo number_format($expected_hours, 1); ?>h
                        </td>
                        <td style="text-align: center; font-weight: 600;">
                            <?php echo number_format($worked_hours, 1); ?>h
                        </td>
                        <td style="text-align: center; font-weight: 700; <?php echo $balance >= 0 ? 'color: #46b450;' : 'color: #dc3545;'; ?>">
                            <?php echo format_minutes_audit($balance); ?>
                        </td>
                        <td style="text-align: center;">
                            <span style="padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Resumo -->
        <?php
        $total_expected = array_sum(array_column($employees_flexible, 'expected_weekly_minutes'));
        $total_worked = array_sum(array_column($employees_flexible, 'worked_minutes'));
        $total_balance = array_sum(array_column($employees_flexible, 'balance_minutes'));
        ?>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 20px;">
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #dcdcde; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #2271b1;">
                    <?php echo count($employees_flexible); ?>
                </div>
                <div style="color: #666;">Funcionários Flexíveis</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #dcdcde; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #1d2327;">
                    <?php echo number_format($total_expected / 60, 0); ?>h
                </div>
                <div style="color: #666;">Total Esperado</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #dcdcde; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; color: #1d2327;">
                    <?php echo number_format($total_worked / 60, 0); ?>h
                </div>
                <div style="color: #666;">Total Trabalhado</div>
            </div>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #dcdcde; text-align: center;">
                <div style="font-size: 32px; font-weight: 700; <?php echo $total_balance >= 0 ? 'color: #46b450;' : 'color: #dc3545;'; ?>">
                    <?php echo format_minutes_audit($total_balance); ?>
                </div>
                <div style="color: #666;">Saldo Total</div>
            </div>
        </div>
    <?php endif; ?>
</div>
