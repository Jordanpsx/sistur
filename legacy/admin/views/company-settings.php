<?php
/**
 * View de configurações da empresa
 *
 * @package SISTUR
 */

// Prevenir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Buscar configurações atuais
global $wpdb;
$table_settings = $wpdb->prefix . 'sistur_settings';

$company_name = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_name'
));

$company_cnpj = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_cnpj'
));

$company_address = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_address'
));

$company_default_department = $wpdb->get_var($wpdb->prepare(
    "SELECT setting_value FROM $table_settings WHERE setting_key = %s",
    'company_default_department'
));

// Processar formulário se submetido
if (isset($_POST['save_company_settings']) && check_admin_referer('sistur_company_settings', 'sistur_company_settings_nonce')) {
    $wpdb->update(
        $table_settings,
        array('setting_value' => sanitize_text_field($_POST['company_name'])),
        array('setting_key' => 'company_name'),
        array('%s'),
        array('%s')
    );

    $wpdb->update(
        $table_settings,
        array('setting_value' => sanitize_text_field($_POST['company_cnpj'])),
        array('setting_key' => 'company_cnpj'),
        array('%s'),
        array('%s')
    );

    $wpdb->update(
        $table_settings,
        array('setting_value' => sanitize_textarea_field($_POST['company_address'])),
        array('setting_key' => 'company_address'),
        array('%s'),
        array('%s')
    );

    $wpdb->update(
        $table_settings,
        array('setting_value' => sanitize_text_field($_POST['company_default_department'])),
        array('setting_key' => 'company_default_department'),
        array('%s'),
        array('%s')
    );

    // Recarregar valores
    $company_name = sanitize_text_field($_POST['company_name']);
    $company_cnpj = sanitize_text_field($_POST['company_cnpj']);
    $company_address = sanitize_textarea_field($_POST['company_address']);
    $company_default_department = sanitize_text_field($_POST['company_default_department']);

    echo '<div class="notice notice-success is-dismissible"><p>Configurações salvas com sucesso!</p></div>';
}
?>

<div class="wrap sistur-wrap">
    <h1 class="wp-heading-inline">
        <i class="dashicons dashicons-building"></i>
        Configurações da Empresa
    </h1>
    <hr class="wp-header-end">

    <div class="sistur-card">
        <div class="card-header">
            <h2>Dados do Empregador</h2>
            <p class="description">
                Estas informações serão utilizadas no cabeçalho da Folha de Ponto e outros relatórios do sistema.
            </p>
        </div>

        <div class="card-body">
            <form method="post" action="">
                <?php wp_nonce_field('sistur_company_settings', 'sistur_company_settings_nonce'); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="company_name">Razão Social <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="company_name"
                                       name="company_name"
                                       value="<?php echo esc_attr($company_name); ?>"
                                       class="regular-text"
                                       required>
                                <p class="description">Nome completo da empresa (Ex: Vinhedo Girassol Vinhos e Turismo LTDA)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="company_cnpj">CNPJ <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="company_cnpj"
                                       name="company_cnpj"
                                       value="<?php echo esc_attr($company_cnpj); ?>"
                                       class="regular-text"
                                       maxlength="18"
                                       placeholder="00.000.000/0000-00"
                                       required>
                                <p class="description">CNPJ da empresa (Ex: 49.608.569/0001-23)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="company_address">Endereço Completo <span class="required">*</span></label>
                            </th>
                            <td>
                                <textarea id="company_address"
                                          name="company_address"
                                          rows="3"
                                          class="large-text"
                                          required><?php echo esc_textarea($company_address); ?></textarea>
                                <p class="description">Endereço completo da empresa (Ex: Rodovia BR 070 - Cocalzinho de Goiás - GO)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="company_default_department">Departamento Padrão</label>
                            </th>
                            <td>
                                <input type="text"
                                       id="company_default_department"
                                       name="company_default_department"
                                       value="<?php echo esc_attr($company_default_department); ?>"
                                       class="regular-text">
                                <p class="description">Departamento padrão para impressão nos relatórios (opcional)</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="save_company_settings" class="button button-primary">
                        <i class="dashicons dashicons-saved"></i>
                        Salvar Configurações
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div class="sistur-card" style="margin-top: 20px;">
        <div class="card-header">
            <h2>Dados de Teste</h2>
            <p class="description">
                Você pode usar estes dados como exemplo para testar o sistema:
            </p>
        </div>
        <div class="card-body">
            <ul style="list-style: disc; margin-left: 20px;">
                <li><strong>Razão Social:</strong> Vinhedo Girassol Vinhos e Turismo LTDA</li>
                <li><strong>CNPJ:</strong> 49.608.569/0001-23</li>
                <li><strong>Endereço:</strong> Rodovia BR 070 - Cocalzinho de Goiás - GO</li>
                <li><strong>Departamento:</strong> Recursos Humanos</li>
            </ul>
        </div>
    </div>
</div>

<style>
.sistur-wrap .required {
    color: #d63638;
}

.sistur-wrap .form-table th {
    width: 200px;
}

.sistur-wrap .button .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    margin-right: 5px;
    vertical-align: middle;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Máscara para CNPJ
    $('#company_cnpj').on('input', function() {
        var value = $(this).val().replace(/\D/g, '');
        var formatted = '';

        if (value.length > 0) {
            formatted = value.substring(0, 2);
        }
        if (value.length >= 3) {
            formatted += '.' + value.substring(2, 5);
        }
        if (value.length >= 6) {
            formatted += '.' + value.substring(5, 8);
        }
        if (value.length >= 9) {
            formatted += '/' + value.substring(8, 12);
        }
        if (value.length >= 13) {
            formatted += '-' + value.substring(12, 14);
        }

        $(this).val(formatted);
    });
});
</script>
