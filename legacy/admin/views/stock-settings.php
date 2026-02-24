<?php
/**
 * Página de Configurações de Estoque
 * 
 * Interface para gerenciar unidades de medida e locais de armazenamento.
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}
?>

<div class="wrap sistur-stock-settings">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-admin-settings" style="font-size: 28px; margin-right: 8px;"></span>
        <?php _e('Configurações de Estoque', 'sistur'); ?>
    </h1>
    <hr class="wp-header-end">

    <!-- Tabs Navigation -->
    <nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
        <a href="#units" class="nav-tab nav-tab-active" data-tab="units">
            <span class="dashicons dashicons-chart-area"
                style="font-family: dashicons; vertical-align: middle; margin-right: 4px;"></span>
            <?php _e('Unidades de Medida', 'sistur'); ?>
        </a>
        <a href="#locations" class="nav-tab" data-tab="locations">
            <span class="dashicons dashicons-location"
                style="font-family: dashicons; vertical-align: middle; margin-right: 4px;"></span>
            <?php _e('Locais de Armazenamento', 'sistur'); ?>
        </a>
    </nav>

    <!-- Tab Content: Unidades -->
    <div id="tab-units" class="sistur-tab-content active" style="display: block;">
        <div class="sistur-card">
            <div class="card-header">
                <h2><?php _e('Unidades de Medida', 'sistur'); ?></h2>
                <p class="description">
                    <?php _e('Edite as unidades diretamente na tabela clicando nos campos.', 'sistur'); ?>
                </p>
            </div>
            <div class="card-body">
                <table class="wp-list-table widefat fixed striped" id="units-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th><?php _e('Nome', 'sistur'); ?></th>
                            <th style="width: 100px;"><?php _e('Símbolo', 'sistur'); ?></th>
                            <th style="width: 120px;"><?php _e('Tipo', 'sistur'); ?></th>
                            <th style="width: 80px;"><?php _e('Sistema', 'sistur'); ?></th>
                            <th style="width: 80px;"><?php _e('Ações', 'sistur'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="units-tbody">
                        <tr class="loading-row">
                            <td colspan="6" style="text-align: center; padding: 30px;">
                                <span class="spinner is-active" style="float: none;"></span>
                                <?php _e('Carregando unidades...', 'sistur'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="card-actions">
                    <button type="button" class="button button-primary" id="btn-add-unit">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top;"></span>
                        <?php _e('Nova Unidade', 'sistur'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Content: Locais -->
    <div id="tab-locations" class="sistur-tab-content" style="display: none;">
        <div class="sistur-card">
            <div class="card-header">
                <h2><?php _e('Locais de Armazenamento', 'sistur'); ?></h2>
                <p class="description">
                    <?php _e('Gerencie os locais físicos onde os produtos são armazenados.', 'sistur'); ?>
                </p>
            </div>
            <div class="card-body">
                <table class="wp-list-table widefat fixed striped" id="locations-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th><?php _e('Nome', 'sistur'); ?></th>
                            <th style="width: 120px;"><?php _e('Código', 'sistur'); ?></th>
                            <th style="width: 120px;"><?php _e('Tipo', 'sistur'); ?></th>
                            <th style="width: 80px;"><?php _e('Ações', 'sistur'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="locations-tbody">
                        <tr class="loading-row">
                            <td colspan="5" style="text-align: center; padding: 30px;">
                                <span class="spinner is-active" style="float: none;"></span>
                                <?php _e('Carregando locais...', 'sistur'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="card-actions">
                    <button type="button" class="button button-primary" id="btn-add-location">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-top;"></span>
                        <?php _e('Novo Local', 'sistur'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nova Unidade -->
<div id="modal-unit" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 500px;">
        <div class="sistur-modal-header">
            <h2 id="modal-unit-title"><?php _e('Nova Unidade', 'sistur'); ?></h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <div class="sistur-modal-body">
            <form id="form-unit">
                <input type="hidden" id="unit-id" name="id" value="">
                <table class="form-table">
                    <tr>
                        <th><label for="unit-name"><?php _e('Nome *', 'sistur'); ?></label></th>
                        <td><input type="text" id="unit-name" name="name" class="regular-text" required
                                placeholder="Ex: Quilograma"></td>
                    </tr>
                    <tr>
                        <th><label for="unit-symbol"><?php _e('Símbolo *', 'sistur'); ?></label></th>
                        <td><input type="text" id="unit-symbol" name="symbol" class="small-text" required
                                placeholder="Ex: kg"></td>
                    </tr>
                    <tr>
                        <th><label for="unit-type"><?php _e('Tipo', 'sistur'); ?></label></th>
                        <td>
                            <select id="unit-type" name="type">
                                <option value="unitary"><?php _e('Unitário (ex: Caixa, Pacote)', 'sistur'); ?></option>
                                <option value="dimensional"><?php _e('Dimensional (ex: Kg, Litro)', 'sistur'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary"
                id="btn-save-unit"><?php _e('Salvar', 'sistur'); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Novo Local -->
<div id="modal-location" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 550px;">
        <div class="sistur-modal-header">
            <h2 id="modal-location-title"><?php _e('Novo Local', 'sistur'); ?></h2>
            <span class="sistur-modal-close">&times;</span>
        </div>
        <div class="sistur-modal-body">
            <form id="form-location">
                <input type="hidden" id="location-id" name="id" value="">
                <table class="form-table">
                    <tr>
                        <th><label for="location-name"><?php _e('Nome *', 'sistur'); ?></label></th>
                        <td><input type="text" id="location-name" name="name" class="regular-text" required
                                placeholder="Ex: Dispensa Principal"></td>
                    </tr>
                    <tr>
                        <th><label for="location-code"><?php _e('Código', 'sistur'); ?></label></th>
                        <td>
                            <input type="text" id="location-code" name="code" class="regular-text"
                                placeholder="Gerado automaticamente">
                            <p class="description"><?php _e('Deixe em branco para gerar automaticamente.', 'sistur'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location-type"><?php _e('Tipo', 'sistur'); ?></label></th>
                        <td>
                            <select id="location-type" name="location_type">
                                <option value="warehouse"><?php _e('🏪 Almoxarifado/Depósito', 'sistur'); ?></option>
                                <option value="refrigerator"><?php _e('❄️ Refrigerador', 'sistur'); ?></option>
                                <option value="freezer"><?php _e('🧊 Freezer', 'sistur'); ?></option>
                                <option value="shelf"><?php _e('📦 Prateleira', 'sistur'); ?></option>
                                <option value="other"><?php _e('📍 Outro', 'sistur'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location-description"><?php _e('Descrição', 'sistur'); ?></label></th>
                        <td><textarea id="location-description" name="description" rows="2" class="large-text"
                                placeholder="Descrição opcional..."></textarea></td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary"
                id="btn-save-location"><?php _e('Salvar', 'sistur'); ?></button>
        </div>
    </div>
</div>

<style>
    /* Tabs */
    .sistur-stock-settings .nav-tab-wrapper {
        margin-top: 0;
        margin-bottom: 20px;
        background: transparent;
        border-bottom: 1px solid #c3c4c7;
    }

    .sistur-stock-settings .nav-tab {
        margin-right: 5px;
        background: #e5e5e5;
        border-color: #c3c4c7;
        color: #50575e;
    }

    .sistur-stock-settings .nav-tab-active {
        background: #f0f0f1;
        border-bottom: 1px solid #f0f0f1;
        color: #000;
    }

    .sistur-stock-settings .sistur-tab-content {
        display: none;
        animation: fadeIn 0.3s ease-in-out;
    }

    .sistur-stock-settings .sistur-tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Cards */
    .sistur-stock-settings .sistur-card {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
    }

    .sistur-stock-settings .card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #ddd;
        background: #f7f7f7;
    }

    .sistur-stock-settings .card-header h2 {
        margin: 0 0 5px 0;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .sistur-stock-settings .card-header .description {
        margin: 0;
        color: #666;
    }

    .sistur-stock-settings .card-body {
        padding: 15px;
    }

    .sistur-stock-settings .card-actions {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #eee;
    }

    /* Editable cells */
    .editable-cell {
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 3px;
        transition: background 0.2s;
    }

    .editable-cell:hover {
        background: #e8f4fc;
    }

    .editable-cell.editing {
        padding: 0;
    }

    .editable-cell input {
        width: 100%;
        padding: 4px 8px;
        border: 2px solid #0073aa;
        border-radius: 3px;
        box-sizing: border-box;
    }

    /* Status indicators */
    .status-saving {
        opacity: 0.5;
    }

    .status-saved {
        animation: pulse-green 0.5s;
    }

    @keyframes pulse-green {
        0% {
            background: #d4edda;
        }

        100% {
            background: transparent;
        }
    }

    /* Badge */
    .badge-system {
        background: #e0e0e0;
        color: #666;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
    }

    /* Type labels */
    .type-label {
        padding: 3px 8px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 500;
    }

    .type-warehouse {
        background: #d1ecf1;
        color: #0c5460;
    }

    .type-refrigerator {
        background: #cce5ff;
        color: #004085;
    }

    .type-freezer {
        background: #e7f3ff;
        color: #0056b3;
    }

    .type-shelf {
        background: #fff3cd;
        color: #856404;
    }

    .type-other {
        background: #e9ecef;
        color: #495057;
    }

    .type-dimensional {
        background: #d4edda;
        color: #155724;
    }

    .type-unitary {
        background: #fef3cd;
        color: #856404;
    }

    /* Modal styles (reusing from stock-management) */
    .sistur-modal {
        position: fixed;
        z-index: 100001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.6);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 40px 20px;
        box-sizing: border-box;
    }

    .sistur-modal-content {
        background-color: #fff;
        padding: 0;
        border: none;
        width: 100%;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        margin: 0;
    }

    .sistur-modal-header {
        padding: 16px 20px;
        background-color: #0073aa;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 8px 8px 0 0;
    }

    .sistur-modal-header h2 {
        margin: 0;
        color: white;
        font-size: 18px;
    }

    .sistur-modal-close {
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
    }

    .sistur-modal-close:hover {
        color: #ddd;
    }

    .sistur-modal-body {
        padding: 20px;
    }

    .sistur-modal-body .form-table {
        margin: 0;
    }

    .sistur-modal-body .form-table th {
        width: 120px;
        padding: 12px 10px 12px 0;
        vertical-align: top;
        font-weight: 600;
    }

    .sistur-modal-body .form-table td {
        padding: 10px 0;
    }

    .sistur-modal-body .form-table input[type="text"],
    .sistur-modal-body .form-table textarea,
    .sistur-modal-body .form-table select {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .sistur-modal-footer {
        padding: 15px 20px;
        background-color: #f5f5f5;
        text-align: right;
        border-top: 1px solid #ddd;
        border-radius: 0 0 8px 8px;
    }

    .sistur-modal-footer .button {
        margin-left: 8px;
    }

    /* Responsive */
    @media screen and (max-width: 782px) {
        .sistur-settings-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    // Configuração da API
    const SisturSettingsConfig = {
        apiBase: '<?php echo esc_url(rest_url('sistur/v1/stock/')); ?>',
        nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
        i18n: {
            confirmDelete: '<?php _e('Tem certeza que deseja excluir?', 'sistur'); ?>',
            unitInUse: '<?php _e('Esta unidade está em uso e não pode ser excluída.', 'sistur'); ?>',
            systemUnit: '<?php _e('Unidades de sistema não podem ser excluídas.', 'sistur'); ?>'
        }
    };
</script>