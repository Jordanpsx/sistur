<?php
/**
 * Dashboard de RH
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;
$employees_table = $wpdb->prefix . 'sistur_employees';
$departments_table = $wpdb->prefix . 'sistur_departments';
$settings_table = $wpdb->prefix . 'sistur_settings';

// Salvar configuração de tolerância
if (isset($_POST['sistur_save_tolerance']) && check_admin_referer('sistur_tolerance_nonce')) {
    $tolerance_delay = isset($_POST['tolerance_minutes_delay']) ? intval($_POST['tolerance_minutes_delay']) : 0;

    $wpdb->update(
        $settings_table,
        array('setting_value' => strval($tolerance_delay)),
        array('setting_key' => 'tolerance_minutes_delay'),
        array('%s'),
        array('%s')
    );

    echo '<div class="notice notice-success is-dismissible"><p>' . __('Tolerância de atraso atualizada com sucesso!', 'sistur') . '</p></div>';
}

$total_employees = $wpdb->get_var("SELECT COUNT(*) FROM $employees_table WHERE status = 1");
$total_departments = $wpdb->get_var("SELECT COUNT(*) FROM $departments_table WHERE status = 1");
$recent_employees = $wpdb->get_results(
    "SELECT * FROM $employees_table ORDER BY created_at DESC LIMIT 5",
    ARRAY_A
);

// Buscar configuração de tolerância
$tolerance_delay = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'tolerance_minutes_delay')) ?: 0;
?>

<div class="wrap">
    <h1><?php _e('Dashboard de RH', 'sistur'); ?></h1>

    <!-- Configuração de Tolerância de Atraso -->
    <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <h2 style="margin-top: 0; color: #856404;">⚙️ <?php _e('Tolerância de Atraso', 'sistur'); ?></h2>
        <p><?php _e('Configure quantos minutos de atraso (saldo negativo) serão perdoados automaticamente.', 'sistur'); ?></p>

        <form method="post" action="" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <?php wp_nonce_field('sistur_tolerance_nonce'); ?>

            <label for="tolerance_minutes_delay" style="font-weight: bold;">
                <?php _e('Minutos de tolerância:', 'sistur'); ?>
            </label>

            <input type="number"
                   id="tolerance_minutes_delay"
                   name="tolerance_minutes_delay"
                   value="<?php echo esc_attr($tolerance_delay); ?>"
                   min="0"
                   max="120"
                   step="1"
                   class="small-text"
                   style="width: 80px;" />

            <span style="color: #666;">
                <?php _e('minutos', 'sistur'); ?>
            </span>

            <?php submit_button(__('Atualizar Tolerância', 'sistur'), 'primary', 'sistur_save_tolerance', false); ?>

            <p style="width: 100%; margin: 10px 0 0 0; color: #666; font-size: 13px;">
                <strong><?php _e('Exemplo:', 'sistur'); ?></strong>
                <?php printf(
                    __('Se configurar %d minutos, atrasos de até %d minutos não serão descontados do saldo do funcionário.', 'sistur'),
                    intval($tolerance_delay),
                    intval($tolerance_delay)
                ); ?>
            </p>
        </form>
    </div>

    <div class="sistur-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
        <div class="sistur-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3><?php _e('Funcionários Ativos', 'sistur'); ?></h3>
            <p style="font-size: 36px; font-weight: bold; color: #2271b1; margin: 10px 0;"><?php echo esc_html($total_employees); ?></p>
            <a href="<?php echo admin_url('admin.php?page=sistur-employees'); ?>" class="button button-primary">
                <?php _e('Ver Todos', 'sistur'); ?>
            </a>
        </div>

        <div class="sistur-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3><?php _e('Departamentos', 'sistur'); ?></h3>
            <p style="font-size: 36px; font-weight: bold; color: #2271b1; margin: 10px 0;"><?php echo esc_html($total_departments); ?></p>
            <a href="<?php echo admin_url('admin.php?page=sistur-departments'); ?>" class="button button-primary">
                <?php _e('Gerenciar', 'sistur'); ?>
            </a>
        </div>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 20px;">
        <h2><?php _e('Funcionários Recentes', 'sistur'); ?></h2>
        <?php if (!empty($recent_employees)) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Nome', 'sistur'); ?></th>
                        <th><?php _e('Email', 'sistur'); ?></th>
                        <th><?php _e('Cargo', 'sistur'); ?></th>
                        <th><?php _e('Data de Cadastro', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_employees as $employee) : ?>
                        <tr>
                            <td><?php echo esc_html($employee['name']); ?></td>
                            <td><?php echo esc_html($employee['email']); ?></td>
                            <td><?php echo esc_html($employee['position']); ?></td>
                            <td><?php echo esc_html(date('d/m/Y', strtotime($employee['created_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php _e('Nenhum funcionário cadastrado ainda.', 'sistur'); ?></p>
        <?php endif; ?>
    </div>

    <div style="margin-top: 20px;">
        <a href="<?php echo admin_url('admin.php?page=sistur-time-tracking'); ?>" class="button button-secondary">
            <?php _e('Registro de Ponto', 'sistur'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=sistur-payments'); ?>" class="button button-secondary">
            <?php _e('Pagamentos', 'sistur'); ?>
        </a>
    </div>

    <!-- Ações Rápidas do Banco de Horas -->
    <div style="background: #e7f6e7; border-left: 4px solid #46b450; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <h2 style="margin-top: 0; color: #1d6b1d;">💼 Ações Rápidas de Banco de Horas</h2>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
            <button type="button" id="btn-perdoar-falta" class="button" style="background: #46b450; color: white; border-color: #3c9c3f;">
                <span class="dashicons dashicons-heart" style="vertical-align: middle;"></span>
                Perdoar/Abonar Falta
            </button>
            <a href="<?php echo admin_url('admin.php?page=sistur-weekly-audit'); ?>" class="button" style="background: #2271b1; color: white; border-color: #135e96;">
                <span class="dashicons dashicons-chart-bar" style="vertical-align: middle;"></span>
                Auditoria Semanal
            </a>
            <a href="<?php echo admin_url('admin.php?page=sistur-holidays'); ?>" class="button">
                <span class="dashicons dashicons-calendar-alt" style="vertical-align: middle;"></span>
                Gerenciar Feriados
            </a>
        </div>
    </div>
</div>

<?php
// Incluir modais do banco de horas
include SISTUR_PLUGIN_DIR . 'admin/views/timebank/modals.php';
?>
