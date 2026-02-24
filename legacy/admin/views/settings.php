<?php
/**
 * Página de Configurações
 *
 * @package SISTUR
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

// Salvar configurações
if (isset($_POST['sistur_save_settings']) && check_admin_referer('sistur_settings_nonce')) {
    $business_hours = isset($_POST['business_hours']) ? $_POST['business_hours'] : array();
    update_option('sistur_business_hours', $business_hours);

    $allowed_modes = array('MICRO', 'MACRO');
    $sales_mode = isset($_POST['sistur_sales_mode']) && in_array($_POST['sistur_sales_mode'], $allowed_modes)
        ? $_POST['sistur_sales_mode']
        : 'MICRO';
    update_option('sistur_sales_mode', $sales_mode);

    echo '<div class="notice notice-success"><p>' . __('Configurações salvas com sucesso!', 'sistur') . '</p></div>';
}

$business_hours = get_option('sistur_business_hours', array());
$current_sales_mode = get_option('sistur_sales_mode', 'MICRO');
$days = array(
    'monday' => __('Segunda-feira', 'sistur'),
    'tuesday' => __('Terça-feira', 'sistur'),
    'wednesday' => __('Quarta-feira', 'sistur'),
    'thursday' => __('Quinta-feira', 'sistur'),
    'friday' => __('Sexta-feira', 'sistur'),
    'saturday' => __('Sábado', 'sistur'),
    'sunday' => __('Domingo', 'sistur')
);
?>

<div class="wrap">
    <h1><?php _e('Configurações do SISTUR', 'sistur'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('sistur_settings_nonce'); ?>

        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label><?php _e('Horários de Funcionamento', 'sistur'); ?></label>
                    </th>
                    <td>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Dia', 'sistur'); ?></th>
                                    <th><?php _e('Abertura', 'sistur'); ?></th>
                                    <th><?php _e('Fechamento', 'sistur'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day_key => $day_label) : ?>
                                    <?php
                                    $open = isset($business_hours[$day_key]['open']) ? $business_hours[$day_key]['open'] : '08:00';
                                    $close = isset($business_hours[$day_key]['close']) ? $business_hours[$day_key]['close'] : '18:00';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($day_label); ?></td>
                                        <td>
                                            <input type="time" name="business_hours[<?php echo $day_key; ?>][open]" value="<?php echo esc_attr($open); ?>" />
                                        </td>
                                        <td>
                                            <input type="time" name="business_hours[<?php echo $day_key; ?>][close]" value="<?php echo esc_attr($close); ?>" />
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description"><?php _e('Defina os horários de funcionamento para cada dia da semana.', 'sistur'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sistur_sales_mode"><?php _e('Modo de Registro de Vendas', 'sistur'); ?></label>
                    </th>
                    <td>
                        <select id="sistur_sales_mode" name="sistur_sales_mode">
                            <option value="MICRO" <?php selected($current_sales_mode, 'MICRO'); ?>>
                                <?php _e('Micro — Registro por Produto (padrão)', 'sistur'); ?>
                            </option>
                            <option value="MACRO" <?php selected($current_sales_mode, 'MACRO'); ?>>
                                <?php _e('Macro — Faturamento Diário (Fechar Caixa)', 'sistur'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Em modo <strong>Macro</strong>, a Receita no DRE vem de lançamentos diários via "Fechar Caixa". O CMV continua sendo calculado pelo estoque (Abertura + Compras − Fechamento).', 'sistur'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Salvar Configurações', 'sistur'), 'primary', 'sistur_save_settings'); ?>
    </form>

    <hr />

    <h2><?php _e('Informações do Sistema', 'sistur'); ?></h2>
    <table class="form-table">
        <tbody>
            <tr>
                <th><?php _e('Versão do Plugin:', 'sistur'); ?></th>
                <td><?php echo esc_html(SISTUR_VERSION); ?></td>
            </tr>
            <tr>
                <th><?php _e('Versão do Banco de Dados:', 'sistur'); ?></th>
                <td><?php echo esc_html(get_option('sistur_db_version', '1.0')); ?></td>
            </tr>
            <tr>
                <th><?php _e('WordPress Version:', 'sistur'); ?></th>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
            </tr>
            <tr>
                <th><?php _e('PHP Version:', 'sistur'); ?></th>
                <td><?php echo esc_html(phpversion()); ?></td>
            </tr>
        </tbody>
    </table>
</div>
