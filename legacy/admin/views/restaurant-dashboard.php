<?php
/**
 * Dashboard de Restaurante
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}
?>

<div class="wrap">
    <h1><?php _e('Dashboard do Restaurante', 'sistur'); ?></h1>

    <div style="background: white; padding: 20px; margin-top: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2><?php _e('Gestão do Restaurante', 'sistur'); ?></h2>
        <p><?php _e('Centralize o gerenciamento do seu restaurante com as ferramentas do SISTUR.', 'sistur'); ?></p>

        <div style="margin-top: 20px;">
            <a href="<?php echo admin_url('admin.php?page=sistur-inventory'); ?>" class="button button-primary button-large">
                <span class="dashicons dashicons-products"></span>
                <?php _e('Gestão de Estoque', 'sistur'); ?>
            </a>
        </div>
    </div>
</div>
