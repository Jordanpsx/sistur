<?php
/**
 * Página de Gestão de Estoque Avançada
 * 
 * Interface administrativa para gerenciamento de produtos e movimentações
 * com suporte a lotes e controle FIFO.
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

<div class="wrap sistur-stock-management">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-archive" style="font-size: 28px; margin-right: 8px;"></span>
        <?php _e('Gestão de Estoque', 'sistur'); ?>
    </h1>

    <a href="#" id="btn-new-product" class="page-title-action">
        <span class="dashicons dashicons-plus-alt2"></span>
        <?php _e('Novo Produto', 'sistur'); ?>
    </a>

    <a href="#" id="btn-new-movement" class="page-title-action"
        style="background: #2271b1; color: white; border-color: #2271b1;">
        <span class="dashicons dashicons-update"></span>
        <?php _e('Nova Movimentação', 'sistur'); ?>
    </a>

    <hr class="wp-header-end">

    <!-- Filtros -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <select id="filter-type">
                <option value=""><?php _e('Todos os Tipos', 'sistur'); ?></option>
                <option value="RAW"><?php _e('Insumo (RAW)', 'sistur'); ?></option>
                <option value="RESALE"><?php _e('Revenda', 'sistur'); ?></option>
                <option value="MANUFACTURED"><?php _e('Produzido', 'sistur'); ?></option>
                <option value="BASE"><?php _e('Prato Base', 'sistur'); ?></option>
            </select>
            <input type="text" id="filter-search" placeholder="<?php _e('Buscar produto...', 'sistur'); ?>"
                class="regular-text">
            <button type="button" id="btn-filter" class="button"><?php _e('Filtrar', 'sistur'); ?></button>
        </div>
        <div class="alignright">
            <span id="stock-summary" class="description"></span>
        </div>
    </div>

    <!-- Alertas de Itens Próximos do Vencimento (FEFO) -->
    <?php
    global $wpdb;
    $expiring_soon = $wpdb->get_results("
        SELECT b.*, p.name as product_name, l.name as location_name
        FROM {$wpdb->prefix}sistur_inventory_batches b
        INNER JOIN {$wpdb->prefix}sistur_products p ON b.product_id = p.id
        LEFT JOIN {$wpdb->prefix}sistur_storage_locations l ON b.location_id = l.id
        WHERE b.status = 'active'
        AND b.quantity > 0
        AND b.expiry_date IS NOT NULL
        AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY b.expiry_date ASC
        LIMIT 8
    ");

    if (!empty($expiring_soon)): ?>
        <div class="notice notice-warning" style="margin: 10px 0; padding: 8px 12px;">
            <h4 style="margin: 0 0 8px 0;">
                <span class="dashicons dashicons-clock" style="color: #dba617;"></span>
                <?php _e('Itens Próximos do Vencimento (FEFO - usar primeiro!)', 'sistur'); ?>
            </h4>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <?php foreach ($expiring_soon as $item): 
                $days_left = floor((strtotime($item->expiry_date) - strtotime('today')) / 86400);
                if ($days_left < 0) {
                    $badge_style = 'background: #dc3232; color: white;';
                    $badge_text = 'VENCIDO';
                } elseif ($days_left == 0) {
                    $badge_style = 'background: #e65100; color: white;';
                    $badge_text = 'HOJE';
                } elseif ($days_left <= 3) {
                    $badge_style = 'background: #ffb900; color: #23282d;';
                    $badge_text = sprintf('%d dias', $days_left);
                } else {
                    $badge_style = 'background: #f0c36d; color: #23282d;';
                    $badge_text = sprintf('%d dias', $days_left);
                }
            ?>
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 4px; background: #fff; border: 1px solid #c3c4c7;">
                    <strong><?php echo esc_html($item->product_name); ?></strong>
                    <span style="font-family: monospace;"><?php echo number_format($item->quantity, 2, ',', '.'); ?></span>
                    <span style="<?php echo $badge_style; ?> padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                        <?php echo $badge_text; ?>
                    </span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabela de Produtos -->
    <table class="wp-list-table widefat fixed striped" id="products-table">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-sku" style="width: 100px;"><?php _e('SKU', 'sistur'); ?>
                </th>
                <th scope="col" class="manage-column column-name"><?php _e('Produto', 'sistur'); ?></th>
                <th scope="col" class="manage-column column-type" style="width: 100px;"><?php _e('Tipo', 'sistur'); ?>
                </th>
                <th scope="col" class="manage-column column-unit" style="width: 80px;"><?php _e('Unidade', 'sistur'); ?>
                </th>
                <th scope="col" class="manage-column column-stock" style="width: 100px; text-align: right;">
                    <?php _e('Estoque', 'sistur'); ?>
                </th>
                <th scope="col" class="manage-column column-cost" style="width: 100px; text-align: right;">
                    <?php _e('Custo Médio', 'sistur'); ?>
                </th>
                <th scope="col" class="manage-column column-actions" style="width: 150px;">
                    <?php _e('Ações', 'sistur'); ?>
                </th>
            </tr>
        </thead>
        <tbody id="products-tbody">
            <tr class="loading-row">
                <td colspan="7" style="text-align: center; padding: 40px;">
                    <span class="spinner is-active" style="float: none;"></span>
                    <?php _e('Carregando produtos...', 'sistur'); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Paginação -->
    <div class="tablenav bottom">
        <div class="tablenav-pages" id="pagination-container">
            <!-- Populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Modal: Novo/Editar Produto -->
<div id="modal-product" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 750px;">
        <div class="sistur-modal-header">
            <h2 id="modal-product-title"><?php _e('Novo Produto', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <!-- Abas do Modal -->
            <div class="sistur-tabs">
                <button type="button" class="sistur-tab active"
                    data-tab="tab-dados"><?php _e('📋 Dados Básicos', 'sistur'); ?></button>
                <button type="button" class="sistur-tab" data-tab="tab-composicao" id="tab-btn-composicao"
                    style="display: none;">
                    <?php _e('🧾 Composição', 'sistur'); ?>
                    <span class="tab-badge" id="recipe-count-badge">0</span>
                </button>
            </div>

            <!-- Tab: Dados Básicos -->
            <div id="tab-dados" class="sistur-tab-content active">
                <form id="form-product">
                    <input type="hidden" id="product-id" name="id" value="">

                    <table class="form-table">
                        <tr>
                            <th><label for="product-name"><?php _e('Nome *', 'sistur'); ?></label></th>
                            <td><input type="text" id="product-name" name="name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="product-sku"><?php _e('SKU', 'sistur'); ?></label></th>
                            <td><input type="text" id="product-sku" name="sku" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="product-barcode"><?php _e('Código de Barras', 'sistur'); ?></label></th>
                            <td><input type="text" id="product-barcode" name="barcode" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('Tipo(s) *', 'sistur'); ?></label></th>
                            <td>
                                <fieldset id="product-types-fieldset" class="product-type-checkboxes">
                                    <label style="display: inline-block; margin-right: 15px;">
                                        <input type="checkbox" name="types[]" value="RAW" id="type-raw">
                                        <?php _e('🥬 Insumo', 'sistur'); ?>
                                    </label>
                                    <label style="display: inline-block; margin-right: 15px;">
                                        <input type="checkbox" name="types[]" value="MANUFACTURED"
                                            id="type-manufactured">
                                        <?php _e('🍳 Produzido', 'sistur'); ?>
                                    </label>
                                    <label style="display: inline-block; margin-right: 15px;">
                                        <input type="checkbox" name="types[]" value="BASE" id="type-base">
                                        <?php _e('🍽️ Prato Base', 'sistur'); ?>
                                    </label>
                                    <label style="display: inline-block; margin-right: 15px;">
                                        <input type="checkbox" name="types[]" value="RESALE" id="type-resale">
                                        <?php _e('🛒 Revenda', 'sistur'); ?>
                                    </label>
                                    <p class="description" id="type-hint" style="margin-top: 8px;">
                                        <?php _e('Insumo = pode ser ingrediente | Produzido = tem receita | Prato Base = semi-preparo reutilizável | Ambos = pré-preparo (ex: vinagrete)', 'sistur'); ?>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>

                        <!-- v2.13.0 - Non-perishable (Only for RESOURCE/Generalization) -->
                        <tr id="row-is-perishable" style="display: none;">
                            <th><label for="product-is-perishable"><?php _e('Perecível?', 'sistur'); ?></label></th>
                            <td>
                                <input type="checkbox" id="product-is-perishable" name="is_perishable" value="1" checked>
                                <label for="product-is-perishable"><?php _e('Sim, possui validade', 'sistur'); ?></label>
                                <p class="description">
                                    <?php _e('Se desmarcado, produtos vinculados a esta generalização não pedirão validade.', 'sistur'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Vincular a Insumo Genérico (v2.4 - RESOURCE) -->
                        <tr id="row-resource-parent">
                            <th><label for="product-resource-parent"><?php _e('Insumo Genérico', 'sistur'); ?></label>
                            </th>
                            <td>
                                <select id="product-resource-parent" name="resource_parent_id">
                                    <option value=""><?php _e('Nenhum (produto independente)', 'sistur'); ?></option>
                                </select>
                                <p class="description">
                                    <?php _e('Vincule a um tipo genérico para receitas usarem qualquer marca.', 'sistur'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="product-unit"><?php _e('Unidade Base', 'sistur'); ?></label></th>
                            <td>
                                <select id="product-unit" name="base_unit_id">
                                    <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="product-cost"><?php _e('Custo Unitário', 'sistur'); ?></label></th>
                            <td><input type="number" id="product-cost" name="cost_price" step="0.01" min="0"
                                    class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="product-price"><?php _e('Preço de Venda', 'sistur'); ?></label></th>
                            <td><input type="number" id="product-price" name="selling_price" step="0.01" min="0"
                                    class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="product-min-stock"><?php _e('Estoque Mínimo', 'sistur'); ?></label></th>
                            <td><input type="number" id="product-min-stock" name="min_stock" step="0.001" min="0"
                                    class="small-text"></td>
                        </tr>

                        <!-- Campos de Conteúdo da Embalagem (v2.3) -->
                        <tr>
                            <th>
                                <label for="product-content-qty"><?php _e('Conteúdo da Embalagem', 'sistur'); ?></label>
                            </th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="number" id="product-content-qty" name="content_quantity" step="0.001"
                                        min="0" class="small-text" placeholder="<?php _e('Ex: 0.75', 'sistur'); ?>">
                                    <select id="product-content-unit" name="content_unit_id" style="width: auto;">
                                        <option value=""><?php _e('Unidade...', 'sistur'); ?></option>
                                    </select>
                                </div>
                                <p class="description">
                                    <?php _e('Quanto vem na embalagem. Ex: Lata de 900ml = 0.9 Litros. Usado para calcular volume total no Dashboard.', 'sistur'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th><label for="product-description"><?php _e('Descrição', 'sistur'); ?></label></th>
                            <td><textarea id="product-description" name="description" rows="2"
                                    class="large-text"></textarea></td>
                        </tr>

                        <!-- Localização Padrão (v2.11.0) -->
                        <tr>
                            <th><label for="product-default-location"><?php _e('Localização Padrão', 'sistur'); ?></label></th>
                            <td>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <select id="product-default-location" style="width: auto;">
                                        <option value=""><?php _e('Selecione o local...', 'sistur'); ?></option>
                                    </select>
                                    <span>&rarr;</span>
                                    <select id="product-default-sector" name="default_sector_id" style="width: auto;">
                                        <option value=""><?php _e('Selecione o setor...', 'sistur'); ?></option>
                                    </select>
                                </div>
                                <p class="description">
                                    <?php _e('Local e setor onde este produto deve ficar armazenado. Usado no inventário para detectar itens fora de lugar.', 'sistur'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>

            <!-- Tab: Composição (Ficha Técnica) -->
            <div id="tab-composicao" class="sistur-tab-content">
                <!-- Aviso para produtos novos não salvos -->
                <div id="recipe-unsaved-warning" class="notice notice-warning inline"
                    style="margin: 0 0 15px 0; display: none;">
                    <p>
                        <span class="dashicons dashicons-warning"></span>
                        <strong><?php _e('Salve o produto primeiro!', 'sistur'); ?></strong>
                        <?php _e('Você precisa salvar o produto antes de adicionar ingredientes à receita.', 'sistur'); ?>
                    </p>
                </div>

                <div class="recipe-header">
                    <div class="recipe-cost-display">
                        <span class="cost-label"><?php _e('Custo Total da Receita:', 'sistur'); ?></span>
                        <span class="cost-value" id="recipe-total-cost">R$ 0,00</span>
                    </div>
                    <button type="button" class="button button-primary" id="btn-add-ingredient">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php _e('Adicionar Ingrediente', 'sistur'); ?>
                    </button>
                </div>

                <table class="wp-list-table widefat fixed striped" id="recipe-ingredients-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;"><?php _e('Ingrediente', 'sistur'); ?></th>
                            <th style="width: 15%;"><?php _e('Peso Líquido', 'sistur'); ?></th>
                            <th style="width: 12%;"><?php _e('Fator', 'sistur'); ?></th>
                            <th style="width: 15%;"><?php _e('Peso Bruto', 'sistur'); ?></th>
                            <th style="width: 15%;"><?php _e('Custo', 'sistur'); ?></th>
                            <th style="width: 13%;"><?php _e('Ações', 'sistur'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="recipe-ingredients-tbody">
                        <tr class="no-ingredients">
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                <span class="dashicons dashicons-info-outline" style="color: #999;"></span>
                                <?php _e('Nenhum ingrediente cadastrado. Clique em "Adicionar Ingrediente" para começar.', 'sistur'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="recipe-formula-hint">
                    <span class="dashicons dashicons-info"></span>
                    <strong><?php _e('Fórmula:', 'sistur'); ?></strong>
                    <?php _e('Peso Bruto = Peso Líquido ÷ Fator de Rendimento', 'sistur'); ?>
                    <br>
                    <small><?php _e('Ex: Arroz (fator 2.5) - 250g cozido ÷ 2.5 = 100g cru sai do estoque', 'sistur'); ?></small>
                </div>
            </div>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button"
                onclick="SisturStock.closeModal('modal-product')"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary"
                id="btn-save-product"><?php _e('Salvar Produto', 'sistur'); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Adicionar Ingrediente -->
<div id="modal-ingredient" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 500px;">
        <div class="sistur-modal-header">
            <h2><?php _e('Adicionar Ingrediente', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <form id="form-ingredient">
                <input type="hidden" id="ingredient-recipe-id" name="recipe_id" value="">

                <table class="form-table">
                    <tr>
                        <th><label for="ingredient-search"><?php _e('Ingrediente *', 'sistur'); ?></label></th>
                        <td>
                            <input type="text" id="ingredient-search" class="regular-text"
                                placeholder="<?php _e('Digite para buscar...', 'sistur'); ?>" autocomplete="off">
                            <input type="hidden" id="ingredient-product-id" name="child_product_id" value="">
                            <div id="ingredient-search-results" class="search-dropdown" style="display: none;"></div>
                            <p class="description" id="ingredient-selected"></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ingredient-net"><?php _e('Peso Líquido *', 'sistur'); ?></label></th>
                        <td>
                            <input type="number" id="ingredient-net" name="quantity_net" step="0.001" min="0.001"
                                class="small-text" required>
                            <select id="ingredient-unit" name="unit_id" style="width: auto;">
                                <option value=""><?php _e('Unidade', 'sistur'); ?></option>
                            </select>
                            <p class="description"><?php _e('Quantidade desejada no prato final.', 'sistur'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ingredient-factor"><?php _e('Fator Rendimento', 'sistur'); ?></label></th>
                        <td>
                            <input type="number" id="ingredient-factor" name="yield_factor" step="0.01" min="0.01"
                                value="1.00" class="small-text">
                            <p class="description"><?php _e('Ex: 2.5 para arroz, 0.8 para carne.', 'sistur'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Peso Bruto', 'sistur'); ?></th>
                        <td>
                            <div class="gross-weight-display">
                                <span id="calculated-gross">0.000</span>
                                <span class="gross-hint"><?php _e('(será descontado do estoque)', 'sistur'); ?></span>
                            </div>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary"
                id="btn-save-ingredient"><?php _e('Adicionar', 'sistur'); ?></button>
        </div>
    </div>
</div>


<!-- Modal: Nova Movimentação -->
<div id="modal-movement" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 550px;">
        <div class="sistur-modal-header">
            <h2><?php _e('Nova Movimentação de Estoque', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <form id="form-movement">
                <table class="form-table">
                    <tr>
                        <th><label for="movement-product"><?php _e('Produto *', 'sistur'); ?></label></th>
                        <td>
                            <select id="movement-product" name="product_id" required style="width: 100%;">
                                <option value=""><?php _e('Selecione o produto...', 'sistur'); ?></option>
                            </select>
                            <p class="description" id="product-stock-info"></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="movement-type"><?php _e('Tipo *', 'sistur'); ?></label></th>
                        <td>
                            <select id="movement-type" name="type" required>
                                <option value="IN"><?php _e('📥 Entrada (Compra/Devolução)', 'sistur'); ?></option>
                                <option value="OUT"><?php _e('📤 Saída (Uso Interno)', 'sistur'); ?></option>
                                <option value="SALE"><?php _e('💰 Venda', 'sistur'); ?></option>
                                <option value="LOSS"><?php _e('⚠️ Perda/Quebra', 'sistur'); ?></option>
                                <option value="ADJUST"><?php _e('🔧 Ajuste de Inventário', 'sistur'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="movement-quantity"><?php _e('Quantidade *', 'sistur'); ?></label></th>
                        <td>
                            <input type="number" id="movement-quantity" name="quantity" step="0.001" min="0.001"
                                required class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="movement-location"><?php _e('Local', 'sistur'); ?></label></th>
                        <td>
                            <select id="movement-location" name="location_id">
                                <option value=""><?php _e('Padrão', 'sistur'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <!-- Campos específicos para ENTRADA -->
                    <tr class="entry-fields" style="display: none;">
                        <th><label for="movement-cost"><?php _e('Custo Unitário', 'sistur'); ?></label></th>
                        <td>
                            <input type="number" id="movement-cost" name="cost_price" step="0.0001" min="0"
                                class="regular-text">
                            <p class="description"><?php _e('Custo de aquisição deste lote', 'sistur'); ?></p>
                        </td>
                    </tr>
                    <tr class="entry-fields" style="display: none;">
                        <th><label for="movement-batch"><?php _e('Lote (Fabricante)', 'sistur'); ?></label></th>
                        <td><input type="text" id="movement-batch" name="batch_code" class="regular-text"></td>
                    </tr>
                    <tr class="entry-fields" style="display: none;">
                        <th><label for="movement-expiry"><?php _e('Data de Validade', 'sistur'); ?></label></th>
                        <td><input type="date" id="movement-expiry" name="expiry_date" class="regular-text"></td>
                    </tr>

                    <tr>
                        <th><label for="movement-reason"><?php _e('Observações', 'sistur'); ?></label></th>
                        <td><textarea id="movement-reason" name="reason" rows="2" class="large-text"
                                placeholder="<?php _e('Ex: NF 12345, Ajuste mensal, etc.', 'sistur'); ?>"></textarea>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button"
                onclick="SisturStock.closeModal('modal-movement')"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary"
                id="btn-save-movement"><?php _e('Registrar Movimentação', 'sistur'); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Ver Lotes -->
<div id="modal-batches" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 800px;">
        <div class="sistur-modal-header">
            <h2 id="modal-batches-title"><?php _e('Lotes do Produto', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <table class="wp-list-table widefat fixed striped" id="batches-table">
                <thead>
                    <tr>
                        <th><?php _e('Lote', 'sistur'); ?></th>
                        <th><?php _e('Local', 'sistur'); ?></th>
                        <th><?php _e('Validade', 'sistur'); ?></th>
                        <th><?php _e('Custo', 'sistur'); ?></th>
                        <th><?php _e('Quantidade', 'sistur'); ?></th>
                        <th><?php _e('Status', 'sistur'); ?></th>
                        <th><?php _e('Entrada', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody id="batches-tbody">
                </tbody>
            </table>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button"
                onclick="SisturStock.closeModal('modal-batches')"><?php _e('Fechar', 'sistur'); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Produzir Produto MANUFACTURED (v2.5) -->
<div id="modal-production" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 600px;">
        <div class="sistur-modal-header" style="background: linear-gradient(135deg, #46b450 0%, #2e7d32 100%);">
            <h2 id="modal-production-title">
                <span class="dashicons dashicons-hammer" style="margin-right: 8px;"></span>
                <?php _e('Produzir', 'sistur'); ?>
            </h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <form id="form-production">
                <input type="hidden" id="production-product-id" name="product_id" value="">

                <div class="production-info"
                    style="background: #f0f6fc; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0;">
                        <span class="dashicons dashicons-food"></span>
                        <span id="production-product-name">Produto</span>
                    </h4>
                    <p class="description" style="margin: 0;">
                        <?php _e('Ao produzir, os ingredientes serão consumidos do estoque e o produto final será adicionado.', 'sistur'); ?>
                    </p>
                </div>

                <table class="form-table">
                    <tr>
                        <th><label for="production-quantity"><?php _e('Quantidade a Produzir *', 'sistur'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="production-quantity" name="quantity" step="1" min="1" value="1"
                                required class="regular-text" style="width: 100px;">
                            <span id="production-unit" class="description" style="margin-left: 5px;">unidades</span>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Ingredientes Necessários', 'sistur'); ?></label></th>
                        <td>
                            <div id="production-ingredients-preview" style="max-height: 200px; overflow-y: auto;">
                                <p class="description"><?php _e('Carregando receita...', 'sistur'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="production-notes"><?php _e('Observações', 'sistur'); ?></label></th>
                        <td>
                            <textarea id="production-notes" name="notes" rows="2" class="large-text"
                                placeholder="<?php _e('Ex: Produção para evento, etc.', 'sistur'); ?>"></textarea>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button"
                onclick="SisturStock.closeModal('modal-production')"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary" id="btn-execute-production"
                style="background: #46b450; border-color: #46b450;">
                <span class="dashicons dashicons-hammer" style="vertical-align: middle;"></span>
                <?php _e('Produzir Agora', 'sistur'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Registrar Perda (v2.7.0 - Loss Tracking) -->
<div id="modal-loss" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 550px;">
        <div class="sistur-modal-header" style="background: linear-gradient(135deg, #d63638 0%, #b32d2f 100%);">
            <h2 id="modal-loss-title">
                <span class="dashicons dashicons-warning" style="margin-right: 8px;"></span>
                <?php _e('Registrar Perda', 'sistur'); ?>
            </h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <form id="form-loss">
                <input type="hidden" id="loss-product-id" name="product_id" value="">

                <div class="loss-info"
                    style="background: #fcf0f0; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #d63638;">
                    <h4 style="margin: 0 0 5px 0; color: #d63638;">
                        <span class="dashicons dashicons-archive"></span>
                        <span id="loss-product-name">Produto</span>
                    </h4>
                    <p class="description" style="margin: 0;">
                        <?php _e('Estoque atual:', 'sistur'); ?>
                        <strong id="loss-current-stock">0</strong>
                        <span id="loss-unit-symbol"></span>
                    </p>
                </div>

                <table class="form-table">
                    <tr>
                        <th><label for="loss-quantity"><?php _e('Quantidade *', 'sistur'); ?></label></th>
                        <td>
                            <input type="number" id="loss-quantity" name="quantity" step="0.001" min="0.001" required
                                class="regular-text" style="width: 120px;">
                            <span id="loss-unit-display" class="description" style="margin-left: 5px;"></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loss-reason"><?php _e('Motivo *', 'sistur'); ?></label></th>
                        <td>
                            <select id="loss-reason" name="reason" required style="width: 100%;">
                                <option value=""><?php _e('Selecione o motivo...', 'sistur'); ?></option>
                                <option value="expired">📅 <?php _e('Produto Vencido', 'sistur'); ?></option>
                                <option value="production_error">⚙️ <?php _e('Erro de Produção', 'sistur'); ?></option>
                                <option value="damaged">💥 <?php _e('Dano Físico (quebra, queda)', 'sistur'); ?>
                                </option>
                                <option value="other">📝 <?php _e('Outro', 'sistur'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loss-details"><?php _e('Detalhes', 'sistur'); ?></label></th>
                        <td>
                            <textarea id="loss-details" name="reason_details" rows="3" class="large-text"
                                placeholder="<?php _e('Descreva o que aconteceu (opcional)', 'sistur'); ?>"></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loss-location"><?php _e('Local', 'sistur'); ?></label></th>
                        <td>
                            <select id="loss-location" name="location_id" style="width: 100%;">
                                <option value=""><?php _e('Padrão', 'sistur'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <div class="loss-warning"
                    style="background: #fff8e5; padding: 12px; border-radius: 4px; border-left: 4px solid #f0c33c; margin-top: 15px;">
                    <span class="dashicons dashicons-info" style="color: #f0c33c;"></span>
                    <strong><?php _e('Atenção:', 'sistur'); ?></strong>
                    <?php _e('Esta ação irá debitar o estoque e registrar o custo para cálculo de CMV.', 'sistur'); ?>
                </div>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button"
                onclick="SisturStock.closeModal('modal-loss')"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary" id="btn-submit-loss"
                style="background: #d63638; border-color: #d63638;">
                <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                <?php _e('Registrar Perda', 'sistur'); ?>
            </button>
        </div>
    </div>
</div>

<style>
    /* Modal Styles - Professional Design (matching shift-patterns) */
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
        max-width: 700px;
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
        font-weight: 600;
    }

    .sistur-modal-close {
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
        background: none;
        border: none;
        padding: 0;
    }

    .sistur-modal-close:hover,
    .sistur-modal-close:focus {
        color: #ddd;
    }

    .sistur-modal-body {
        padding: 20px;
        max-height: 65vh;
        overflow-y: auto;
    }

    .sistur-modal-body .form-table {
        margin: 0;
    }

    .sistur-modal-body .form-table th {
        width: 160px;
        padding: 12px 10px 12px 0;
        vertical-align: top;
        font-weight: 600;
        line-height: 1.4;
    }

    .sistur-modal-body .form-table td {
        padding: 10px 0;
    }

    .sistur-modal-body .form-table input[type="text"],
    .sistur-modal-body .form-table input[type="number"],
    .sistur-modal-body .form-table input[type="date"],
    .sistur-modal-body .form-table textarea,
    .sistur-modal-body .form-table select {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .sistur-modal-body .form-table input[type="number"] {
        max-width: 150px;
    }

    .sistur-modal-body .form-table .description {
        color: #666;
        font-size: 12px;
        margin-top: 5px;
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

    /* Stock Management Specific */
    .sistur-stock-management .page-title-action .dashicons {
        font-size: 16px;
        vertical-align: text-bottom;
        margin-right: 3px;
    }

    .sistur-stock-management #products-table .column-stock {
        font-weight: 600;
    }

    .sistur-stock-management .stock-low {
        color: #d63638;
    }

    .sistur-stock-management .stock-ok {
        color: #00a32a;
    }

    .sistur-stock-management .type-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
    }

    .sistur-stock-management .type-RAW {
        background: #fef3cd;
        color: #856404;
    }

    .sistur-stock-management .type-RESALE {
        background: #d1ecf1;
        color: #0c5460;
    }

    .sistur-stock-management .type-MANUFACTURED {
        background: #d4edda;
        color: #155724;
    }

    .sistur-stock-management .type-BASE {
        background: #e8d5f5;
        color: #5a1a8a;
    }

    .sistur-stock-management .batch-active {
        color: #00a32a;
    }

    .sistur-stock-management .batch-depleted {
        color: #999;
    }

    .sistur-stock-management .batch-expired {
        color: #d63638;
    }

    /* Campos obrigatórios */
    .required {
        color: #d63638;
    }

    /* Responsive */
    @media screen and (max-width: 782px) {
        .sistur-modal {
            padding: 20px 10px;
        }

        .sistur-modal-content {
            max-width: 100%;
        }

        .sistur-modal-body .form-table th {
            display: block;
            width: 100%;
            padding-bottom: 5px;
        }

        .sistur-modal-body .form-table td {
            display: block;
            width: 100%;
            padding-left: 0;
        }
    }

    /* ===== TABS SYSTEM ===== */
    .sistur-tabs {
        display: flex;
        gap: 0;
        border-bottom: 2px solid #c3c4c7;
        margin-bottom: 20px;
    }

    .sistur-tab {
        padding: 10px 20px;
        background: transparent;
        border: none;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: #646970;
        transition: all 0.2s;
    }

    .sistur-tab:hover {
        color: #0073aa;
        background: #f0f6fc;
    }

    .sistur-tab.active {
        color: #0073aa;
        border-bottom-color: #0073aa;
        background: transparent;
    }

    .sistur-tab .tab-badge {
        display: inline-block;
        background: #0073aa;
        color: white;
        font-size: 11px;
        padding: 1px 6px;
        border-radius: 10px;
        margin-left: 5px;
    }

    .sistur-tab-content {
        display: none;
    }

    .sistur-tab-content.active {
        display: block;
    }

    /* ===== RECIPE SECTION ===== */
    .recipe-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding: 10px 15px;
        background: #f0f6fc;
        border-radius: 4px;
    }

    .recipe-cost-display {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .recipe-cost-display .cost-label {
        color: #666;
        font-weight: 500;
    }

    .recipe-cost-display .cost-value {
        font-size: 18px;
        font-weight: 700;
        color: #0073aa;
    }

    #recipe-ingredients-table {
        margin-bottom: 15px;
    }

    #recipe-ingredients-table td {
        vertical-align: middle;
    }

    .recipe-formula-hint {
        padding: 10px 15px;
        background: #fff8e5;
        border-left: 4px solid #f0c33c;
        font-size: 12px;
        color: #555;
    }

    .recipe-formula-hint .dashicons {
        color: #f0c33c;
        vertical-align: text-bottom;
        margin-right: 5px;
    }

    /* ===== SEARCH DROPDOWN ===== */
    .search-dropdown {
        position: absolute;
        z-index: 1000;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        max-height: 200px;
        overflow-y: auto;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        width: calc(100% - 20px);
    }

    .search-dropdown-item {
        padding: 8px 12px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
    }

    .search-dropdown-item:last-child {
        border-bottom: none;
    }

    .search-dropdown-item:hover {
        background: #f0f6fc;
    }

    .search-dropdown-item .item-name {
        font-weight: 500;
    }

    .search-dropdown-item .item-details {
        font-size: 11px;
        color: #666;
    }

    /* ===== GROSS WEIGHT DISPLAY ===== */
    .gross-weight-display {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        background: #e8f7ee;
        border-radius: 4px;
        border: 1px solid #b8dbc8;
    }

    .gross-weight-display #calculated-gross {
        font-size: 18px;
        font-weight: 700;
        color: #00a32a;
    }

    .gross-weight-display .gross-hint {
        font-size: 11px;
        color: #666;
    }

    /* Position relative for search container */
    .sistur-modal-body .form-table td {
        position: relative;
    }
</style>

<script>
    // Configuração da API
    const SisturStockConfig = {
        apiBase: '<?php echo esc_url(rest_url('sistur/v1/stock/')); ?>',
        recipesApiBase: '<?php echo esc_url(rest_url('sistur/v1/')); ?>',
        nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
        i18n: {
            loading: '<?php _e('Carregando...', 'sistur'); ?>',
            noProducts: '<?php _e('Nenhum produto encontrado.', 'sistur'); ?>',
            confirmDelete: '<?php _e('Tem certeza que deseja excluir este produto?', 'sistur'); ?>',
            success: '<?php _e('Operação realizada com sucesso!', 'sistur'); ?>',
            error: '<?php _e('Ocorreu um erro. Tente novamente.', 'sistur'); ?>'
        }
    };
</script>