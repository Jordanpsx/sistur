<?php
/**
 * Aviso Admin: Funcionários sem Escala Vinculada
 *
 * Exibe alerta no admin do WordPress quando existem funcionários
 * sem escala de trabalho vinculada
 *
 * @package SISTUR
 * @since 2.0.0
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar e exibir aviso sobre funcionários sem escala
 */
add_action('admin_notices', function() {
    // Apenas para admins
    if (!current_user_can('manage_options')) {
        return;
    }

    // Verificar se há funcionários sem escala
    global $wpdb;
    $table_employees = $wpdb->prefix . 'sistur_employees';
    $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';

    $count_without_schedule = $wpdb->get_var("
        SELECT COUNT(*)
        FROM $table_employees e
        LEFT JOIN $table_schedules s ON e.id = s.employee_id AND s.is_active = 1
        WHERE s.id IS NULL AND e.status = 1
    ");

    if ($count_without_schedule > 0) {
        $migration_url = admin_url('admin.php?page=sistur-migrate-shifts');
        ?>
        <div class="notice notice-error is-dismissible">
            <h3>⚠️ SISTUR: Ação Necessária</h3>
            <p><strong><?php echo $count_without_schedule; ?> funcionário(s) sem escala de trabalho vinculada!</strong></p>
            <p>A partir desta versão, <strong>todos os funcionários devem ter uma escala vinculada</strong> para o cálculo correto de horas trabalhadas, horas extras e banco de horas.</p>
            <p>
                <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">
                    🔧 Executar Migração Automática
                </a>
                <a href="<?php echo admin_url('admin.php?page=sistur-funcionarios'); ?>" class="button">
                    👥 Gerenciar Funcionários
                </a>
            </p>
            <p style="font-size: 12px; color: #666;">
                ℹ️ A migração automática vinculará os funcionários a escalas baseado na carga horária cadastrada.
            </p>
        </div>
        <?php
    }
});

/**
 * Adicionar menu de migração no admin (sempre disponível)
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sistur',
        'Migração de Escalas',
        'Migração de Escalas',
        'manage_options',
        'sistur-migrate-shifts',
        'sistur_migration_admin_page'
    );
}, 20);
