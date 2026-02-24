<?php
/**
 * Componente: Módulo de Estoque (Inventário)
 *
 * @package SISTUR
 * @since 2.0.0
 */

$session = SISTUR_Session::get_instance();
$permissions = SISTUR_Permissions::get_instance();
$employee_id = $session->get_employee_id();

// v2.16.0: Em modo Macro, o registro de venda por produto fica desativado —
// a receita é lançada globalmente via "Fechar Caixa" no painel admin.
$is_macro_sales_mode = get_option('sistur_sales_mode', 'MICRO') === 'MACRO';

// Permissões do usuário - Inventário básico
$can_record_sale = !$is_macro_sales_mode && $permissions->can($employee_id, 'inventory', 'record_sale');
$can_request_loss = $permissions->can($employee_id, 'inventory', 'request_loss');
$can_approve_loss = $permissions->can($employee_id, 'inventory', 'approve_loss');
$can_manage = $permissions->can($employee_id, 'inventory', 'manage');

// Permissões CMV - Portal do Colaborador
$can_cmv_manage_products = $permissions->can($employee_id, 'cmv', 'manage_products');
$can_cmv_manage_recipes = $permissions->can($employee_id, 'cmv', 'manage_recipes');
$can_cmv_produce = $permissions->can($employee_id, 'cmv', 'produce');
$can_cmv_view_batches = $permissions->can($employee_id, 'cmv', 'view_batches');
$can_cmv_view_costs = $permissions->can($employee_id, 'cmv', 'view_costs');
$can_cmv_full = $permissions->can($employee_id, 'cmv', 'manage_full');

// Se tem permissão full de CMV, ativa todas as permissões CMV
if ($can_cmv_full) {
    $can_cmv_manage_products = true;
    $can_cmv_manage_recipes = true;
    $can_cmv_produce = true;
    $can_cmv_view_batches = true;
    $can_cmv_view_costs = true;
}

// Flag para mostrar funcionalidades CMV avançadas
$show_cmv_features = $can_cmv_manage_products || $can_cmv_produce || $can_cmv_view_batches;

global $wpdb;

// Buscar produtos para movimentação (com dados adicionais para CMV)
$products = $wpdb->get_results(
    "SELECT p.id, p.name, p.sku, p.current_stock, p.cached_stock, p.base_unit_id, u.symbol as unit, p.min_stock, p.type, p.average_cost, p.selling_price, p.status,
     (CASE 
        WHEN p.type = 'RESOURCE' THEN (
            SELECT COALESCE(SUM(
                b.quantity * COALESCE(child.content_quantity, 1) 
                * COALESCE(
                    (SELECT uc.factor FROM {$wpdb->prefix}sistur_unit_conversions uc 
                     WHERE uc.from_unit_id = child.content_unit_id 
                     AND uc.to_unit_id = p.base_unit_id 
                     AND uc.product_id IS NULL LIMIT 1),
                    1
                )
            ), 0)
            FROM {$wpdb->prefix}sistur_inventory_batches b 
            INNER JOIN {$wpdb->prefix}sistur_products child ON b.product_id = child.id 
            WHERE child.resource_parent_id = p.id 
            AND b.status = 'active'
            AND b.quantity > 0
        )
        ELSE p.cached_stock
     END) as calculated_stock,
     (CASE 
        WHEN p.type = 'RESOURCE' THEN (
            SELECT SUM(b.quantity * b.cost_price) / NULLIF(SUM(
                b.quantity * COALESCE(child.content_quantity, 1)
                * COALESCE(
                    (SELECT uc.factor FROM {$wpdb->prefix}sistur_unit_conversions uc 
                     WHERE uc.from_unit_id = child.content_unit_id 
                     AND uc.to_unit_id = p.base_unit_id 
                     AND uc.product_id IS NULL LIMIT 1),
                    1
                )
            ), 0)
            FROM {$wpdb->prefix}sistur_inventory_batches b 
            INNER JOIN {$wpdb->prefix}sistur_products child ON b.product_id = child.id 
            WHERE child.resource_parent_id = p.id 
            AND b.status = 'active'
            AND b.quantity > 0
            AND b.cost_price > 0
        )
        ELSE p.average_cost
     END) as calculated_cost 
     FROM {$wpdb->prefix}sistur_products p
     LEFT JOIN {$wpdb->prefix}sistur_units u ON p.base_unit_id = u.id
     WHERE p.status = 'active'
     ORDER BY p.name ASC",
    ARRAY_A
);

// Buscar movimentações recentes do usuário
$recent_movements = $wpdb->get_results($wpdb->prepare(
    "SELECT m.*, p.name as product_name 
     FROM {$wpdb->prefix}sistur_inventory_movements m
     JOIN {$wpdb->prefix}sistur_products p ON m.product_id = p.id
     WHERE m.employee_id = %d
     ORDER BY m.created_at DESC
     LIMIT 10",
    $employee_id
), ARRAY_A);

// Separar produtos por tipo (Generalizações vs Estoque Regular)
$products_generics = array_filter($products, function ($p) {
    return strpos($p['type'] ?? '', 'RESOURCE') !== false;
});

$products_stock = array_filter($products, function ($p) {
    return strpos($p['type'] ?? '', 'RESOURCE') === false;
});

// =============================================================================
// Sanity Checks para Generalizações (RESOURCE)
// =============================================================================

// 1. Produtos filhos sem content_quantity definido (fator de conversão ausente)
$children_missing_content = $wpdb->get_results(
    "SELECT p.id, p.name, par.id as parent_id, par.name as parent_name
     FROM {$wpdb->prefix}sistur_products p
     INNER JOIN {$wpdb->prefix}sistur_products par ON p.resource_parent_id = par.id
     WHERE p.status = 'active'
       AND p.resource_parent_id IS NOT NULL
       AND (p.content_quantity IS NULL OR p.content_quantity <= 0)
     ORDER BY par.name ASC, p.name ASC",
    ARRAY_A
);

// Indexar por parent_id para lookup rápido na tabela HTML
$missing_content_parent_ids = array();
foreach ($children_missing_content as $mc) {
    $missing_content_parent_ids[$mc['parent_id']] = true;
}

// 2. Produtos filhos com content_unit_id diferente da base do pai sem conversão registrada
$children_missing_conversion = $wpdb->get_results(
    "SELECT p.id, p.name, par.id as parent_id, par.name as parent_name,
            cu.symbol as child_unit, pu.symbol as parent_unit
     FROM {$wpdb->prefix}sistur_products p
     INNER JOIN {$wpdb->prefix}sistur_products par ON p.resource_parent_id = par.id
     LEFT JOIN {$wpdb->prefix}sistur_units cu  ON p.content_unit_id = cu.id
     LEFT JOIN {$wpdb->prefix}sistur_units pu  ON par.base_unit_id  = pu.id
     WHERE p.status = 'active'
       AND p.resource_parent_id IS NOT NULL
       AND p.content_unit_id IS NOT NULL
       AND p.content_unit_id != par.base_unit_id
       AND NOT EXISTS (
           SELECT 1 FROM {$wpdb->prefix}sistur_unit_conversions uc
           WHERE uc.from_unit_id = p.content_unit_id
             AND uc.to_unit_id   = par.base_unit_id
             AND uc.product_id IS NULL
       )
     ORDER BY par.name ASC, p.name ASC",
    ARRAY_A
);

$missing_conversion_parent_ids = array();
foreach ($children_missing_conversion as $mc) {
    $missing_conversion_parent_ids[$mc['parent_id']] = true;
}

// 3. Desvio de custo > 20% entre custo vivo (lotes ativos) e custo histórico (average_cost)
$cost_sanity_warnings = array();
foreach ($products_generics as $product) {
    $live = (float) ($product['calculated_cost'] ?? 0);
    $hist = (float) ($product['average_cost'] ?? 0);
    if ($live > 0 && $hist > 0) {
        $deviation = abs($live - $hist) / $hist;
        if ($deviation > 0.20) {
            $cost_sanity_warnings[$product['id']] = array(
                'name' => $product['name'],
                'live_cost' => $live,
                'hist_cost' => $hist,
                'deviation' => round($deviation * 100, 1),
            );
            error_log(sprintf(
                '[SISTUR Sanity] Custo medio de "%s" (ID %d) desviou %.1f%% — Live: R$ %.4f | Historico: R$ %.4f',
                $product['name'],
                $product['id'],
                $deviation * 100,
                $live,
                $hist
            ));
        }
    }
}

// =============================================================================

// Buscar unidades de medida para formulários
$units = $wpdb->get_results(
    "SELECT id, name, symbol as abbreviation, type FROM {$wpdb->prefix}sistur_units ORDER BY name ASC",
    ARRAY_A
);

// Buscar locais para formulários
$locations = $wpdb->get_results(
    "SELECT id, name, parent_id FROM {$wpdb->prefix}sistur_storage_locations WHERE is_active = 1 ORDER BY parent_id ASC, name ASC",
    ARRAY_A
);

// Organizar locais em hierarquia para uso no JS
$locations_hierarchy = array();
$locations_flat = array();

foreach ($locations as $loc) {
    // Se é pai (parent_id null)
    if (empty($loc['parent_id'])) {
        if (!isset($locations_hierarchy[$loc['id']])) {
            $locations_hierarchy[$loc['id']] = array(
                'id' => $loc['id'],
                'name' => $loc['name'],
                'sectors' => array()
            );
        } else {
            // Caso já tenha sido criado por um filho órfão (não deve acontecer com este order by, mas por segurança)
            $locations_hierarchy[$loc['id']]['id'] = $loc['id'];
            $locations_hierarchy[$loc['id']]['name'] = $loc['name'];
        }
    } else {
        // É setor (tem parent_id)
        $pid = $loc['parent_id'];
        if (!isset($locations_hierarchy[$pid])) {
            $locations_hierarchy[$pid] = array('sectors' => array());
        }
        $locations_hierarchy[$pid]['sectors'][] = array(
            'id' => $loc['id'],
            'name' => $loc['name']
        );
    }
}
// Remover chaves vazias ou incompletas se houver
$locations_hierarchy = array_filter($locations_hierarchy, function ($l) {
    return !empty($l['id']);
});

// Reindexar para garantir array JSON (não objeto)
$locations_hierarchy = array_values($locations_hierarchy);

// Enriquecer unidades com tipo de medida (Mass vs Volume)
// O banco só tem 'dimensional', precisamos distinguir
$mass_symbols = array('kg', 'g', 'mg', 't');
$volume_symbols = array('l', 'ml', 'gal');

// Mapa de produtos por local (para filtro no JS)
$location_products_map = array();
$batches = $wpdb->get_results("
    SELECT DISTINCT location_id, product_id 
    FROM {$wpdb->prefix}sistur_inventory_batches 
    WHERE status = 'active' AND quantity > 0
");
foreach ($batches as $b) {
    if (!isset($location_products_map[$b->location_id])) {
        $location_products_map[$b->location_id] = array();
    }
    $location_products_map[$b->location_id][] = (int) $b->product_id;
}

foreach ($units as &$u) {
    $symbol_lower = strtolower($u['abbreviation']);
    if (in_array($symbol_lower, $mass_symbols)) {
        $u['measure_type'] = 'mass';
    } elseif (in_array($symbol_lower, $volume_symbols)) {
        $u['measure_type'] = 'volume';
    } else {
        $u['measure_type'] = $u['type']; // 'unitary' or unknown 'dimensional'
    }
}
unset($u);
?>

<div class="module-estoque">
    <h2><?php _e('Gestão de Estoque', 'sistur'); ?></h2>

    <!-- Ações Rápidas -->
    <div class="estoque-actions-grid">
        <?php if ($can_record_sale): ?>
            <button class="estoque-action-btn sale" data-modal="modal-sale">
                <span class="dashicons dashicons-cart"></span>
                <span><?php _e('Registrar Venda', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_request_loss): ?>
            <button class="estoque-action-btn loss" data-modal="modal-loss">
                <span class="dashicons dashicons-warning"></span>
                <span><?php _e('Solicitar Baixa', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <button class="estoque-action-btn transfer" id="btn-open-transfer">
                <span class="dashicons dashicons-update"></span>
                <span><?php _e('Transferir', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <button class="estoque-action-btn entry" data-modal="modal-entry">
                <span class="dashicons dashicons-plus-alt"></span>
                <span><?php _e('Registrar Entrada', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_cmv_manage_products): ?>
            <button class="estoque-action-btn product" id="btn-new-product-portal">
                <span class="dashicons dashicons-archive"></span>
                <span><?php _e('Novo Produto', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_cmv_produce): ?>
            <button class="estoque-action-btn produce" id="btn-produce-portal">
                <span class="dashicons dashicons-hammer"></span>
                <span><?php _e('Produzir', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <button class="estoque-action-btn location" id="btn-manage-locations">
                <span class="dashicons dashicons-location"></span>
                <span><?php _e('Gerenciar Locais', 'sistur'); ?></span>
            </button>
        <?php endif; ?>
        <?php if ($can_manage): ?>
            <button class="estoque-action-btn location" id="btn-manage-suppliers">
                <span class="dashicons dashicons-store"></span>
                <span><?php _e('Fornecedores', 'sistur'); ?></span>
            </button>
        <?php endif; ?>
        <?php if ($can_manage): ?>
            <button class="estoque-action-btn blind-inventory" id="btn-blind-inventory">
                <span class="dashicons dashicons-clipboard"></span>
                <span><?php _e('Inventário Cego', 'sistur'); ?></span>
            </button>
        <?php endif; ?>

        <?php if ($can_manage || $permissions->can($employee_id, 'inventory', 'view')): ?>
            <button class="estoque-action-btn view-location" id="btn-view-by-location" style="background: #8b5cf6;">
                <span class="dashicons dashicons-category"></span>
                <span><?php _e('Por Local', 'sistur'); ?></span>
            </button>
        <?php endif; ?>
    </div>


    <!-- Produtos em Estoque Baixo -->
    <?php
    $low_stock = array_filter($products, function ($p) {
        return ($p['calculated_stock'] ?? 0) <= $p['min_stock'];
    });
    if (!empty($low_stock)):
        ?>
        <div class="estoque-alert low-stock">
            <span class="dashicons dashicons-warning"></span>
            <strong><?php _e('Atenção:', 'sistur'); ?></strong>
            <?php printf(__('%d produto(s) com estoque baixo', 'sistur'), count($low_stock)); ?>
        </div>
    <?php endif; ?>

    <!-- Alertas de Itens Próximos do Vencimento (FEFO) -->
    <?php
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
        <div class="expiry-alerts-panel">
            <h4>
                <span class="dashicons dashicons-clock"></span>
                <?php _e('Itens Próximos do Vencimento (usar primeiro!)', 'sistur'); ?>
            </h4>
            <div class="expiry-list">
                <?php foreach ($expiring_soon as $item):
                    $days_left = floor((strtotime($item->expiry_date) - strtotime('today')) / 86400);
                    $urgency = $days_left <= 0 ? 'expired' : ($days_left <= 3 ? 'critical' : 'warning');
                    ?>
                    <div class="expiry-item <?php echo $urgency; ?>">
                        <strong><?php echo esc_html($item->product_name); ?></strong>
                        <span
                            class="qty"><?php echo rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ','); ?></span>
                        <span class="date">
                            <?php
                            if ($days_left < 0) {
                                echo '<span class="badge-expired">VENCIDO</span>';
                            } elseif ($days_left == 0) {
                                echo '<span class="badge-today">VENCE HOJE</span>';
                            } else {
                                printf(__('%d dia(s)', 'sistur'), $days_left);
                            }
                            ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Abas e Lista de Produtos -->
    <div class="estoque-products-section">

        <!-- Controles de Aba -->
        <div class="stock-tabs"
            style="display: flex; gap: 20px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
            <button class="stock-tab-btn active" data-target="tab-stock-products"
                style="padding: 10px 5px; border: none; background: transparent; border-bottom: 3px solid #f59e0b; font-weight: 700; cursor: pointer; color: #1e293b; font-size: 1rem;">
                <?php _e('Produtos', 'sistur'); ?>
            </button>
            <button class="stock-tab-btn" data-target="tab-stock-generics"
                style="padding: 10px 5px; border: none; background: transparent; border-bottom: 3px solid transparent; font-weight: 600; cursor: pointer; color: #64748b; font-size: 1rem;">
                <?php _e('Generalizações', 'sistur'); ?>
            </button>
        </div>

        <div class="estoque-search">
            <input type="text" id="estoque-search" placeholder="<?php _e('Buscar produto...', 'sistur'); ?>">
        </div>

        <!-- TAB: PRODUTOS (Estoque Regular) -->
        <div id="tab-stock-products" class="stock-tab-content">
            <table class="estoque-products-table">
                <thead>
                    <tr>
                        <th><?php _e('Produto', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Tipo', 'sistur'); ?></th>
                        <th><?php _e('SKU', 'sistur'); ?></th>
                        <th><?php _e('Estoque', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Custo Médio', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Preço Venda', 'sistur'); ?></th>
                        <th><?php _e('Ações', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody id="estoque-products-list">
                    <?php if (empty($products_stock)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 20px; color: #64748b;">
                                <?php _e('Nenhum produto encontrado.', 'sistur'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($products_stock as $product):
                        $is_manufactured = strpos($product['type'] ?? '', 'MANUFACTURED') !== false
                            || strpos($product['type'] ?? '', 'BASE') !== false;
                        $type_label = '';
                        if (strpos($product['type'] ?? '', 'RAW') !== false)
                            $type_label = '🥬 Insumo';
                        if (strpos($product['type'] ?? '', 'MANUFACTURED') !== false)
                            $type_label .= ($type_label ? ' + ' : '') . '🍳 Produzido';
                        if (strpos($product['type'] ?? '', 'RESALE') !== false)
                            $type_label = '🛒 Revenda';
                        if (strpos($product['type'] ?? '', 'BASE') !== false)
                            $type_label .= ($type_label ? ' + ' : '') . '🍲 Prato Base';
                        ?>
                        <tr data-product-id="<?php echo esc_attr($product['id']); ?>"
                            data-product-name="<?php echo esc_attr($product['name']); ?>"
                            data-product-type="<?php echo esc_attr($product['type']); ?>"
                            class="<?php echo ($product['calculated_stock'] ?? 0) <= $product['min_stock'] ? 'low-stock' : ''; ?>">
                            <td><strong><?php echo esc_html($product['name']); ?></strong></td>
                            <td class="cmv-col type-cell">
                                <span
                                    class="type-badge type-<?php echo esc_attr(strtolower(explode(',', $product['type'] ?? 'raw')[0])); ?>">
                                    <?php echo esc_html($type_label ?: '-'); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($product['sku']); ?></td>
                            <td>
                                <span
                                    class="stock-qty"><?php echo rtrim(rtrim(number_format($product['calculated_stock'] ?? 0, 3, ',', '.'), '0'), ','); ?></span>
                                <span class="stock-unit"><?php echo esc_html($product['unit']); ?></span>
                            </td>
                            <td class="cmv-col cost-cell">
                                <?php echo 'R$ ' . rtrim(rtrim(number_format($product['calculated_cost'] ?? 0, 2, ',', '.'), '0'), ','); ?>
                            </td>
                            <td class="cmv-col price-cell">
                                <?php echo 'R$ ' . rtrim(rtrim(number_format($product['selling_price'] ?? 0, 2, ',', '.'), '0'), ','); ?>
                            </td>
                            <td class="product-actions">
                                <?php if ($can_record_sale): ?>
                                    <button class="btn-quick-action sale" data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Venda', 'sistur'); ?>">
                                        <span class="dashicons dashicons-cart"></span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_request_loss): ?>
                                    <button class="btn-quick-action loss" data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Baixa', 'sistur'); ?>">
                                        <span class="dashicons dashicons-dismiss"></span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_cmv_manage_products): ?>
                                    <button class="btn-quick-action edit" data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Editar', 'sistur'); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_cmv_produce && $is_manufactured): ?>
                                    <button class="btn-quick-action produce"
                                        data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Produzir', 'sistur'); ?>">
                                        <span class="dashicons dashicons-hammer"></span>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_cmv_view_batches): ?>
                                    <button class="btn-quick-action batches"
                                        data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Lotes', 'sistur'); ?>">
                                        <span class="dashicons dashicons-list-view"></span>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- TAB: GENERALIZAÇÕES (Genéricos/Recursos) -->
        <div id="tab-stock-generics" class="stock-tab-content" style="display:none;">

            <?php if (!empty($children_missing_content) || !empty($children_missing_conversion) || !empty($cost_sanity_warnings)): ?>
                <div style="margin-bottom:14px;">

                    <?php if (!empty($children_missing_content)): ?>
                        <div
                            style="background:#fffbeb; border:1px solid #f59e0b; border-radius:6px; padding:10px 14px; margin-bottom:8px;">
                            <strong style="color:#92400e;">⚠️
                                <?php _e('Conteúdo da Embalagem não definido', 'sistur'); ?></strong>
                            <p style="margin:4px 0 6px; color:#78350f; font-size:0.85em;">
                                <?php _e('Os seguintes produtos filhos não têm "Conteúdo da Embalagem" configurado. O estoque e o custo médio da generalização estão sendo calculados com fator 1 por unidade, o que pode subestimar ou superestimar os valores reais:', 'sistur'); ?>
                            </p>
                            <ul style="margin:0; padding-left:18px; font-size:0.85em; color:#78350f;">
                                <?php foreach ($children_missing_content as $child): ?>
                                    <li><strong><?php echo esc_html($child['parent_name']); ?></strong> →
                                        <?php echo esc_html($child['name']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($children_missing_conversion)): ?>
                        <div
                            style="background:#fef2f2; border:1px solid #ef4444; border-radius:6px; padding:10px 14px; margin-bottom:8px;">
                            <strong style="color:#991b1b;">🚨 <?php _e('Conversão de Unidade ausente', 'sistur'); ?></strong>
                            <p style="margin:4px 0 6px; color:#7f1d1d; font-size:0.85em;">
                                <?php _e('Os produtos abaixo têm unidade de conteúdo diferente da unidade base da generalização, mas não possuem fator de conversão cadastrado. O sistema está usando fator 1, podendo misturar unidades (ex: gramas com quilogramas):', 'sistur'); ?>
                            </p>
                            <ul style="margin:0; padding-left:18px; font-size:0.85em; color:#7f1d1d;">
                                <?php foreach ($children_missing_conversion as $child): ?>
                                    <li>
                                        <strong><?php echo esc_html($child['parent_name']); ?></strong>
                                        (<?php echo esc_html($child['parent_unit']); ?>)
                                        → <?php echo esc_html($child['name']); ?>
                                        (<?php echo esc_html($child['child_unit']); ?>)
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($cost_sanity_warnings)): ?>
                        <div style="background:#eff6ff; border:1px solid #3b82f6; border-radius:6px; padding:10px 14px;">
                            <strong style="color:#1e40af;">ℹ️ <?php _e('Desvio de custo > 20% detectado', 'sistur'); ?></strong>
                            <p style="margin:4px 0 6px; color:#1e3a8a; font-size:0.85em;">
                                <?php _e('O custo vivo (baseado nos lotes ativos atuais) difere mais de 20% do custo médio histórico. Isso pode indicar erro no Conteúdo da Embalagem ou lotes antigos com preços muito discrepantes. Verifique os produtos marcados com ⚠️ na tabela abaixo:', 'sistur'); ?>
                            </p>
                            <ul style="margin:0; padding-left:18px; font-size:0.85em; color:#1e3a8a;">
                                <?php foreach ($cost_sanity_warnings as $w): ?>
                                    <li>
                                        <strong><?php echo esc_html($w['name']); ?></strong>:
                                        <?php _e('Vivo', 'sistur'); ?> R$ <?php echo number_format($w['live_cost'], 2, ',', '.'); ?>
                                        vs <?php _e('Histórico', 'sistur'); ?> R$
                                        <?php echo number_format($w['hist_cost'], 2, ',', '.'); ?>
                                        (<?php echo $w['deviation']; ?>% <?php _e('desvio', 'sistur'); ?>)
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>

            <table class="estoque-products-table">
                <thead>
                    <tr>
                        <th><?php _e('Generalização', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Tipo', 'sistur'); ?></th>
                        <th><?php _e('SKU', 'sistur'); ?></th>
                        <th><?php _e('Estoque Total', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Custo Médio', 'sistur'); ?></th>
                        <th class="cmv-col"><?php _e('Preço Venda', 'sistur'); ?></th>
                        <th><?php _e('Ações', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody id="estoque-generics-list">
                    <?php if (empty($products_generics)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 20px; color: #64748b;">
                                <?php _e('Nenhuma generalização encontrada.', 'sistur'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($products_generics as $product): ?>
                        <tr data-product-id="<?php echo esc_attr($product['id']); ?>"
                            data-product-name="<?php echo esc_attr($product['name']); ?>"
                            class="<?php echo ($product['calculated_stock'] ?? 0) <= $product['min_stock'] ? 'low-stock' : ''; ?>">
                            <td><strong><?php echo esc_html($product['name']); ?></strong></td>
                            <td class="cmv-col type-cell">
                                <span class="type-badge" style="background: #e2e8f0; color: #475569;">
                                    <?php _e('📦 Genérico', 'sistur'); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($product['sku']); ?></td>
                            <td>
                                <span
                                    class="stock-qty"><?php echo rtrim(rtrim(number_format($product['calculated_stock'] ?? 0, 3, ',', '.'), '0'), ','); ?></span>
                                <span class="stock-unit"><?php echo esc_html($product['unit']); ?></span>
                                <?php if (isset($missing_content_parent_ids[$product['id']])): ?>
                                    <span
                                        title="<?php esc_attr_e('Estoque pode estar incorreto: produto(s) filho(s) sem Conteúdo da Embalagem definido', 'sistur'); ?>"
                                        style="color:#f59e0b; cursor:help; margin-left:4px;">⚠️</span>
                                <?php elseif (isset($missing_conversion_parent_ids[$product['id']])): ?>
                                    <span
                                        title="<?php esc_attr_e('Estoque pode estar incorreto: conversão de unidade ausente em produto(s) filho(s)', 'sistur'); ?>"
                                        style="color:#ef4444; cursor:help; margin-left:4px;">🚨</span>
                                <?php endif; ?>
                            </td>
                            <td class="cmv-col cost-cell">
                                <?php echo 'R$ ' . rtrim(rtrim(number_format($product['calculated_cost'] ?? 0, 2, ',', '.'), '0'), ','); ?>
                                <?php if (isset($cost_sanity_warnings[$product['id']])): ?>
                                    <span title="<?php echo esc_attr(sprintf(
                                        __('Desvio de %s%% em relação ao custo histórico (R$ %s)', 'sistur'),
                                        $cost_sanity_warnings[$product['id']]['deviation'],
                                        number_format($cost_sanity_warnings[$product['id']]['hist_cost'], 2, ',', '.')
                                    )); ?>" style="color:#f59e0b; cursor:help; margin-left:4px;">⚠️</span>
                                <?php endif; ?>
                            </td>
                            <td class="cmv-col price-cell">
                                <?php echo 'R$ ' . rtrim(rtrim(number_format($product['selling_price'] ?? 0, 2, ',', '.'), '0'), ','); ?>
                            </td>
                            <td class="product-actions">
                                <?php if ($can_cmv_manage_products): ?>
                                    <button class="btn-quick-action edit" data-product="<?php echo esc_attr($product['id']); ?>"
                                        title="<?php _e('Editar', 'sistur'); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Transferência de Estoque (v2.10.0) -->
    <!-- Modal: Transferência de Estoque (v2.10.0) -->
    <div id="modal-transfer" class="estoque-modal" style="display: none;">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3>
                    <span class="dashicons dashicons-update" style="margin-right: 8px;"></span>
                    <?php _e('Transferência entre Estoques', 'sistur'); ?>
                </h3>
                <button type="button" class="modal-close">&times;</button>
            </div>

            <form id="form-transfer" class="estoque-form">
                <div class="form-group">
                    <div class="transfer-locations-grid"
                        style="display: grid; grid-template-columns: 1fr 40px 1fr; gap: 10px; align-items: start; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <!-- Source Column -->
                        <div>
                            <label
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php _e('Origem (Sai de)', 'sistur'); ?></label>

                            <!-- Main Location -->
                            <select id="transfer-source-location" class="regular-text"
                                style="width: 100%; border-color: #cbd5e1; margin-bottom: 8px;">
                                <option value=""><?php _e('Selecione o Local...', 'sistur'); ?></option>
                            </select>

                            <!-- Sector (Hidden by default) -->
                            <div id="wrapper-source-sector" style="display: none;">
                                <select id="transfer-source-sector" class="regular-text"
                                    style="width: 100%; border-color: #cbd5e1; font-size: 0.9em; color: #475569;">
                                    <option value=""><?php _e('Selecione o Setor...', 'sistur'); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Arrow Icon -->
                        <div style="text-align: center; color: #64748b; padding-top: 25px;">
                            <span class="dashicons dashicons-arrow-right-alt"
                                style="font-size: 30px; width: 30px; height: 30px;"></span>
                        </div>

                        <!-- Destination Column -->
                        <div>
                            <label
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php _e('Destino (Entra em)', 'sistur'); ?></label>

                            <!-- Main Location -->
                            <select id="transfer-dest-location" class="regular-text"
                                style="width: 100%; border-color: #cbd5e1; margin-bottom: 8px;">
                                <option value=""><?php _e('Selecione o Local...', 'sistur'); ?></option>
                            </select>

                            <!-- Sector (Hidden by default) -->
                            <div id="wrapper-dest-sector" style="display: none;">
                                <select id="transfer-dest-sector" class="regular-text"
                                    style="width: 100%; border-color: #cbd5e1; font-size: 0.9em; color: #475569;">
                                    <option value=""><?php _e('Selecione o Setor...', 'sistur'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group transfer-products-section">
                    <h4 style="margin: 0 0 10px 0; font-size: 1rem; color: #334155;">
                        <?php _e('Produtos a Transferir', 'sistur'); ?>
                    </h4>

                    <table class="wp-list-table widefat fixed striped"
                        style="border: 1px solid #e2e4e7; border-radius: 6px; overflow: hidden;">
                        <thead>
                            <tr>
                                <th><?php _e('Produto', 'sistur'); ?></th>
                                <th style="width: 120px;"><?php _e('Quantidade', 'sistur'); ?></th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="transfer-products-tbody">
                            <!-- Rows added via JS -->
                        </tbody>
                    </table>

                    <button type="button" class="button button-secondary" id="btn-add-transfer-product"
                        style="margin-top: 10px;">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                        <?php _e('Adicionar Produto', 'sistur'); ?>
                    </button>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                    <button type="submit" class="btn-submit" id="btn-confirm-transfer">
                        <span class="dashicons dashicons-yes" style="vertical-align: text-bottom;"></span>
                        <?php _e('Confirmar Transferência', 'sistur'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dados para o módulo de transferência
        var sisturStockData = {
            products: <?php echo json_encode($products); ?>,
            locations: <?php echo json_encode($locations); ?>,
            locationsHierarchy: <?php echo json_encode($locationsHierarchy); ?>
        };
    </script>
    <script>
        jQuery(document).ready(function ($) {
            // Controle de Tabs
            $('.stock-tab-btn').on('click', function () {
                var target = $(this).data('target');

                // Alterar estilo dos botões
                $('.stock-tab-btn').removeClass('active').css({
                    'border-bottom-color': 'transparent',
                    'color': '#64748b'
                });
                $(this).addClass('active').css({
                    'border-bottom-color': '#f59e0b',
                    'color': '#1e293b'
                });

                // Alternar conteúdo
                $('.stock-tab-content').hide();
                $('#' + target).fadeIn(200);
            });

            // Atualizar busca para ambas as tabelas
            $('#estoque-search').off('keyup').on('keyup', function () {
                var value = $(this).val().toLowerCase().trim();
                $('#estoque-products-list tr, #estoque-generics-list tr').filter(function () {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(value) > -1);
                });
            });
        });
    </script>

    <!-- Movimentações Recentes -->
    <?php if (!empty($recent_movements)): ?>
        <div class="estoque-history-section">
            <h3><?php _e('Minhas Movimentações Recentes', 'sistur'); ?></h3>
            <ul class="estoque-history-list">
                <?php foreach ($recent_movements as $mov): ?>
                    <li class="movement-<?php echo esc_attr($mov['type']); ?>">
                        <div class="movement-info">
                            <strong><?php echo esc_html($mov['product_name']); ?></strong>
                            <span class="movement-qty">
                                <?php echo $mov['type'] === 'entry' ? '+' : '-'; ?>
                                <?php echo esc_html($mov['quantity']); ?>
                            </span>
                        </div>
                        <span class="movement-time">
                            <?php echo esc_html(human_time_diff(strtotime($mov['created_at']), current_time('timestamp'))); ?>
                            atrás
                        </span>
                        <?php if (!empty($mov['requires_approval']) && $mov['requires_approval'] == 1): ?>
                            <span class="movement-status pending"><?php _e('Aguardando Aprovação', 'sistur'); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Registrar Venda (oculto em modo Macro) -->
<?php if (!$is_macro_sales_mode): ?>
    <div id="modal-sale" class="estoque-modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php _e('Registrar Venda', 'sistur'); ?></h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="form-sale" class="estoque-form">
                <input type="hidden" name="action" value="sistur_record_sale">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sistur_inventory_nonce'); ?>">

                <div class="form-group">
                    <label><?php _e('Produto', 'sistur'); ?></label>
                    <select name="product_id" required>
                        <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo esc_attr($p['id']); ?>">
                                <?php echo esc_html($p['name']); ?>
                                (<?php echo rtrim(rtrim(number_format($p['calculated_stock'] ?? 0, 3, ',', '.'), '0'), ','); ?>
                                <?php echo esc_html($p['unit']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><?php _e('Quantidade', 'sistur'); ?></label>
                    <input type="number" name="quantity" min="1" required>
                </div>

                <div class="form-group">
                    <label><?php _e('Observação (opcional)', 'sistur'); ?></label>
                    <textarea name="notes" rows="2"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                    <button type="submit" class="btn-submit"><?php _e('Confirmar Venda', 'sistur'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; // !$is_macro_sales_mode — fim do modal de venda por produto ?>

<!-- Modal: Solicitar Baixa -->
<div id="modal-loss" class="estoque-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php _e('Solicitar Baixa (Perda/Quebra)', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form id="form-loss" class="estoque-form">
            <input type="hidden" name="action" value="sistur_submit_stock_loss">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sistur_inventory_nonce'); ?>">

            <div class="form-group">
                <label><?php _e('Produto', 'sistur'); ?></label>
                <select name="product_id" required>
                    <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                    <?php foreach ($products_stock as $p): ?>
                        <option value="<?php echo esc_attr($p['id']); ?>">
                            <?php echo esc_html($p['name']); ?>
                            (<?php echo rtrim(rtrim(number_format($p['calculated_stock'] ?? 0, 3, ',', '.'), '0'), ','); ?>
                            <?php echo esc_html($p['unit']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label><?php _e('Tipo de Baixa', 'sistur'); ?></label>
                <select name="reason" required>
                    <option value="loss"><?php _e('Perda', 'sistur'); ?></option>
                    <option value="damage"><?php _e('Quebra/Avaria', 'sistur'); ?></option>
                    <option value="expiry"><?php _e('Vencimento', 'sistur'); ?></option>
                    <option value="other"><?php _e('Outro', 'sistur'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label><?php _e('Quantidade', 'sistur'); ?></label>
                <div class="input-group" style="display: flex; gap: 10px;">
                    <!-- Campo visível para o usuário (caixas/pacotes) -->
                    <input type="number" name="quantity_display" min="0.001" step="0.001" required placeholder="Qtd"
                        class="calc-qty">
                    <!-- Campo real enviado ao backend (unidades finais) -->
                    <input type="hidden" name="quantity" class="calc-total">

                    <div style="flex: 1;">
                        <input type="number" name="package_content" min="0.001" step="0.001"
                            placeholder="Conteúdo da Embalagem (opcional)" class="calc-content"
                            title="Se preenchido, a quantidade final será Qtd * Conteúdo">
                    </div>
                </div>
                <small style="color: #666; display: block; margin-top: 4px;" class="calc-preview"></small>
            </div>

            <div class="form-group">
                <label><?php _e('Justificativa', 'sistur'); ?></label>
                <textarea name="notes" rows="3" required
                    placeholder="<?php _e('Descreva o motivo da baixa...', 'sistur'); ?>"></textarea>
            </div>

            <div class="form-notice">
                <span class="dashicons dashicons-info"></span>
                <?php _e('Esta solicitação será enviada para aprovação do seu supervisor.', 'sistur'); ?>
            </div>

            <div class="form-actions">
                <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                <button type="submit" class="btn-submit"><?php _e('Enviar Solicitação', 'sistur'); ?></button>
            </div>
        </form>
    </div>
</div>



<!-- Modal: Gerenciar Locais -->
<div id="modal-locations" class="estoque-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php _e('Gerenciar Locais e Setores', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
            <!-- Form to add new location -->
            <form id="form-add-location" class="estoque-form"
                style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                <div class="form-row" style="display: flex; gap: 10px; align-items: flex-end;">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label><?php _e('Novo Local', 'sistur'); ?></label>
                        <input type="text" name="location_name" required
                            placeholder="<?php _e('Nome do local (ex: Dispensa, Freezer)', 'sistur'); ?>"
                            style="width: 100%;">
                    </div>
                    <button type="submit" class="button-primary"
                        style="height: 38px; display: flex; align-items: center; gap: 5px; background: #2271b1; color: white; border: none; padding: 0 15px; border-radius: 4px; cursor: pointer;">
                        <span class="dashicons dashicons-plus-alt2"></span> <?php _e('Adicionar', 'sistur'); ?>
                    </button>
                </div>
            </form>

            <!-- List of locations with sectors -->
            <div id="locations-list-container">
                <!-- Populated via JS -->
            </div>
        </div>
    </div>
</div>

<!-- Modal: Gerenciar Fornecedores (v2.17.0) -->
<div id="modal-suppliers" class="estoque-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?php _e('Gerenciar Fornecedores', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px; max-height:70vh; overflow-y:auto;">
            <!-- Form de adição -->
            <form id="form-add-supplier" class="estoque-form"
                style="margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid #eee;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group" style="flex:2; min-width:160px; margin-bottom:0;">
                        <label><?php _e('Nome do Fornecedor', 'sistur'); ?> *</label>
                        <input type="text" name="supplier_name" required
                            placeholder="<?php _e('Ex: Atacadão, Fornecedor Local', 'sistur'); ?>" style="width:100%;">
                    </div>
                    <div class="form-group" style="flex:1; min-width:120px; margin-bottom:0;">
                        <label><?php _e('CNPJ', 'sistur'); ?></label>
                        <input type="text" name="supplier_tax_id" placeholder="00.000.000/0001-00" style="width:100%;"
                            maxlength="18">
                    </div>
                    <div class="form-group" style="flex:2; min-width:160px; margin-bottom:0;">
                        <label><?php _e('Contato', 'sistur'); ?></label>
                        <input type="text" name="supplier_contact"
                            placeholder="<?php _e('Telefone, e-mail...', 'sistur'); ?>" style="width:100%;">
                    </div>
                    <button type="submit" class="button-primary" style="height:38px; display:flex; align-items:center; gap:5px;
                                   background:#2271b1; color:white; border:none;
                                   padding:0 15px; border-radius:4px; cursor:pointer; flex-shrink:0;">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php _e('Adicionar', 'sistur'); ?>
                    </button>
                </div>
            </form>
            <div id="suppliers-list-container">
                <div style="text-align:center; padding:20px; color:#64748b;">
                    <?php _e('Carregando...', 'sistur'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Visualizar por Local -->
<div id="modal-stock-by-location" class="estoque-modal" style="display:none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><?php _e('Estoque por Local', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: 80vh; overflow-y: auto;">
            <div id="stock-by-location-loading" style="text-align: center; padding: 40px; color: #64748b;">
                <span class="dashicons dashicons-update spin"></span>
                <p><?php _e('Carregando estoque...', 'sistur'); ?></p>
            </div>
            <div id="stock-by-location-content" style="display: none;">
                <!-- Content populated by JS -->
            </div>
        </div>
    </div>
</div>

<!-- Modal: Inventário Cego -->
<div id="modal-blind-inventory" class="estoque-modal" style="display:none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><?php _e('Inventário Cego', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: 75vh; overflow-y: auto;">

            <!-- VIEW 1: Sessions List -->
            <div id="bi-view-sessions">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h4 style="margin:0;">📋 <?php _e('Sessões de Inventário', 'sistur'); ?></h4>
                    <button id="bi-btn-new" class="button-primary"
                        style="display:flex; align-items:center; gap:6px; padding:6px 14px; background:#10b981; border:none; color:white; border-radius:6px; cursor:pointer; font-size:13px;">
                        <span class="dashicons dashicons-plus-alt2"></span> <?php _e('Nova Contagem', 'sistur'); ?>
                    </button>
                </div>

                <!-- New session form (hidden by default) -->
                <div id="bi-new-session-form"
                    style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px;">
                    <h4 style="margin:0 0 12px 0; font-size:14px;">🆕 <?php _e('Iniciar Nova Contagem', 'sistur'); ?>
                    </h4>
                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                        <div style="flex:1; min-width:180px;">
                            <label
                                style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;"><?php _e('Local', 'sistur'); ?></label>
                            <select id="bi-location-select"
                                style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:4px;">
                                <option value=""><?php _e('Todos os locais', 'sistur'); ?></option>
                            </select>
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <label
                                style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;"><?php _e('Setor', 'sistur'); ?></label>
                            <select id="bi-sector-select"
                                style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:4px;"
                                disabled>
                                <option value=""><?php _e('Todos os setores', 'sistur'); ?></option>
                            </select>
                        </div>
                        <div style="flex:1; min-width:180px;">
                            <label
                                style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;"><?php _e('Observações', 'sistur'); ?></label>
                            <input type="text" id="bi-notes" placeholder="<?php _e('Opcional...', 'sistur'); ?>"
                                style="width:100%; padding:6px 10px; border:1px solid #ddd; border-radius:4px;">
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button id="bi-confirm-new" class="button-primary"
                                style="padding:6px 14px; background:#2271b1; border:none; color:white; border-radius:4px; cursor:pointer;">
                                <?php _e('Iniciar', 'sistur'); ?>
                            </button>
                            <button id="bi-cancel-new"
                                style="padding:6px 14px; background:#f1f1f1; border:1px solid #ddd; border-radius:4px; cursor:pointer;">
                                <?php _e('Cancelar', 'sistur'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="bi-sessions-list">
                    <div style="text-align:center; padding:20px; color:#888;"><?php _e('Carregando...', 'sistur'); ?>
                    </div>
                </div>
            </div>

            <!-- VIEW 2: Active Counting -->
            <div id="bi-view-count" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div>
                        <h4 style="margin:0;">📝 <?php _e('Contagem em Andamento', 'sistur'); ?> - <span
                                id="bi-count-session-id"></span></h4>
                        <small id="bi-count-scope" style="color:#666;"></small>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button id="bi-save-progress"
                            style="padding:5px 12px; background:#f0f6fc; border:1px solid #c3d9f0; border-radius:4px; cursor:pointer; font-size:12px;">
                            💾 <?php _e('Salvar', 'sistur'); ?>
                        </button>
                        <button id="bi-wizard-next-sector"
                            style="display:none; padding:5px 12px; background:#2271b1; border:none; color:white; border-radius:4px; cursor:pointer; font-size:12px;">
                            ➡️ <?php _e('Próximo Setor', 'sistur'); ?>
                        </button>
                        <button id="bi-submit-count"
                            style="padding:5px 12px; background:#10b981; border:none; color:white; border-radius:4px; cursor:pointer; font-size:12px;">
                            ✅ <?php _e('Finalizar', 'sistur'); ?>
                        </button>
                        <button id="bi-back-to-sessions"
                            style="padding:5px 12px; background:#f1f1f1; border:1px solid #ddd; border-radius:4px; cursor:pointer; font-size:12px;">
                            ← <?php _e('Voltar', 'sistur'); ?>
                        </button>
                    </div>
                </div>

                <!-- Wizard Progress Indicator (v2.15.0) -->
                <div id="bi-wizard-progress"
                    style="display:none; padding:12px; background:#f0f6fc; border:1px solid #c3d9f0; border-radius:6px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div style="font-size:14px; font-weight:600; color:#1e293b;">
                            📍 <span id="bi-wizard-sector-label"></span>
                        </div>
                        <div style="font-size:12px; color:#64748b;">
                            <?php _e('Setor', 'sistur'); ?> <span id="bi-wizard-current-step">1</span>
                            <?php _e('de', 'sistur'); ?> <span id="bi-wizard-total-steps">1</span>
                        </div>
                    </div>
                    <div
                        style="width:100%; height:6px; background:#e0e0e0; border-radius:3px; overflow:hidden; margin-bottom:8px;">
                        <div id="bi-wizard-progress-fill"
                            style="height:100%; background:linear-gradient(90deg,#2271b1,#135e96); border-radius:3px; transition:width 0.4s ease; width:0%;">
                        </div>
                    </div>
                    <div id="bi-wizard-sectors-dots" style="display:flex; gap:4px; flex-wrap:wrap;"></div>
                </div>

                <div id="bi-count-progress"
                    style="background:#f0f6fc; border-radius:6px; padding:10px 14px; margin-bottom:12px; font-size:14px; font-weight:600;">
                    <span id="bi-items-filled">0</span> / <span id="bi-items-total">0</span>
                    <?php _e('itens contados', 'sistur'); ?>
                </div>

                <div style="margin-bottom:10px;">
                    <input type="text" id="bi-search-product"
                        placeholder="🔍 <?php _e('Filtrar produto...', 'sistur'); ?>"
                        style="width:100%; padding:8px 12px; border:1px solid #ddd; border-radius:6px; font-size:13px;">
                </div>

                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8f9fa; border-bottom:2px solid #e2e8f0;">
                            <th style="padding:8px 10px; text-align:left; font-size:13px;">
                                <?php _e('Produto', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:13px; width:80px;">
                                <?php _e('Unidade', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:13px; width:150px;">
                                <?php _e('Quantidade Física', 'sistur'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="bi-count-body"></tbody>
                </table>
            </div>

            <!-- VIEW 3: Report -->
            <div id="bi-view-report" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div>
                        <h4 style="margin:0;">📊 <?php _e('Relatório de Divergências', 'sistur'); ?> - <span
                                id="bi-report-session-id"></span></h4>
                        <small id="bi-report-scope" style="color:#666;"></small>
                    </div>
                    <button id="bi-report-back"
                        style="padding:5px 12px; background:#f1f1f1; border:1px solid #ddd; border-radius:4px; cursor:pointer; font-size:12px;">
                        ← <?php _e('Voltar', 'sistur'); ?>
                    </button>
                </div>

                <div id="bi-report-summary"
                    style="display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:16px;">
                </div>

                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8f9fa; border-bottom:2px solid #e2e8f0;">
                            <th style="padding:8px 10px; text-align:left; font-size:12px;">
                                <?php _e('Produto', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:12px;">
                                <?php _e('Teórico', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:12px;">
                                <?php _e('Físico', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:12px;">
                                <?php _e('Divergência', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:right; font-size:12px;">
                                <?php _e('Impacto R$', 'sistur'); ?>
                            </th>
                            <th style="padding:8px 10px; text-align:center; font-size:12px;">
                                <?php _e('Status', 'sistur'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="bi-report-body"></tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- Modal: Registrar Entradas em Lote -->
<div id="modal-entry" class="estoque-modal" style="display:none;">
    <div class="modal-content" style="max-width:920px; width:95vw;">
        <div class="modal-header">
            <h3><?php _e('Registrar Entradas', 'sistur'); ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form id="form-entry" class="estoque-form">
            <input type="hidden" name="action" value="sistur_save_bulk_movement">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sistur_inventory_nonce'); ?>">

            <!-- Campos globais -->
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                <div class="form-group" style="flex:2; min-width:240px; margin-bottom:0;">
                    <label><?php _e('Local de Armazenamento', 'sistur'); ?></label>
                    <div style="display:flex; gap:8px;">
                        <select id="entry-location-parent" name="parent_location_id" required style="flex:1;">
                            <option value=""><?php _e('Selecione o Local...', 'sistur'); ?></option>
                            <?php foreach ($locations_hierarchy as $loc): ?>
                                <option value="<?php echo esc_attr($loc['id']); ?>"
                                    data-sectors='<?php echo json_encode($loc['sectors']); ?>'>
                                    <?php echo esc_html($loc['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="entry-sector-wrapper" style="display:none; flex:1;">
                            <select id="entry-location-sector" name="location_id" disabled style="width:100%;">
                                <option value=""><?php _e('Selecione o Setor...', 'sistur'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="flex:1; min-width:180px; margin-bottom:0;">
                    <label><?php _e('Fornecedor', 'sistur'); ?></label>
                    <div style="display:flex; gap:6px; align-items:stretch;">
                        <div id="supplier-search-wrapper" style="flex:1; position:relative;">
                            <input type="text" id="entry-supplier-search"
                                placeholder="<?php _e('Buscar fornecedor...', 'sistur'); ?>" autocomplete="off"
                                style="width:100%;">
                            <input type="hidden" id="entry-supplier-id" name="supplier_id">
                            <div id="supplier-dropdown" style="display:none; position:absolute; top:100%; left:0; right:0;
                                        background:#fff; border:1px solid #d1d5db; border-radius:4px;
                                        max-height:200px; overflow-y:auto; z-index:1050;
                                        box-shadow:0 4px 6px rgba(0,0,0,.1);"></div>
                        </div>
                        <button type="button" id="btn-open-suppliers-from-entry"
                            title="<?php _e('Gerenciar Fornecedores', 'sistur'); ?>" style="flex-shrink:0; padding:0 10px; background:#f8f9fa;
                                       border:1px solid #d1d5db; border-radius:4px; cursor:pointer;
                                       font-size:16px; line-height:1;">+</button>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label><?php _e('Observação', 'sistur'); ?></label>
                <input type="text" id="entry-notes" name="notes"
                    placeholder="<?php _e('Observação geral para todas as entradas (opcional)', 'sistur'); ?>">
            </div>

            <!-- Tabela de linhas de produto -->
            <div style="overflow-x:auto; margin-bottom:4px;">
                <table id="entry-rows-table" style="width:100%; border-collapse:collapse; font-size:0.88em;">
                    <thead>
                        <tr style="background:#f3f4f6;">
                            <th style="padding:6px 8px; text-align:left; font-weight:600; min-width:170px;">
                                <?php _e('Produto', 'sistur'); ?></th>
                            <th style="padding:6px 8px; text-align:left; font-weight:600; width:75px;">
                                <?php _e('Qtd', 'sistur'); ?></th>
                            <th style="padding:6px 8px; text-align:left; font-weight:600; width:110px;">
                                <?php _e('Custo Unit.', 'sistur'); ?></th>
                            <th style="padding:6px 8px; text-align:left; font-weight:600; width:130px;">
                                <?php _e('Validade', 'sistur'); ?></th>
                            <th style="padding:6px 4px; width:24px;"></th>
                        </tr>
                    </thead>
                    <tbody id="entry-rows-body"></tbody>
                </table>
            </div>

            <!-- Template de linha (conteúdo inerte, renderizado pelo PHP no servidor) -->
            <template id="entry-row-template">
                <tr class="entry-row" style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:3px 8px;">
                        <select class="entry-product" style="width:100%; min-width:150px;">
                            <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                            <?php foreach ($products_stock as $p): ?>
                                <option value="<?php echo esc_attr($p['id']); ?>"><?php echo esc_html($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:3px 4px;">
                        <input type="number" class="entry-qty" min="0.001" step="0.001" placeholder="0"
                            style="width:100%;">
                    </td>
                    <td style="padding:3px 4px;">
                        <input type="number" class="entry-price" min="0" step="0.0001" placeholder="0,00"
                            style="width:100%;">
                    </td>
                    <td style="padding:3px 4px;">
                        <input type="date" class="entry-expiry" style="width:100%;">
                    </td>
                    <td style="padding:3px 4px; text-align:center;">
                        <button type="button" class="entry-remove-row"
                            title="<?php esc_attr_e('Remover linha', 'sistur'); ?>"
                            style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:1.2em; line-height:1; padding:0 4px;">&times;</button>
                    </td>
                </tr>
            </template>

            <div class="form-actions">
                <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                <button type="submit" class="btn-submit" id="entry-submit-btn" disabled>
                    <?php _e('Registrar Entradas', 'sistur'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($can_cmv_manage_products): ?>
    <!-- Modal: Novo/Editar Produto (CMV) -->
    <div id="modal-cmv-product" class="estoque-modal" style="display:none;">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3 id="modal-product-title"><?php _e('Novo Produto', 'sistur'); ?></h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="form-cmv-product" class="estoque-form">
                <input type="hidden" name="action" value="sistur_cmv_save_product">
                <input type="hidden" name="product_id" id="cmv-product-id" value="">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sistur_stock_nonce'); ?>">

                <!-- Tabs -->
                <div class="modal-tabs">
                    <button type="button" class="tab-btn active"
                        data-tab="tab-basic"><?php _e('Dados Básicos', 'sistur'); ?></button>
                    <?php if ($can_cmv_manage_recipes): ?>
                        <button type="button" class="tab-btn"
                            data-tab="tab-recipe"><?php _e('Composição/Receita', 'sistur'); ?></button>
                    <?php endif; ?>
                </div>

                <!-- Tab: Dados Básicos -->
                <div id="tab-basic" class="tab-content active">
                    <div class="form-row">
                        <div class="form-group">
                            <label><?php _e('Nome do Produto', 'sistur'); ?> *</label>
                            <input type="text" name="name" id="cmv-name" required>
                        </div>
                        <div class="form-group">
                            <label><?php _e('SKU/Código', 'sistur'); ?></label>
                            <input type="text" name="sku" id="cmv-sku">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><?php _e('Tipo do Produto', 'sistur'); ?> *</label>
                            <select name="type" id="cmv-type" required>
                                <option value="RESOURCE"><?php _e('📦 Generalização (ex: Arroz, Óleo)', 'sistur'); ?>
                                </option>
                                <option value="RAW"><?php _e('🥬 Insumo/Matéria-prima', 'sistur'); ?></option>
                                <option value="MANUFACTURED"><?php _e('🍳 Produzido', 'sistur'); ?></option>
                                <option value="BASE"><?php _e('🍲 Prato Base (produzido e reutilizável)', 'sistur'); ?>
                                </option>
                                <option value="RAW,MANUFACTURED"><?php _e('🥬🍳 Insumo + Produzido', 'sistur'); ?></option>
                                <option value="RESALE"><?php _e('🛒 Revenda', 'sistur'); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><?php _e('Unidade Base', 'sistur'); ?> *</label>
                            <select name="unit_id" id="cmv-unit" required>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo esc_attr($unit['id']); ?>">
                                        <?php echo esc_html($unit['name']); ?> (<?php echo esc_html($unit['abbreviation']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><?php _e('Estoque Mínimo', 'sistur'); ?></label>
                            <input type="number" name="min_stock" id="cmv-min-stock" min="0" step="0.01" value="0">
                        </div>
                        <div class="form-group">
                            <label><?php _e('Preço de Venda', 'sistur'); ?></label>
                            <input type="number" name="selling_price" id="cmv-selling-price" min="0" step="0.01">
                        </div>
                    </div>

                    <div class="form-row" id="row-resource-parent">
                        <div class="form-group" style="flex: 1;">
                            <label>
                                <?php _e('Vincular a Generalização (opcional)', 'sistur'); ?>
                                <small class="text-muted"
                                    title="Vincule este produto a um item genérico (ex: Vinagre Allegro -> Vinagre)">?</small>
                            </label>
                            <select name="resource_parent_id" id="cmv-resource-parent" class="form-control">
                                <option value=""><?php _e('Nenhum vínculo', 'sistur'); ?></option>
                                <?php if (!empty($products_generics)): ?>
                                    <?php foreach ($products_generics as $res): ?>
                                        <option value="<?php echo esc_attr($res['id']); ?>">
                                            <?php echo esc_html($res['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- v2.13.0 - Checkbox Não Perecível (Apenas para Generalizações) -->
                    <div class="form-row" id="row-is-perishable" style="display:none;">
                        <input type="hidden" name="is_perishable" value="1">
                        <!-- Default value if unchecked logic fails, but we will handle logic -->
                        <div class="form-group"
                            style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; width: 100%;">
                            <input type="checkbox" id="cmv-is-perishable" value="0" style="width: auto; margin: 0;">
                            <div>
                                <label for="cmv-is-perishable" style="margin: 0; font-weight: 600; cursor: pointer;">
                                    <?php _e('Produto Não Perecível / Sem Validade', 'sistur'); ?>
                                </label>
                                <small style="display: block; color: #64748b; margin-top: 2px;">
                                    <?php _e('Marque esta opção para itens que não possuem data de validade (ex: Itens de limpeza, descartáveis, utensílios).', 'sistur'); ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <?php _e('Conteúdo da Embalagem', 'sistur'); ?>
                            </label>
                            <input type="number" name="content_quantity" id="cmv-content-quantity" min="0" step="0.001"
                                placeholder="Ex: 500 (para pacote de 500g)"
                                title="Quanto vem na embalagem. Ex: Pacote 1kg = 1000 gramas">
                        </div>
                        <div class="form-group">
                            <label>
                                <?php _e('Unidade do Conteúdo', 'sistur'); ?>
                            </label>
                            <select name="content_unit_id" id="cmv-content-unit">
                                <option value="">
                                    <?php _e('Selecione...', 'sistur'); ?>
                                </option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo esc_attr($unit['id']); ?>">
                                        <?php echo esc_html($unit['name']); ?> (
                                        <?php echo esc_html($unit['abbreviation']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tab: Composição/Receita (só para MANUFACTURED) -->
                <?php if ($can_cmv_manage_recipes): ?>
                    <div id="tab-recipe" class="tab-content" style="display:none;">
                        <div class="recipe-info-box">
                            <span class="dashicons dashicons-info"></span>
                            <?php _e('Defina os ingredientes necessários para produzir este item.', 'sistur'); ?>
                        </div>

                        <div class="ingredient-search-row">
                            <select id="cmv-ingredient-select">
                                <option value=""><?php _e('Selecione um ingrediente...', 'sistur'); ?></option>
                                <?php
                                $generic_resources = array();
                                $base_dishes = array();
                                $raw_ingredients = array();

                                foreach ($products as $p) {
                                    if (strpos($p['type'], 'RESOURCE') !== false) {
                                        $generic_resources[] = $p;
                                    } elseif (strpos($p['type'], 'BASE') !== false) {
                                        $base_dishes[] = $p;
                                    } elseif (strpos($p['type'], 'RAW') !== false) {
                                        $raw_ingredients[] = $p;
                                    }
                                }
                                ?>

                                <?php if (!empty($generic_resources)): ?>
                                    <optgroup label="<?php _e('📦 Generalizações', 'sistur'); ?>">
                                        <?php foreach ($generic_resources as $p): ?>
                                            <option value="<?php echo esc_attr($p['id']); ?>"
                                                data-unit="<?php echo esc_attr($p['unit']); ?>"
                                                data-unit-id="<?php echo esc_attr($p['base_unit_id']); ?>">
                                                <?php echo esc_html($p['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (!empty($base_dishes)): ?>
                                    <optgroup label="<?php _e('🍲 Pratos Base', 'sistur'); ?>">
                                        <?php foreach ($base_dishes as $p): ?>
                                            <option value="<?php echo esc_attr($p['id']); ?>"
                                                data-unit="<?php echo esc_attr($p['unit']); ?>"
                                                data-unit-id="<?php echo esc_attr($p['base_unit_id']); ?>">
                                                <?php echo esc_html($p['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>

                                <?php if (empty($generic_resources) && empty($base_dishes)): ?>
                                    <option value="" disabled><?php _e('Nenhum ingrediente disponível', 'sistur'); ?></option>
                                <?php endif; ?>
                                <!-- Ingredientes: RESOURCE (Generalizações) ou BASE (Pratos Base) -->
                            </select>
                            <input type="number" id="cmv-ingredient-qty" placeholder="<?php _e('Qtd', 'sistur'); ?>" min="0.001"
                                step="0.001">
                            <select id="cmv-ingredient-unit" style="max-width: 100px; display:none;">
                                <!-- Preenchido via JS -->
                            </select>
                            <button type="button" id="btn-add-ingredient" class="btn-add">
                                <span class="dashicons dashicons-plus"></span>
                            </button>
                        </div>

                        <table class="recipe-ingredients-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Ingrediente', 'sistur'); ?></th>
                                    <th><?php _e('Quantidade', 'sistur'); ?></th>
                                    <th><?php _e('Custo', 'sistur'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="cmv-ingredients-list">
                                <!-- Preenchido via JS -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2"><strong><?php _e('Custo Total da Receita', 'sistur'); ?></strong></td>
                                    <td colspan="2"><strong id="cmv-recipe-total-cost">R$ 0,00</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                    <button type="submit" class="btn-submit"><?php _e('Salvar Produto', 'sistur'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($can_cmv_produce): ?>
    <!-- Modal: Produção (CMV) -->
    <div id="modal-cmv-produce" class="estoque-modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><?php _e('Produzir Item', 'sistur'); ?></h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="form-cmv-produce" class="estoque-form">
                <input type="hidden" name="action" value="sistur_cmv_produce">
                <input type="hidden" name="product_id" id="produce-product-id" value="">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('sistur_stock_nonce'); ?>">

                <div class="produce-product-info">
                    <h4 id="produce-product-name">-</h4>
                    <span class="produce-current-stock">
                        <?php _e('Estoque atual:', 'sistur'); ?> <strong id="produce-current-qty">0</strong>
                    </span>
                </div>

                <div class="form-group">
                    <label><?php _e('Quantidade a Produzir', 'sistur'); ?> *</label>
                    <input type="number" name="quantity" id="produce-qty" min="1" step="1" value="1" required>
                </div>

                <div class="produce-ingredients-section">
                    <h5><?php _e('Ingredientes Necessários', 'sistur'); ?></h5>
                    <table class="produce-ingredients-table">
                        <thead>
                            <tr>
                                <th><?php _e('Ingrediente', 'sistur'); ?></th>
                                <th><?php _e('Necessário', 'sistur'); ?></th>
                                <th><?php _e('Disponível', 'sistur'); ?></th>
                                <th><?php _e('Status', 'sistur'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="produce-ingredients-list">
                            <!-- Preenchido via JS -->
                        </tbody>
                    </table>
                </div>

                <div class="produce-cost-summary">
                    <div class="cost-item">
                        <span><?php _e('Custo por unidade:', 'sistur'); ?></span>
                        <strong id="produce-unit-cost">R$ 0,00</strong>
                    </div>
                    <div class="cost-item total">
                        <span><?php _e('Custo total:', 'sistur'); ?></span>
                        <strong id="produce-total-cost">R$ 0,00</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label><?php _e('Observações', 'sistur'); ?></label>
                    <textarea name="notes" rows="2"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
                    <button type="submit" class="btn-submit btn-produce" id="btn-execute-produce">
                        <span class="dashicons dashicons-hammer"></span>
                        <?php _e('Produzir Agora', 'sistur'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($can_cmv_view_batches): ?>
    <!-- Modal: Visualizar Lotes (CMV) -->
    <div id="modal-cmv-batches" class="estoque-modal" style="display:none;">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><?php _e('Lotes do Produto', 'sistur'); ?></h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="batches-product-info">
                <h4 id="batches-product-name">-</h4>
                <span class="batches-total-stock">
                    <?php _e('Estoque total:', 'sistur'); ?> <strong id="batches-total-qty">0</strong>
                </span>
            </div>

            <table class="batches-table">
                <thead>
                    <tr>
                        <th><?php _e('Lote', 'sistur'); ?></th>
                        <th><?php _e('Local', 'sistur'); ?></th>
                        <th><?php _e('Validade', 'sistur'); ?></th>
                        <th><?php _e('Quantidade', 'sistur'); ?></th>
                        <th><?php _e('Custo Unit.', 'sistur'); ?></th>
                        <th><?php _e('Status', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody id="batches-list">
                    <!-- Preenchido via JS -->
                </tbody>
            </table>

            <div class="form-actions">
                <button type="button" class="btn-cancel"><?php _e('Fechar', 'sistur'); ?></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    var sisturStockData = {
        products: <?php echo json_encode($products); ?>,
        locations: <?php echo json_encode($locations); ?>,
        locationsHierarchy: <?php echo json_encode($locations_hierarchy); ?>,
        locationProducts: <?php echo json_encode($location_products_map); ?>
    };

    (function ($) {
        var sisturUnits = <?php echo json_encode($units); ?>;

        // Busca de produtos
        $('#estoque-search').on('input', function () {
            var term = $(this).val().toLowerCase();
            $('#estoque-products-list tr').each(function () {
                var name = $(this).data('product-name').toLowerCase();
                $(this).toggle(name.indexOf(term) !== -1);
            });
        });

        // Abrir modal
        $('.estoque-action-btn[data-modal]').on('click', function () {
            var modalId = $(this).data('modal');
            $('#' + modalId).fadeIn(200);
        });

        // Ação rápida na tabela (Apenas Vanda e Baixa - outros têm handlers específicos)
        $('.btn-quick-action.sale, .btn-quick-action.loss').on('click', function () {
            var productId = $(this).data('product');
            var modalId = $(this).hasClass('sale') ? 'modal-sale' : 'modal-loss';

            $('#' + modalId).fadeIn(200);
            $('#' + modalId + ' select[name="product_id"]').val(productId);
        });

        // Fechar modal
        $('.modal-close, .btn-cancel').on('click', function () {
            $(this).closest('.estoque-modal').fadeOut(200);
        });

        // Fechar ao clicar fora
        $('.estoque-modal').on('click', function (e) {
            if (e.target === this) {
                $(this).fadeOut(200);
            }
        });

        // Submeter formulários
        $('.estoque-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.btn-submit');
            var originalText = $btn.data('original-text') || $btn.text(); // Fix: save original text properly
            $btn.data('original-text', originalText);

            $btn.prop('disabled', true).text('<?php _e('Processando...', 'sistur'); ?>');

            // Ensure hidden quantity fields are populated before submit
            $form.find('.calc-qty').each(function () {
                var $container = $(this).closest('.form-group');
                var qty = parseFloat($(this).val()) || 0;
                var content = parseFloat($container.find('.calc-content').val()) || 0;
                var multiplier = content > 0 ? content : 1;
                var total = qty * multiplier;
                $container.find('.calc-total').val(total > 0 ? total : qty);
            });

            // DEBUG: Check data before sending
            var formData = $form.serializeArray();
            var hasAction = false;
            for (var i = 0; i < formData.length; i++) {
                if (formData[i].name === 'action') {
                    hasAction = true;
                    console.log('SISTUR DEBUG: Action found:', formData[i].value);
                    break;
                }
            }

            if (!hasAction) {
                console.warn('SISTUR DEBUG: Action missing in serialize! Trying manual fetch.');
                var actionVal = $form.find('input[name="action"]').val();
                if (actionVal) {
                    formData.push({ name: 'action', value: actionVal });
                    console.log('SISTUR DEBUG: Manually added action:', actionVal);
                } else {
                    console.error('SISTUR ERROR: Action input not found in form.');
                    alert('Erro interno: Ação não definida no formulário.');
                    $btn.prop('disabled', false).text(originalText);
                    return;
                }
            }

            // Mapear checkbox "não perecível" para o campo hidden is_perishable
            // Se checkbox checked (val 0) => is_perishable = 0
            // Se checkbox unchecked => is_perishable = 1
            if ($('#cmv-is-perishable').is(':checked')) {
                formData.push({ name: 'is_perishable', value: '0' });
            } else {
                formData.push({ name: 'is_perishable', value: '1' });
            }

            console.log('SISTUR DEBUG: Sending AJAX data:', formData);

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: $.param(formData),
                success: function (response) {
                    console.log('SISTUR DEBUG: Success response:', response);
                    if (response.success) {
                        alert(response.data.message || '<?php _e('Operação realizada com sucesso!', 'sistur'); ?>');
                        $form.closest('.estoque-modal').fadeOut(200);
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php _e('Erro ao processar', 'sistur'); ?>');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('SISTUR DEBUG: AJAX Error:', status, error, xhr.responseText);
                    var msg = '<?php _e('Erro de conexão', 'sistur'); ?>';
                    if (xhr.status === 400) {
                        msg += ' (400 Bad Request - Dados inválidos)';
                    } else if (xhr.status === 403) {
                        msg += ' (403 Forbidden - Permissão negada)';
                    } else if (xhr.status === 500) {
                        msg += ' (500 Internal Server Error)';
                    }
                    alert(msg + '. Veja o console para mais detalhes.');
                },
                complete: function () {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // ========== CMV Features ==========

        // Abrir modal de novo produto
        $('#btn-new-product-portal').on('click', function () {
            $('#modal-product-title').text('<?php _e('Novo Produto', 'sistur'); ?>');
            $('#form-cmv-product')[0].reset();
            $('#cmv-product-id').val('');
            $('#cmv-ingredients-list').empty();
            $('#cmv-is-perishable').prop('checked', false); // Default: Perecível (Unchecked because "Não Perecível" is the label)
            updateRecipeTotalCost();
            showTab('tab-basic');
            $('#modal-cmv-product').fadeIn(200);
        });

        // Editar produto - botão na tabela
        $('.btn-quick-action.edit').on('click', function () {
            var productId = $(this).data('product');
            openEditProductModal(productId);
        });

        // Produzir - botão na tabela
        $('.btn-quick-action.produce').on('click', function () {
            var productId = $(this).data('product');
            openProduceModal(productId);
        });

        // Botão produzir global
        $('#btn-produce-portal').on('click', function () {
            // Abrir modal de seleção de produto para produzir
            var manufacturedProducts = [];
            $('#estoque-products-list tr').each(function () {
                var pType = $(this).data('product-type') || '';
                if (pType.indexOf('MANUFACTURED') !== -1 || pType.indexOf('BASE') !== -1) {
                    manufacturedProducts.push({
                        id: $(this).data('product-id'),
                        name: $(this).data('product-name')
                    });
                }
            });

            if (manufacturedProducts.length === 0) {
                alert('<?php _e('Nenhum produto MANUFACTURED ou BASE encontrado.', 'sistur'); ?>');
                return;
            }

            // Se só tem um, abre direto
            if (manufacturedProducts.length === 1) {
                openProduceModal(manufacturedProducts[0].id);
            } else {
                // Cria um prompt simples para escolher
                var options = manufacturedProducts.map(function (p) { return p.name; }).join('\n');
                var choice = prompt('<?php _e('Escolha o produto para produzir:', 'sistur'); ?>\n\n' + options);
                if (choice) {
                    var selected = manufacturedProducts.find(function (p) { return p.name === choice; });
                    if (selected) {
                        openProduceModal(selected.id);
                    }
                }
            }
        });

        // Ver lotes - botão na tabela
        $('.btn-quick-action.batches').on('click', function () {
            var productId = $(this).data('product');
            openBatchesModal(productId);
        });

        // Tabs no modal de produto
        $('.tab-btn').on('click', function () {
            var tabId = $(this).data('tab');
            showTab(tabId);
        });

        function showTab(tabId) {
            $('.tab-btn').removeClass('active');
            $('.tab-btn[data-tab="' + tabId + '"]').addClass('active');
            $('.tab-content').hide();
            $('#' + tabId).show();
        }

        // Abrir modal de edição de produto
        function openEditProductModal(productId) {
            $('#modal-product-title').text('<?php _e('Editar Produto', 'sistur'); ?>');

            // Buscar dados do produto via API
            $.ajax({
                url: '<?php echo rest_url('sistur/v1/stock/products/'); ?>' + productId,
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                success: function (product) {
                    $('#cmv-product-id').val(product.id);
                    $('#cmv-name').val(product.name);
                    $('#cmv-sku').val(product.sku);
                    $('#cmv-type').val(product.type);
                    // Tentar diferentes nomes de propriedade para unidade
                    var unitVal = product.base_unit_id || product.unit_id || '';
                    $('#cmv-unit').val(unitVal);
                    // Console log para debug caso falhe
                    if (!unitVal) console.warn('DEBUG: Unidade base não encontrada no objeto:', product);
                    $('#cmv-min-stock').val(product.min_stock);
                    $('#cmv-selling-price').val(product.selling_price);

                    // Carregar conteúdo da embalagem
                    $('#cmv-content-quantity').val(product.content_quantity || '');
                    $('#cmv-content-unit').val(product.content_unit_id || '');

                    // Carregar vínculo com generalização
                    $('#cmv-resource-parent').val(product.resource_parent_id || '');

                    // Carregar perecível (backend 1=perecível, 0=não)
                    // Checkbox value=0 (Não perecível). Se back=0, check. Se back=1, uncheck.
                    var isPerishable = parseInt(product.is_perishable);
                    if (isNaN(isPerishable)) isPerishable = 1; // Default true

                    // Se isPerishable == 0 (FALSE), então marcamos o checkbox "NÃO PERECÍVEL"
                    $('#cmv-is-perishable').prop('checked', isPerishable === 0);

                    // Carregar ingredientes se for MANUFACTURED ou BASE
                    if (product.type && (product.type.indexOf('MANUFACTURED') !== -1 || product.type.indexOf('BASE') !== -1)) {
                        loadProductIngredients(productId);
                    }

                    showTab('tab-basic');

                    // Reset field visibility based on type
                    toggleFieldsByType();
                    toggleRecipeTab();

                    $('#modal-cmv-product').fadeIn(200);
                },
                error: function () {
                    alert('<?php _e('Erro ao carregar produto', 'sistur'); ?>');
                }
            });
        }

        // Carregar ingredientes do produto
        function loadProductIngredients(productId) {
            $.ajax({
                url: '<?php echo rest_url('sistur/v1/recipes/'); ?>' + productId,
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                success: function (recipe) {
                    $('#cmv-ingredients-list').empty();
                    // API returns array directly, not wrapped in .ingredients
                    // API fields: child_product_id, ingredient_name, quantity_net (or quantity_gross), unit_symbol, unit_id
                    var ingredients = Array.isArray(recipe) ? recipe : (recipe.ingredients || []);
                    ingredients.forEach(function (ing) {
                        // Map API field names to expected format
                        var ingId = ing.child_product_id || ing.ingredient_id;
                        var qty = ing.quantity_net || ing.quantity_gross || ing.quantity || 0;
                        var unitSymbol = ing.unit_symbol || ing.unit_name || ing.unit || '';
                        var unitId = ing.unit_id || '';
                        var ingCost = (parseFloat(ing.average_cost || ing.cost_price || 0) * parseFloat(qty)).toFixed(2);
                        addIngredientRow(ingId, ing.ingredient_name, qty, unitSymbol, unitId, ingCost);
                    });
                    updateRecipeTotalCost();
                }
            });
        }

        // Atualizar unidades ao selecionar ingrediente
        $('#cmv-ingredient-select').on('change', function () {
            var $option = $(this).find('option:selected');
            var baseUnitId = $option.data('unit-id');
            var $unitSelect = $('#cmv-ingredient-unit');

            if (!baseUnitId) {
                $unitSelect.hide();
                return;
            }

            // Encontrar o tipo da unidade base
            var baseUnit = sisturUnits.find(function (u) { return u.id == baseUnitId; });

            if (baseUnit) {
                // Filtrar unidades do mesmo tipo (measure_type: mass, volume, unitary)
                var compatibleUnits = sisturUnits.filter(function (u) {
                    return u.measure_type === baseUnit.measure_type;
                });

                $unitSelect.empty();
                compatibleUnits.forEach(function (u) {
                    var selected = u.id == baseUnitId ? 'selected' : '';
                    $unitSelect.append('<option value="' + u.abbreviation + '" data-id="' + u.id + '" ' + selected + '>' + u.abbreviation + '</option>');
                });

                $unitSelect.show();
            } else {
                $unitSelect.hide();
            }
        });

        // Adicionar ingrediente
        $('#btn-add-ingredient').on('click', function () {
            var ingredientId = $('#cmv-ingredient-select').val();
            var qty = $('#cmv-ingredient-qty').val();

            if (!ingredientId || !qty) {
                alert('<?php _e('Selecione um ingrediente e informe a quantidade', 'sistur'); ?>');
                return;
            }

            var ingredientName = $('#cmv-ingredient-select option:selected').text();

            // Tentar pegar do seletor de unidade, senão fallback para o padrão
            var ingredientUnit, ingredientUnitId;

            if ($('#cmv-ingredient-unit').is(':visible')) {
                ingredientUnit = $('#cmv-ingredient-unit').val();
                ingredientUnitId = $('#cmv-ingredient-unit option:selected').data('id');
            } else {
                ingredientUnit = $('#cmv-ingredient-select option:selected').data('unit');
                ingredientUnitId = $('#cmv-ingredient-select option:selected').data('unit-id');
            }

            addIngredientRow(ingredientId, ingredientName, qty, ingredientUnit, ingredientUnitId, 0);

            $('#cmv-ingredient-select').val('').trigger('change');
            $('#cmv-ingredient-qty').val('');
        });

        function addIngredientRow(id, name, qty, unit, unitId, cost) {
            var row = '<tr data-ingredient-id="' + id + '">' +
                '<td>' + name +
                '<input type="hidden" name="ingredients[' + id + '][id]" value="' + id + '">' +
                '<input type="hidden" name="ingredients[' + id + '][unit_id]" value="' + (unitId || '') + '">' +
                '</td>' +
                '<td>' + qty + ' ' + unit + '<input type="hidden" name="ingredients[' + id + '][qty]" value="' + qty + '"></td>' +
                '<td class="ing-cost">R$ ' + parseFloat(cost || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + '</td>' +
                '<td><button type="button" class="btn-remove-ingredient" data-id="' + id + '">&times;</button></td>' +
                '</tr>';
            $('#cmv-ingredients-list').append(row);
        }

        // Remover ingrediente
        $(document).on('click', '.btn-remove-ingredient', function () {
            $(this).closest('tr').remove();
            updateRecipeTotalCost();
        });

        function updateRecipeTotalCost() {
            var total = 0;
            $('#cmv-ingredients-list tr').each(function () {
                var costText = $(this).find('.ing-cost').text().replace('R$ ', '').replace(',', '.');
                total += parseFloat(costText) || 0;
            });
            $('#cmv-recipe-total-cost').text('R$ ' + total.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }));
        }

        // Abrir modal de produção
        function openProduceModal(productId) {
            $('#produce-product-id').val(productId);

            var $row = $('tr[data-product-id="' + productId + '"]');
            var productName = $row.data('product-name');
            var currentStock = $row.find('.stock-qty').text();

            $('#produce-product-name').text(productName);
            $('#produce-current-qty').text(currentStock);
            $('#produce-qty').val(1);

            // Carregar ingredientes da receita
            $.ajax({
                url: '<?php echo rest_url('sistur/v1/recipes/'); ?>' + productId,
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                success: function (recipe) {
                    $('#produce-ingredients-list').empty();
                    var unitCost = 0;

                    // API pode retornar array diretamente ou objeto com .ingredients
                    var ingredients = Array.isArray(recipe) ? recipe : (recipe.ingredients || []);

                    if (ingredients.length > 0) {
                        ingredients.forEach(function (ing) {
                            var available = parseFloat(ing.available_stock) || 0;
                            // Usar campos corretos da API
                            var required = parseFloat(ing.quantity_net || ing.quantity || 0);
                            var ingName = ing.ingredient_name || ing.name || 'Ingrediente';
                            var ingUnit = ing.unit_symbol || ing.unit || '';

                            var status = available >= required ?
                                '<span class="status-ok">✓ OK</span>' :
                                '<span class="status-error">✗ Insuficiente</span>';

                            var row = '<tr>' +
                                '<td>' + ingName + '</td>' +
                                '<td>' + required.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }) + ' ' + ingUnit + '</td>' +
                                '<td>' + available.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }) + ' ' + ingUnit + '</td>' +
                                '<td>' + status + '</td>' +
                                '</tr>';
                            $('#produce-ingredients-list').append(row);
                            unitCost += parseFloat(ing.cost || ing.average_cost || 0) * required;
                        });
                    }

                    $('#produce-unit-cost').text('R$ ' + unitCost.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }));
                    updateProductionTotalCost();

                    $('#modal-cmv-produce').fadeIn(200);
                },
                error: function () {
                    alert('<?php _e('Erro ao carregar receita', 'sistur'); ?>');
                }
            });
        }

        // Atualizar custo total ao mudar quantidade
        $('#produce-qty').on('input', function () {
            updateProductionTotalCost();
        });

        function updateProductionTotalCost() {
            var qty = parseFloat($('#produce-qty').val()) || 0;
            var unitCostText = $('#produce-unit-cost').text().replace('R$ ', '').replace(',', '.');
            var unitCost = parseFloat(unitCostText) || 0;
            var total = qty * unitCost;
            $('#produce-total-cost').text('R$ ' + total.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }));
        }

        // Abrir modal de lotes
        function openBatchesModal(productId) {
            var $row = $('tr[data-product-id="' + productId + '"]');
            var productName = $row.data('product-name');
            var totalStock = $row.find('.stock-qty').text();

            $('#batches-product-name').text(productName);
            $('#batches-total-qty').text(totalStock);

            // Carregar lotes via API
            $.ajax({
                url: '<?php echo rest_url('sistur/v1/stock/batches/'); ?>' + productId,
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                success: function (batches) {
                    $('#batches-list').empty();

                    if (batches && batches.length > 0) {
                        batches.forEach(function (batch) {
                            var statusClass = batch.status === 'active' ? 'status-active' :
                                (batch.status === 'exhausted' ? 'status-exhausted' : 'status-expired');
                            var statusLabel = batch.status === 'active' ? '<?php _e('Ativo', 'sistur'); ?>' :
                                (batch.status === 'exhausted' ? '<?php _e('Esgotado', 'sistur'); ?>' : '<?php _e('Expirado', 'sistur'); ?>');

                            var row = '<tr class="' + statusClass + '">' +
                                '<td>' + (batch.batch_number || '-') + '</td>' +
                                '<td>' + (batch.location_name || '-') + '</td>' +
                                '<td>' + (batch.expiry_date || '-') + '</td>' +
                                '<td>' + parseFloat(batch.quantity).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 }) + '</td>' +
                                '<td>R$ ' + parseFloat(batch.cost_price || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + '</td>' +
                                '<td><span class="batch-status ' + statusClass + '">' + statusLabel + '</span></td>' +
                                '</tr>';
                            $('#batches-list').append(row);
                        });
                    } else {
                        $('#batches-list').append('<tr><td colspan="6" style="text-align:center"><?php _e('Nenhum lote encontrado', 'sistur'); ?></td></tr>');
                    }

                    $('#modal-cmv-batches').fadeIn(200);
                },
                error: function () {
                    alert('<?php _e('Erro ao carregar lotes', 'sistur'); ?>');
                }
            });
        }
        // Cálculo de conteúdo da embalagem (Portal)
        $('.calc-qty, .calc-content').on('input', function () {
            var $container = $(this).closest('.form-group');
            var $qtyDisplay = $container.find('.calc-qty');
            var $content = $container.find('.calc-content');
            var $total = $container.find('.calc-total');
            var $preview = $container.find('.calc-preview');

            var qty = parseFloat($qtyDisplay.val()) || 0;
            var content = parseFloat($content.val()) || 0;

            // Se conteúdo não for preenchido ou for 0, usa 1 (comportamento padrão)
            var multiplier = content > 0 ? content : 1;

            var total = qty * multiplier;

            // Atualiza input hidden
            $total.val(total > 0 ? total : '');

            // Mostra preview se houver conteúdo definido
            if (content > 0 && qty > 0) {
                $preview.html('Total: <strong>' + total.toLocaleString('pt-BR') + '</strong> ( ' + qty + ' x ' + content + ' )');
            } else {
                $preview.empty();
            }
        });

        // Inicializar cálculos ao abrir modal (caso reset)
        $('.estoque-modal').on('fadeIn', function () {
            $(this).find('.calc-preview').empty();
            $(this).find('.calc-total').val('');
        });

        // Toggle Tab Receita
        function toggleRecipeTab() {
            var type = $('#cmv-type').val() || '';
            // Mostrar aba Receita para MANUFACTURED e BASE (ambos têm receitas)
            if (type.indexOf('MANUFACTURED') !== -1 || type.indexOf('BASE') !== -1) {
                $('.tab-btn[data-tab="tab-recipe"]').show();
            } else {
                $('.tab-btn[data-tab="tab-recipe"]').hide();
                // Se estiver na tab receita, voltar para basic
                if ($('.tab-btn[data-tab="tab-recipe"]').hasClass('active')) {
                    $('.tab-btn[data-tab="tab-basic"]').click();
                }
            }
        }

        // Toggle Campos por Tipo (Customização Geral)
        function toggleFieldsByType() {
            try {
                var type = $('#cmv-type').val() || '';

                var $skuGroup = $('#cmv-sku').closest('.form-group');
                var $sellingGroup = $('#cmv-selling-price').closest('.form-group'); // Preço Venda
                var $contentRow = $('#cmv-content-quantity').closest('.form-row'); // Linha Conteúdo + Unid Conteúdo
                var $perishableRow = $('#row-is-perishable');

                if (type === 'RESOURCE') {
                    // Ocultar (Generalização não tem esses campos)
                    $skuGroup.hide();
                    $sellingGroup.hide();
                    $contentRow.hide();
                    $('#row-resource-parent').hide();

                    // Mostrar Perecível para RESOURCE
                    $perishableRow.show();
                    $perishableRow.css('display', 'flex');
                } else {
                    // Mostrar (Restaurar)
                    // Usamos .css('display', '') para remover o style="display: none" inline
                    // Isso permite que o CSS original (block ou grid) assuma o controle
                    $skuGroup.css('display', '');
                    $sellingGroup.css('display', '');
                    $contentRow.css('display', '');
                    $('#row-resource-parent').css('display', '');

                    // Ocultar Perecível para outros tipos (herdam da Generalização)
                    $perishableRow.hide();

                    // Fallback: se por algum motivo o CSS não aplicar, forçamos
                    if ($contentRow.css('display') === 'none') {
                        $contentRow.css('display', 'flex'); // .form-row use flex
                    }
                    if ($('#row-resource-parent').css('display') === 'none') {
                        $('#row-resource-parent').css('display', 'flex');
                    }
                    if ($skuGroup.css('display') === 'none') {
                        $skuGroup.css('display', 'block');
                    }
                    if ($sellingGroup.css('display') === 'none') {
                        $sellingGroup.css('display', 'block');
                    }
                }
            } catch (e) {
                console.error('SISTUR Error in toggleFieldsByType:', e);
            }
        }

        $('#cmv-type').on('change', function () {
            toggleRecipeTab();
            toggleFieldsByType();
        });

        $('#btn-new-product-portal').on('click', function () {
            setTimeout(function () {
                toggleRecipeTab();
                toggleFieldsByType();
                $('#cmv-type').trigger('change');
            }, 100);
        });

        // --- Lógica de Cascata de Locais/Setores na Entrada ---
        $('#entry-location-parent').on('change', function () {
            var $sectorSelect = $('#entry-location-sector');
            var $wrapper = $('#entry-sector-wrapper');
            var selectedOption = $(this).find('option:selected');
            var sectors = selectedOption.data('sectors') || [];

            $sectorSelect.empty().append('<option value="">Selecione o Setor...</option>');

            if (sectors.length > 0) {
                //Tem setores: popula e obriga escolha
                $.each(sectors, function (i, sector) {
                    $sectorSelect.append('<option value="' + sector.id + '">' + sector.name + '</option>');
                });
                $sectorSelect.prop('disabled', false).prop('required', true);
                $wrapper.show();
                // Se o usuário selecionou o local pai, mas tem setores, o value final deve vir do setor
                // O name="location_id" está no select do setor, então ok
            } else {
                // Não tem setores: usa o ID do pai como location_id
                // Para isso, precisamos que o select do setor NÃO seja enviado (disabled) 
                // e criar um hidden input ou mudar o name dinamicamente?
                // Melhor abordagem: O backend espera 'location_id'. 
                // Se tem setores, o select de setor tem name='location_id'.
                // Se NÃO tem setores, o select pai deve ter name='location_id'.

                $sectorSelect.prop('disabled', true).prop('required', false);
                $wrapper.hide();

                // Ajuste de names
                $(this).attr('name', 'location_id');
                $sectorSelect.attr('name', 'sector_id_ignore'); // Tira o name location_id
            }

            if (sectors.length > 0) {
                // Se tem setores, o pai é apenas auxiliar
                $(this).attr('name', 'parent_location_id_aux');
                $sectorSelect.attr('name', 'location_id');
            }
        });

        // Disparar change inicial caso haja valor pré-selecionado (ex: navegador ou reload)
        if ($('#entry-location-parent').val()) {
            $('#entry-location-parent').trigger('change');
        }

    })(jQuery);

    // START INLINE: Stock by Location
    jQuery(document).ready(function ($) {
        console.log('SISTUR Stock by Location script loaded (INLINE)');

        var sisturAdminLocal = typeof sisturAdmin !== 'undefined' ? sisturAdmin : {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('sistur_inventory_nonce'); ?>',
            strings: {
                error: '<?php _e('Ocorreu um erro. Tente novamente.', 'sistur'); ?>'
            }
        };

        var $modal = $('#modal-stock-by-location');
        var $content = $('#stock-by-location-content');
        var $loading = $('#stock-by-location-loading');

        $(document).on('click', '#btn-view-by-location', function (e) {
            console.log('Button clicked');
            e.preventDefault();
            $modal.fadeIn(200);
            loadStockByLocation();
        });

        $(document).on('click', '.modal-close', function () {
            $modal.fadeOut(200);
        });

        function loadStockByLocation() {
            $content.hide();
            $loading.show();

            $.ajax({
                url: sisturAdminLocal.ajax_url,
                type: 'POST',
                data: {
                    action: 'sistur_get_stock_by_location',
                    nonce: sisturAdminLocal.nonce
                },
                success: function (response) {
                    $loading.hide();
                    if (response.success) {
                        renderStockByLocation(response.data);
                        $content.fadeIn(200);
                    } else {
                        alert(response.data.message || sisturAdminLocal.strings.error);
                    }
                },
                error: function () {
                    $loading.hide();
                    alert(sisturAdminLocal.strings.error);
                }
            });
        }

        function renderStockByLocation(groupedData) {
            var html = '';

            if (!groupedData || groupedData.length === 0) {
                html = '<p style="text-align:center; color:#64748b;">Nenhum item em estoque encontrado.</p>';
                $content.html(html);
                return;
            }

            groupedData.forEach(function (location) {
                html += '<div class="location-group" style="margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">';
                html += '<div class="location-header" style="background: #f8fafc; padding: 12px 15px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">';
                html += '<h4 style="margin: 0; color: #334155; font-size: 1.1em;">' +
                    '<span class="dashicons dashicons-location" style="color: #64748b; margin-right: 5px;"></span>' +
                    escapeHtml(location.name) + '</h4>';
                html += '<span class="location-count" style="background: #e2e8f0; padding: 2px 8px; border-radius: 10px; font-size: 0.85em; color: #475569;">' +
                    location.items.length + ' itens</span>';
                html += '</div>';

                html += '<div class="location-items" style="padding: 0;">';
                html += '<table class="wp-list-table widefat fixed striped" style="border: none; box-shadow: none;">';
                html += '<thead><tr>';
                html += '<th style="padding-left: 20px;">Produto</th>';
                html += '<th>SKU</th>';
                html += '<th>Qtd Total</th>';
                html += '<th>Lotes</th>';
                html += '</tr></thead>';
                html += '<tbody>';

                if (location.items.length === 0) {
                    html += '<tr><td colspan="4" style="padding: 15px 20px; color: #94a3b8;">Nenhum item neste local.</td></tr>';
                } else {
                    location.items.forEach(function (item) {
                        html += '<tr>';
                        html += '<td style="padding-left: 20px;"><strong>' + escapeHtml(item.name) + '</strong></td>';
                        html += '<td>' + (item.sku ? escapeHtml(item.sku) : '-') + '</td>';
                        html += '<td><span style="font-weight:bold; color: #2563eb;">' +
                            formatNumber(item.total_quantity) + '</span> ' + escapeHtml(item.unit || '') + '</td>';

                        html += '<td>';
                        if (item.batches && item.batches.length > 0) {
                            html += '<div style="font-size: 0.85em; color: #64748b;">';
                            item.batches.forEach(function (batch, index) {
                                html += '<div>';
                                if (batch.batch_code) html += '<span style="background:#f1f5f9; padding:0 4px; border-radius:3px;">' + escapeHtml(batch.batch_code) + '</span> ';
                                html += formatNumber(batch.quantity) + (item.unit ? ' ' + item.unit : '');
                                if (batch.sector_name) html += ' <span style="color:#64748b; font-size:0.9em;">[' + escapeHtml(batch.sector_name) + ']</span>';
                                else if (!batch.sector_id && batch.location_name) html += ' <span style="color:#9ca3af; font-size:0.9em;">(sem setor)</span>';
                                if (batch.expiry) html += ' <span style="color:#ef4444;">(Val: ' + formatDate(batch.expiry) + ')</span>';
                                html += '</div>';
                            });
                            html += '</div>';
                        } else {
                            html += '-';
                        }
                        html += '</td>';
                        html += '</tr>';
                    });
                }

                html += '</tbody></table>';
                html += '</div></div>';
            });

            $content.html(html);
        }

        function escapeHtml(text) {
            if (!text) return '';
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, function (m) { return map[m]; });
        }

        function formatNumber(num) {
            return parseFloat(num).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            var parts = dateString.split('-');
            if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0]; // YYYY-MM-DD to DD/MM/YYYY
            return dateString;
        }
    }); // END INLINE

</script>

<style>
    .module-estoque h2 {
        margin: 0 0 25px 0;
        font-size: 1.5rem;
        color: #1e293b;
        border-bottom: 2px solid #f59e0b;
        padding-bottom: 10px;
        display: inline-block;
    }

    .module-estoque h3 {
        font-size: 1.1rem;
        color: #64748b;
        margin: 0 0 15px 0;
    }

    /* Actions Grid */
    .estoque-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }

    .estoque-action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 20px;
        border: none;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        color: white;
        text-decoration: none;
    }

    .estoque-action-btn.sale {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .estoque-action-btn.loss {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .estoque-action-btn.entry {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }

    .estoque-action-btn.admin {
        background: linear-gradient(135deg, #6366f1, #4f46e5);
    }

    .estoque-action-btn.product {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    }

    .estoque-action-btn.produce {
        background: linear-gradient(135deg, #22c55e, #16a34a);
    }

    .estoque-action-btn.location {
        background: linear-gradient(135deg, #06b6d4, #0891b2);
    }

    .estoque-action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        color: white;
    }

    .estoque-action-btn .dashicons {
        font-size: 28px;
        width: 28px;
        height: 28px;
    }

    /* Alert */
    .estoque-alert {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .estoque-alert.low-stock {
        background: #fef3c7;
        color: #92400e;
    }

    /* Search */
    .estoque-search {
        margin-bottom: 15px;
    }

    .estoque-search input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 1rem;
    }

    /* Products Table */
    .estoque-products-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .estoque-products-table th,
    .estoque-products-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
    }

    .estoque-products-table th {
        background: #f8fafc;
        font-weight: 600;
        color: #64748b;
        font-size: 0.85rem;
        text-transform: uppercase;
    }

    .estoque-products-table tr.low-stock {
        background: #fefce8;
    }

    .stock-qty {
        font-weight: 700;
        color: #1e293b;
    }

    .stock-unit {
        color: #94a3b8;
        font-size: 0.85rem;
    }

    .product-actions {
        display: flex;
        gap: 8px;
    }

    .btn-quick-action {
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .btn-quick-action.sale {
        background: #dcfce7;
        color: #16a34a;
    }

    .btn-quick-action.sale:hover {
        background: #16a34a;
        color: white;
    }

    .btn-quick-action.loss {
        background: #fef3c7;
        color: #d97706;
    }

    .btn-quick-action.loss:hover {
        background: #d97706;
        color: white;
    }

    /* History */
    .estoque-history-section {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }

    .estoque-history-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .estoque-history-list li {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 16px;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 8px;
    }

    .estoque-history-list .movement-info {
        flex: 1;
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .movement-qty {
        font-weight: 700;
    }

    .movement-entry .movement-qty {
        color: #16a34a;
    }

    .movement-exit .movement-qty {
        color: #dc2626;
    }

    .movement-time {
        color: #94a3b8;
        font-size: 0.85rem;
    }

    .movement-status.pending {
        background: #fef3c7;
        color: #92400e;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    /* Modal */
    .estoque-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        padding: 20px;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 480px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1.25rem;
        color: #1e293b;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #94a3b8;
    }

    .estoque-form {
        padding: 24px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #334155;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 1rem;
    }

    .form-notice {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        background: #f0f9ff;
        border-radius: 8px;
        color: #0369a1;
        font-size: 0.9rem;
        margin-bottom: 20px;
    }

    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .btn-cancel,
    .btn-submit {
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        border: none;
    }

    .btn-cancel {
        background: #f1f5f9;
        color: #64748b;
    }

    .btn-submit {
        background: linear-gradient(135deg, #0d9488, #0f766e);
        color: white;
    }

    .btn-submit:hover {
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
    }

    /* CMV Styles */
    .modal-content.modal-lg {
        max-width: 700px;
    }

    .modal-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        padding: 0 24px;
    }

    .tab-btn {
        padding: 12px 20px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-weight: 600;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
    }

    .tab-btn.active {
        color: #0f766e;
        border-bottom-color: #0f766e;
    }

    .tab-content {
        padding: 20px 0;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    .recipe-info-box {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        background: #f0fdf4;
        border-radius: 8px;
        color: #166534;
        margin-bottom: 16px;
    }

    .ingredient-search-row {
        display: flex;
        gap: 10px;
        margin-bottom: 16px;
    }

    .ingredient-search-row select {
        flex: 1;
    }

    .ingredient-search-row input {
        width: 100px;
    }

    .btn-add {
        background: #10b981;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 10px 14px;
        cursor: pointer;
    }

    .recipe-ingredients-table,
    .produce-ingredients-table,
    .batches-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 16px;
    }

    .recipe-ingredients-table th,
    .produce-ingredients-table th,
    .batches-table th {
        background: #f8fafc;
        padding: 10px;
        text-align: left;
        font-size: 0.85rem;
    }

    .recipe-ingredients-table td,
    .produce-ingredients-table td,
    .batches-table td {
        padding: 10px;
        border-bottom: 1px solid #e2e8f0;
    }

    .btn-remove-ingredient {
        background: #ef4444;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 4px 8px;
        cursor: pointer;
    }

    .type-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .type-badge.type-raw {
        background: #dcfce7;
        color: #166534;
    }

    .type-badge.type-manufactured {
        background: #fef3c7;
        color: #92400e;
    }

    .type-badge.type-resale {
        background: #dbeafe;
        color: #1e40af;
    }

    .cmv-col {
        min-width: 100px;
    }

    .cost-cell {
        font-family: monospace;
    }

    .produce-product-info,
    .batches-product-info {
        background: #f0f9ff;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
    }

    .produce-product-info h4,
    .batches-product-info h4 {
        margin: 0 0 8px 0;
        color: #0369a1;
    }

    .produce-cost-summary {
        background: #fefce8;
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
    }

    .cost-item {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
    }

    .cost-item.total {
        border-top: 1px solid #fde68a;
        padding-top: 12px;
    }

    .status-ok {
        color: #16a34a;
        font-weight: 600;
    }

    .status-error {
        color: #dc2626;
        font-weight: 600;
    }

    .batch-status {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .batch-status.status-active {
        background: #dcfce7;
        color: #166534;
    }

    .batch-status.status-exhausted {
        background: #f3f4f6;
        color: #6b7280;
    }

    .batch-status.status-expired {
        background: #fee2e2;
        color: #991b1b;
    }

    .btn-produce {
        display: flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #22c55e, #16a34a) !important;
    }

    .btn-quick-action.edit {
        background: #8b5cf6;
    }

    .btn-quick-action.produce {
        background: #22c55e;
    }

    .btn-quick-action.batches {
        background: #0ea5e9;
    }

    /* Expiry Alerts Panel (FEFO) */
    .expiry-alerts-panel {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 1px solid #f59e0b;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
    }

    .expiry-alerts-panel h4 {
        margin: 0 0 12px 0;
        color: #92400e;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .expiry-alerts-panel h4 .dashicons {
        color: #d97706;
    }

    .expiry-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .expiry-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 0.85rem;
        flex: 1 1 auto;
        min-width: 200px;
        max-width: 300px;
    }

    .expiry-item.warning {
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid #fcd34d;
    }

    .expiry-item.critical {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: #991b1b;
    }

    .expiry-item.expired {
        background: #dc2626;
        color: white;
        border: 1px solid #b91c1c;
    }

    .expiry-item strong {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .expiry-item .qty {
        font-family: monospace;
        font-weight: 600;
    }

    .expiry-item .date {
        font-size: 0.75rem;
        font-weight: 600;
    }

    .badge-expired,
    .badge-today {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .badge-expired {
        background: #dc2626;
        color: white;
    }

    .badge-today {
        background: #ea580c;
        color: white;
    }

    @media (max-width: 768px) {
        .estoque-actions-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .estoque-products-table {
            font-size: 0.9rem;
        }

        .estoque-products-table th:nth-child(2),
        .estoque-products-table td:nth-child(2) {
            display: none;
        }

        .cmv-col {
            display: none;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .modal-content.modal-lg {
            max-width: 95%;
        }
    }
</style>

<script>
    // Define ajaxurl for AJAX requests (WordPress doesn't auto-inject in all contexts)
    // Define ajaxurl for AJAX requests (WordPress doesn't auto-inject in all contexts)
    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

    // API Settings for REST calls
    var sistur_api_settings = {
        root: '<?php echo esc_url_raw(rest_url()); ?>',
        nonce: '<?php echo wp_create_nonce('wp_rest'); ?>'
    };

    jQuery(document).ready(function ($) {
        // Busca de produtos em tempo real
        $('#estoque-search').on('keyup', function () {
            var value = $(this).val().toLowerCase().trim();
            $('#estoque-products-list tr').filter(function () {
                var text = $(this).text().toLowerCase();
                var match = text.indexOf(value) > -1;
                $(this).toggle(match);
            });
        });

        // Auto-executar busca se já tiver valor (em caso de reload com cache)
        if ($('#estoque-search').val()) {
            $('#estoque-search').trigger('keyup');
        }

        // Handler para formulário de entrada (AJAX)
        // Lógica para checkbox "Não perecível"
        $('#entry-non-perishable').on('change', function () {
            var isNonPerishable = $(this).is(':checked');
            var $expiryInput = $('#entry-expiry-date');

            if (isNonPerishable) {
                $expiryInput.val('').prop('required', false).prop('disabled', true);
            } else {
                $expiryInput.prop('required', true).prop('disabled', false);
            }
        });

        // === Bulk Entry Modal ===

        function sisturAddEntryRow() {
            var template = document.getElementById('entry-row-template');
            if (!template) return;
            var $clone = $(template.content.cloneNode(true));
            $('#entry-rows-body').append($clone);
            sisturUpdateEntryBtn();
        }

        function sisturGetValidEntries() {
            var entries = [];
            $('#entry-rows-body .entry-row').each(function () {
                var product_id = $(this).find('.entry-product').val();
                var qty = parseFloat($(this).find('.entry-qty').val());
                if (product_id && qty > 0) {
                    var price = parseFloat($(this).find('.entry-price').val());
                    var expiry = $(this).find('.entry-expiry').val();
                    entries.push({
                        product_id: parseInt(product_id, 10),
                        quantity: qty,
                        unit_price: (price >= 0) ? price : 0,
                        expiry_date: expiry || null
                    });
                }
            });
            return entries;
        }

        function sisturUpdateEntryBtn() {
            var n = sisturGetValidEntries().length;
            var $btn = $('#entry-submit-btn');
            if (n === 0) {
                $btn.prop('disabled', true).text('<?php _e('Registrar Entradas', 'sistur'); ?>');
            } else {
                $btn.prop('disabled', false).text('<?php _e('Registrar', 'sistur'); ?> ' + n + ' ' + (n === 1 ? '<?php _e('Entrada', 'sistur'); ?>' : '<?php _e('Entradas', 'sistur'); ?>'));
            }
        }

        function sisturInitEntryModal() {
            $('#entry-rows-body').empty();
            sisturAddEntryRow();
            $('#entry-location-parent').val('').trigger('change');
            // Reset searchable supplier select
            $('#entry-supplier-search').val('');
            $('#entry-supplier-id').val('');
            $('#supplier-dropdown').hide().empty();
            $('#entry-notes').val('');
            sisturUpdateEntryBtn();
            // Pre-load suppliers cache for the dropdown
            loadSuppliers();
        }

        // Inicializar modal ao abrir
        $('.estoque-action-btn.entry[data-modal="modal-entry"]').on('click', function () {
            sisturInitEntryModal();
        });

        // Campo alterado em qualquer linha: atualizar botão + adicionar nova linha se for a última
        $(document).on('change input', '#entry-rows-body .entry-row input, #entry-rows-body .entry-row select', function () {
            sisturUpdateEntryBtn();
            var $row = $(this).closest('.entry-row');
            var $lastRow = $('#entry-rows-body .entry-row').last();
            if ($row.is($lastRow) && $(this).val()) {
                sisturAddEntryRow();
            }
        });

        // Remover linha
        $(document).on('click', '.entry-remove-row', function () {
            var $rows = $('#entry-rows-body .entry-row');
            if ($rows.length > 1) {
                $(this).closest('.entry-row').remove();
            }
            var $last = $('#entry-rows-body .entry-row').last();
            var hasContent = $last.find('.entry-product').val() || $last.find('.entry-qty').val();
            if (hasContent) {
                sisturAddEntryRow();
            }
            sisturUpdateEntryBtn();
        });

        // Submit em lote
        $('#form-entry').off('submit').on('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            var $form = $(this);
            var $btn = $('#entry-submit-btn');

            if ($form.data('submitting')) return false;

            var locationId = ($('#entry-location-sector').is(':visible') && !$('#entry-location-sector').prop('disabled'))
                ? $('#entry-location-sector').val()
                : $('#entry-location-parent').val();

            if (!locationId) {
                alert('<?php _e('Selecione o Local de Armazenamento.', 'sistur'); ?>');
                return;
            }

            var entries = sisturGetValidEntries();
            if (entries.length === 0) {
                alert('<?php _e('Adicione pelo menos um produto com quantidade.', 'sistur'); ?>');
                return;
            }

            var originalText = $btn.text();
            $form.data('submitting', true);
            $btn.prop('disabled', true).text('<?php _e('Processando...', 'sistur'); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sistur_save_bulk_movement',
                    nonce: $form.find('input[name="nonce"]').val(),
                    location_id: locationId,
                    supplier_id: $('#entry-supplier-id').val(),
                    notes: $('#entry-notes').val(),
                    entries: JSON.stringify(entries)
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message || '<?php _e('Entradas registradas com sucesso!', 'sistur'); ?>');
                        $('#modal-entry').fadeOut(200);
                        window.location.reload();
                    } else {
                        alert('<?php _e('Erro', 'sistur'); ?>: ' + (response.data.message || '<?php _e('Erro desconhecido', 'sistur'); ?>'));
                    }
                },
                error: function (xhr, status, error) {
                    alert('<?php _e('Erro de conexão', 'sistur'); ?>: ' + error + '\nStatus: ' + status);
                },
                complete: function () {
                    $form.data('submitting', false);
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
        // === Location Management ===

        // Open Locations Modal
        $('#btn-manage-locations').on('click', function () {
            $('#modal-locations').fadeIn(200);
            loadLocations();
        });

        // Close Modal
        $('.modal-close').on('click', function () {
            $(this).closest('.estoque-modal').fadeOut(200);
        });

        // Load Locations with Sectors
        function loadLocations() {
            var $container = $('#locations-list-container');
            $container.html('<div style="text-align:center; padding:20px;">Carregando...</div>');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations',
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function (response) {
                    $container.empty();
                    if (response && response.length > 0) {
                        response.forEach(function (loc) {
                            var defaultIcon = loc.is_default
                                ? '<span class="dashicons dashicons-star-filled" style="color: #f59e0b;" title="Local Padrão"></span>'
                                : '<span class="dashicons dashicons-star-empty set-default-location" data-id="' + loc.id + '" style="cursor: pointer; color: #ccc;" title="Definir como Padrão"></span>';

                            var sectorsHtml = '';
                            if (loc.sectors && loc.sectors.length > 0) {
                                loc.sectors.forEach(function (sector) {
                                    sectorsHtml += `
                                        <div class="sector-item" style="display:flex; align-items:center; justify-content:space-between; padding:6px 12px; border-bottom:1px solid #f0f0f0;">
                                            <span style="font-size:13px;">📌 ${sector.name}</span>
                                            <button class="delete-location" data-id="${sector.id}" style="color:#dc2626; border:none; background:none; cursor:pointer; padding:2px;" title="Excluir setor">
                                                <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                            </button>
                                        </div>`;
                                });
                            }

                            var card = `
                                <div class="location-card" style="border:1px solid #ddd; border-radius:8px; margin-bottom:12px; overflow:hidden;">
                                    <div class="location-header" style="display:flex; align-items:center; justify-content:space-between; padding:12px 15px; background:#f8f9fa; border-bottom:1px solid #eee;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            ${defaultIcon}
                                            <strong style="font-size:14px;">📦 ${loc.name}</strong>
                                            <span style="font-size:11px; color:#888; background:#eee; padding:2px 8px; border-radius:10px;">${(loc.sectors ? loc.sectors.length : 0)} setores</span>
                                        </div>
                                        <button class="delete-location" data-id="${loc.id}" style="color:#dc2626; border:none; background:none; cursor:pointer;" title="Excluir local">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                    <div class="location-sectors" style="background:#fff;">
                                        ${sectorsHtml}
                                        <div class="add-sector-row" style="display:flex; gap:8px; padding:8px 12px; align-items:center;">
                                            <input type="text" class="sector-name-input" data-parent-id="${loc.id}"
                                                placeholder="Nome do setor (ex: Prateleira A)"
                                                style="flex:1; padding:6px 10px; border:1px solid #ddd; border-radius:4px; font-size:13px;">
                                            <button class="btn-add-sector" data-parent-id="${loc.id}"
                                                style="padding:4px 12px; background:#10b981; color:white; border:none; border-radius:4px; cursor:pointer; font-size:12px; white-space:nowrap;">
                                                + Setor
                                            </button>
                                        </div>
                                    </div>
                                </div>`;
                            $container.append(card);
                        });
                    } else {
                        $container.html('<div style="text-align:center; padding:20px; color:#888;">Nenhum local cadastrado.</div>');
                    }
                },
                error: function () {
                    $container.html('<div style="text-align:center; padding:20px; color:red;">Erro ao carregar locais.</div>');
                }
            });
        }

        // Add Location
        $('#form-add-location').on('submit', function (e) {
            e.preventDefault();
            var name = $(this).find('input[name="location_name"]').val();
            var $btn = $(this).find('button[type="submit"]');

            if (!name) return;

            $btn.prop('disabled', true).text('Adicionando...');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function (response) {
                    $('#form-add-location')[0].reset();
                    loadLocations();
                },
                error: function (err) {
                    alert('Erro ao adicionar local: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                },
                complete: function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt2"></span> Adicionar');
                }
            });
        });

        // Add Sector
        $(document).on('click', '.btn-add-sector', function () {
            var parentId = $(this).data('parent-id');
            var $input = $(this).siblings('.sector-name-input');
            var name = $input.val();
            var $btn = $(this);

            if (!name) {
                $input.focus();
                return;
            }

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name, parent_id: parentId }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    loadLocations();
                },
                error: function (err) {
                    alert('Erro ao adicionar setor: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                },
                complete: function () {
                    $btn.prop('disabled', false).text('+ Setor');
                }
            });
        });

        // Allow Enter key to add sector
        $(document).on('keypress', '.sector-name-input', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).siblings('.btn-add-sector').click();
            }
        });

        // Delete Location or Sector
        $(document).on('click', '.delete-location', function () {
            if (!confirm('Tem certeza que deseja excluir?')) return;

            var id = $(this).data('id');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations/' + id,
                type: 'DELETE',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    loadLocations();
                },
                error: function (err) {
                    alert('Erro ao excluir: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                }
            });
        });

        // Set Default Location
        $(document).on('click', '.set-default-location', function () {
            var id = $(this).data('id');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations/default',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ id: id }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    loadLocations();
                },
                error: function (err) {
                    alert('Erro ao definir local padrão: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                }
            });
        });

        // ============================================
        // Supplier Management (v2.17.0)
        // ============================================

        var $suppliersCache = []; // In-memory cache of all suppliers

        // Open Suppliers Modal from sidebar button
        $('#btn-manage-suppliers').on('click', function () {
            $('#modal-suppliers').fadeIn(200);
            loadSuppliers();
        });

        // Open Suppliers Modal from "+" button inside entry modal
        $('#btn-open-suppliers-from-entry').on('click', function () {
            $('#modal-suppliers').fadeIn(200);
            loadSuppliers();
        });

        // Load Suppliers — populates both the modal list and the dropdown cache
        function loadSuppliers() {
            var $container = $('#suppliers-list-container');
            $container.html('<div style="text-align:center; padding:20px; color:#64748b;">Carregando...</div>');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/suppliers',
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function (suppliers) {
                    $suppliersCache = Array.isArray(suppliers) ? suppliers : [];
                    $container.empty();

                    if ($suppliersCache.length === 0) {
                        $container.html('<div style="text-align:center; padding:20px; color:#888;">Nenhum fornecedor cadastrado.</div>');
                        return;
                    }

                    $suppliersCache.forEach(function (s) {
                        var meta = [];
                        if (s.tax_id) meta.push('CNPJ: ' + s.tax_id);
                        if (s.contact_info) meta.push(s.contact_info);
                        var metaHtml = meta.length ? '<span style="font-size:12px; color:#64748b;">' + meta.join(' · ') + '</span>' : '';

                        var card = `
                            <div class="supplier-card" data-id="${s.id}"
                                 style="display:flex; align-items:center; justify-content:space-between;
                                        padding:10px 14px; border:1px solid #e5e7eb; border-radius:6px;
                                        margin-bottom:8px; background:#fff;">
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:600; font-size:13px; margin-bottom:2px;" class="supplier-name-display">${s.name}</div>
                                    <div class="supplier-meta-display">${metaHtml}</div>
                                    <!-- Edit form (hidden) -->
                                    <div class="supplier-edit-form" style="display:none; margin-top:8px;">
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <input type="text" class="edit-supplier-name" value="${s.name}" placeholder="Nome *" style="flex:2; min-width:120px;">
                                            <input type="text" class="edit-supplier-tax-id" value="${s.tax_id || ''}" placeholder="CNPJ" style="flex:1; min-width:100px;" maxlength="18">
                                            <input type="text" class="edit-supplier-contact" value="${s.contact_info || ''}" placeholder="Contato" style="flex:2; min-width:120px;">
                                            <button class="btn-save-supplier-edit" data-id="${s.id}"
                                                    style="padding:4px 12px; background:#10b981; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">
                                                Salvar
                                            </button>
                                            <button class="btn-cancel-supplier-edit"
                                                    style="padding:4px 10px; background:#f1f5f9; color:#374151; border:1px solid #d1d5db; border-radius:4px; cursor:pointer; font-size:12px;">
                                                Cancelar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div style="display:flex; gap:6px; margin-left:10px; flex-shrink:0;">
                                    <button class="btn-edit-supplier" data-id="${s.id}"
                                            style="color:#3b82f6; border:none; background:none; cursor:pointer; padding:4px;" title="Editar">
                                        <span class="dashicons dashicons-edit" style="font-size:16px;"></span>
                                    </button>
                                    <button class="btn-delete-supplier" data-id="${s.id}"
                                            style="color:#dc2626; border:none; background:none; cursor:pointer; padding:4px;" title="Excluir">
                                        <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                    </button>
                                </div>
                            </div>`;
                        $container.append(card);
                    });
                },
                error: function () {
                    $container.html('<div style="text-align:center; padding:20px; color:red;">Erro ao carregar fornecedores.</div>');
                }
            });
        }

        // Add Supplier
        $('#form-add-supplier').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var name = $form.find('input[name="supplier_name"]').val().trim();
            var tax_id = $form.find('input[name="supplier_tax_id"]').val().trim();
            var contact_info = $form.find('input[name="supplier_contact"]').val().trim();
            var $btn = $form.find('button[type="submit"]');

            if (!name) return;

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/suppliers',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ name: name, tax_id: tax_id, contact_info: contact_info }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    $form[0].reset();
                    loadSuppliers();
                },
                error: function (err) {
                    alert('Erro ao adicionar fornecedor: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                },
                complete: function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt2"></span> Adicionar');
                }
            });
        });

        // Open Edit Form
        $(document).on('click', '.btn-edit-supplier', function () {
            var $card = $(this).closest('.supplier-card');
            $card.find('.supplier-name-display, .supplier-meta-display').hide();
            $card.find('.supplier-edit-form').show();
            $(this).hide();
            $card.find('.btn-delete-supplier').hide();
        });

        // Cancel Edit
        $(document).on('click', '.btn-cancel-supplier-edit', function () {
            var $card = $(this).closest('.supplier-card');
            $card.find('.supplier-edit-form').hide();
            $card.find('.supplier-name-display, .supplier-meta-display').show();
            $card.find('.btn-edit-supplier, .btn-delete-supplier').show();
        });

        // Save Edit
        $(document).on('click', '.btn-save-supplier-edit', function () {
            var $btn = $(this);
            var id = $btn.data('id');
            var $form = $btn.closest('.supplier-edit-form');
            var name = $form.find('.edit-supplier-name').val().trim();
            var tax_id = $form.find('.edit-supplier-tax-id').val().trim();
            var contact_info = $form.find('.edit-supplier-contact').val().trim();

            if (!name) {
                $form.find('.edit-supplier-name').focus();
                return;
            }

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/suppliers/' + id,
                type: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify({ name: name, tax_id: tax_id, contact_info: contact_info }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    // Preserve current selection if this supplier was selected
                    var selectedId = $('#entry-supplier-id').val();
                    loadSuppliers();
                    if (selectedId == id) {
                        // Update search text to reflect new name
                        $('#entry-supplier-search').val(name);
                    }
                },
                error: function (err) {
                    alert('Erro ao salvar: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                    $btn.prop('disabled', false).text('Salvar');
                }
            });
        });

        // Delete Supplier
        $(document).on('click', '.btn-delete-supplier', function () {
            if (!confirm('Tem certeza que deseja excluir este fornecedor?')) return;

            var id = $(this).data('id');

            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/suppliers/' + id,
                type: 'DELETE',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function () {
                    // Clear selection if the deleted supplier was selected
                    if ($('#entry-supplier-id').val() == id) {
                        $('#entry-supplier-search').val('');
                        $('#entry-supplier-id').val('');
                    }
                    loadSuppliers();
                },
                error: function (err) {
                    alert('Erro ao excluir: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
                }
            });
        });

        // ---- Searchable Select (supplier dropdown in entry form) ----

        function sisturRenderSupplierDropdown(filter) {
            var $drop = $('#supplier-dropdown');
            var query = (filter || '').toLowerCase().trim();
            var filtered = query
                ? $suppliersCache.filter(function (s) {
                    return s.name.toLowerCase().indexOf(query) !== -1 ||
                        (s.tax_id && s.tax_id.indexOf(query) !== -1);
                })
                : $suppliersCache;

            $drop.empty();

            if (filtered.length === 0) {
                $drop.append('<div style="padding:8px 12px; color:#888; font-size:13px;">Nenhum resultado</div>');
            } else {
                filtered.forEach(function (s) {
                    var label = s.name + (s.tax_id ? ' <span style="color:#94a3b8; font-size:11px;">(' + s.tax_id + ')</span>' : '');
                    var $item = $('<div>')
                        .addClass('supplier-dropdown-item')
                        .attr('data-id', s.id)
                        .attr('data-name', s.name)
                        .css({ padding: '8px 12px', cursor: 'pointer', fontSize: '13px', borderBottom: '1px solid #f3f4f6' })
                        .html(label);
                    $drop.append($item);
                });
            }

            $drop.show();
        }

        // Hover effect on dropdown items
        $(document).on('mouseenter', '.supplier-dropdown-item', function () {
            $(this).css('background', '#f0f9ff');
        }).on('mouseleave', '.supplier-dropdown-item', function () {
            $(this).css('background', '');
        });

        // Typing in search box — filter dropdown
        $('#entry-supplier-search').on('input', function () {
            sisturRenderSupplierDropdown($(this).val());
        }).on('focus', function () {
            if ($suppliersCache.length > 0) {
                sisturRenderSupplierDropdown($(this).val());
            }
        });

        // Click on dropdown item — select it
        $(document).on('mousedown', '.supplier-dropdown-item', function (e) {
            e.preventDefault(); // prevent blur from firing before click
            var id = $(this).data('id');
            var name = $(this).data('name');
            $('#entry-supplier-search').val(name);
            $('#entry-supplier-id').val(id);
            $('#supplier-dropdown').hide();
        });

        // Close dropdown on blur
        $('#entry-supplier-search').on('blur', function () {
            setTimeout(function () {
                $('#supplier-dropdown').hide();
            }, 200);
        });

        // Clear hidden ID if user clears the text manually
        $('#entry-supplier-search').on('input', function () {
            if ($(this).val() === '') {
                $('#entry-supplier-id').val('');
            }
        });

        // ============================================
        // Inventário Cego / Blind Inventory
        // ============================================

        var biCurrentSessionId = null;
        var biLocationsCache = null;
        // v2.15.0 - Wizard state
        var biWizardMode = false;
        var biWizardData = {};
        var biWizardCompleted = false;

        // Open modal
        $('#btn-blind-inventory').on('click', function () {
            $('#modal-blind-inventory').show();
            biShowView('sessions');
            biLoadSessions();
        });

        function biApiCall(method, endpoint, data) {
            var opts = {
                url: sistur_api_settings.root + 'sistur/v1/stock/blind-inventory' + endpoint,
                type: method,
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                }
            };
            if (data) {
                opts.contentType = 'application/json';
                opts.data = JSON.stringify(data);
            }
            return $.ajax(opts);
        }

        function biShowView(view) {
            $('#bi-view-sessions, #bi-view-count, #bi-view-report').hide();
            $('#bi-view-' + view).show();
        }

        // Load Sessions
        function biLoadSessions() {
            var $list = $('#bi-sessions-list');
            $list.html('<div style="text-align:center; padding:20px; color:#888;">Carregando...</div>');

            biApiCall('GET', '').done(function (resp) {
                if (!resp.success || !resp.sessions.length) {
                    $list.html('<div style="text-align:center; padding:30px; color:#888;">Nenhuma sessão encontrada. Clique em "Nova Contagem" para iniciar.</div>');
                    return;
                }

                var html = '<div style="display:flex; flex-direction:column; gap:8px;">';
                resp.sessions.forEach(function (s) {
                    var statusColors = {
                        'in_progress': { bg: '#fff7ed', border: '#fdba74', color: '#c2410c', label: 'Em Andamento' },
                        'completed': { bg: '#f0fdf4', border: '#86efac', color: '#166534', label: 'Concluída' },
                        'cancelled': { bg: '#fef2f2', border: '#fecaca', color: '#991b1b', label: 'Cancelada' }
                    };
                    var st = statusColors[s.status] || statusColors['cancelled'];
                    var scopeText = s.location_name ? ('📍 ' + s.location_name + (s.sector_name ? ' › ' + s.sector_name : '')) : '📍 Todos os locais';
                    var progress = s.items_count > 0 ? Math.round((s.items_filled / s.items_count) * 100) : 0;

                    var actions = '';
                    if (s.status === 'in_progress') {
                        actions = '<button class="bi-continue" data-id="' + s.id + '" style="padding:4px 10px; background:#2271b1; color:white; border:none; border-radius:4px; cursor:pointer; font-size:11px;">Continuar</button>';
                    } else if (s.status === 'completed') {
                        actions = '<button class="bi-view-report" data-id="' + s.id + '" style="padding:4px 10px; background:#6366f1; color:white; border:none; border-radius:4px; cursor:pointer; font-size:11px;">Relatório</button>';
                    }

                    html += '<div style="border:1px solid ' + st.border + '; background:' + st.bg + '; border-radius:8px; padding:12px 14px;">';
                    html += '<div style="display:flex; justify-content:space-between; align-items:center;">';
                    html += '<div>';
                    html += '<strong style="font-size:14px;">#' + s.id + '</strong> ';
                    html += '<span style="background:' + st.border + '; color:' + st.color + '; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">' + st.label + '</span>';
                    html += '<div style="font-size:12px; color:#666; margin-top:4px;">' + scopeText + ' • ' + s.started_at + ' • ' + (s.user_name || '') + '</div>';
                    html += '</div>';
                    html += '<div style="display:flex; align-items:center; gap:12px;">';
                    html += '<div style="text-align:center;"><div style="font-size:13px; font-weight:600;">' + s.items_filled + '/' + s.items_count + '</div><div style="font-size:10px; color:#888;">contados</div></div>';
                    if (s.status === 'completed') {
                        var divColor = s.total_divergence_value < 0 ? '#dc2626' : '#16a34a';
                        html += '<div style="text-align:center;"><div style="font-size:13px; font-weight:600; color:' + divColor + ';">R$ ' + s.total_divergence_value.toFixed(2) + '</div><div style="font-size:10px; color:#888;">impacto</div></div>';
                    }
                    html += actions;
                    html += '</div></div></div>';
                });
                html += '</div>';
                $list.html(html);
            }).fail(function () {
                $list.html('<div style="text-align:center; padding:20px; color:red;">Erro ao carregar sessões.</div>');
            });
        }

        // New session button
        $('#bi-btn-new').on('click', function () {
            $('#bi-new-session-form').slideToggle(200);
            biLoadLocationsForSelect();
        });

        $('#bi-cancel-new').on('click', function () {
            $('#bi-new-session-form').slideUp(200);
        });

        // Load locations for selector
        function biLoadLocationsForSelect() {
            if (biLocationsCache) {
                biPopulateLocationSelect(biLocationsCache);
                return;
            }
            $.ajax({
                url: sistur_api_settings.root + 'sistur/v1/stock/locations',
                type: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sistur_api_settings.nonce);
                },
                success: function (resp) {
                    biLocationsCache = resp;
                    biPopulateLocationSelect(resp);
                }
            });
        }

        function biPopulateLocationSelect(locations) {
            var $loc = $('#bi-location-select');
            $loc.find('option:not(:first)').remove();
            locations.forEach(function (l) {
                $loc.append('<option value="' + l.id + '">' + l.name + '</option>');
            });
        }

        // Location change -> populate sectors
        $('#bi-location-select').on('change', function () {
            var locId = $(this).val();
            var $sec = $('#bi-sector-select');
            $sec.find('option:not(:first)').remove();

            if (!locId || !biLocationsCache) {
                $sec.prop('disabled', true);
                return;
            }

            var loc = biLocationsCache.find(function (l) { return l.id == locId; });
            if (loc && loc.sectors && loc.sectors.length > 0) {
                loc.sectors.forEach(function (s) {
                    $sec.append('<option value="' + s.id + '">' + s.name + '</option>');
                });
                $sec.prop('disabled', false);
            } else {
                $sec.prop('disabled', true);
            }
        });

        // Confirm new session
        $('#bi-confirm-new').on('click', function () {
            var $btn = $(this);
            var data = {
                notes: $('#bi-notes').val() || ''
            };
            var locId = $('#bi-location-select').val();
            var secId = $('#bi-sector-select').val();
            if (locId) data.location_id = parseInt(locId);
            if (secId) data.sector_id = parseInt(secId);

            $btn.prop('disabled', true).text('Iniciando...');

            biApiCall('POST', '/start', data).done(function (resp) {
                if (resp.success) {
                    $('#bi-new-session-form').slideUp(200);
                    $('#bi-notes').val('');
                    $('#bi-location-select').val('');
                    $('#bi-sector-select').val('').prop('disabled', true);
                    biOpenCount(resp.session_id);
                } else {
                    alert('Erro: ' + resp.message);
                }
            }).fail(function (err) {
                alert('Erro ao criar sessão: ' + (err.responseJSON ? err.responseJSON.message : err.statusText));
            }).always(function () {
                $btn.prop('disabled', false).text('Iniciar');
            });
        });

        // Open count
        function biOpenCount(sessionId) {
            biCurrentSessionId = sessionId;
            $('#bi-count-session-id').text('#' + sessionId);
            biShowView('count');

            biApiCall('GET', '/' + sessionId).done(function (resp) {
                if (resp.success) {
                    var scope = resp.session.location_name
                        ? '📍 ' + resp.session.location_name + (resp.session.sector_name ? ' › ' + resp.session.sector_name : '')
                        : '📍 Todos os locais';
                    $('#bi-count-scope').text(scope);

                    // v2.15.0 - Check for wizard mode
                    if (resp.wizard && resp.wizard.wizard_mode) {
                        biWizardMode = true;
                        biWizardData = resp.wizard;
                        biWizardCompleted = false;
                        biInitWizardUI();
                        biLoadCurrentSectorItems();
                    } else {
                        biWizardMode = false;
                        $('#bi-wizard-progress').hide();
                        $('#bi-wizard-next-sector').hide();
                        $('#bi-submit-count').show();
                        biRenderCountItems(resp.items);
                    }
                }
            });
        }

        function biRenderCountItems(items) {
            var $body = $('#bi-count-body');
            $body.empty();
            var filled = 0;

            items.forEach(function (item) {
                var isFilled = item.physical_qty !== null && item.physical_qty !== '';
                if (isFilled) filled++;
                var bgClass = isFilled ? 'background:#f0fdf4;' : '';
                var borderClass = isFilled ? 'border-color:#86efac;' : '';

                $body.append(
                    '<tr class="bi-count-row" data-name="' + (item.product_name || '').toLowerCase() + '" style="border-bottom:1px solid #f0f0f0; ' + bgClass + '">' +
                    '<td style="padding:8px 10px; font-size:13px;">' + item.product_name + '</td>' +
                    '<td style="padding:8px 10px; text-align:center; font-size:12px; color:#888;">' + (item.unit_symbol || 'un') + '</td>' +
                    '<td style="padding:8px 10px; text-align:center;">' +
                    '<input type="number" class="bi-qty-input" data-product-id="' + item.product_id + '"' +
                    ' value="' + (isFilled ? item.physical_qty : '') + '"' +
                    ' step="0.001" min="0" placeholder="Qtd"' +
                    ' style="width:100%; padding:6px 8px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:13px; ' + borderClass + '">' +
                    '</td></tr>'
                );
            });

            $('#bi-items-filled').text(filled);
            $('#bi-items-total').text(items.length);
        }

        // v2.15.0 - Wizard Helper Functions
        function biInitWizardUI() {
            $('#bi-wizard-progress').show();
            var totalSectors = biWizardData.total_sectors || 1;
            var currentIndex = biWizardData.current_sector_index || 0;
            var sectorNames = biWizardData.sectors_names || {};
            var sectorsList = biWizardData.sectors_list || [];

            // Update sector label
            var currentSectorId = sectorsList[currentIndex];
            var currentSectorName = sectorNames[currentSectorId] || 'Setor ' + (currentIndex + 1);
            $('#bi-wizard-sector-label').text(currentSectorName);

            // Appending current sector to main scope label for better visibility
            var startScope = $('#bi-count-scope').text().split(' › ')[0]; // Keep only location
            $('#bi-count-scope').text(startScope + ' › ' + currentSectorName);

            // Update step counters
            $('#bi-wizard-current-step').text(currentIndex + 1);
            $('#bi-wizard-total-steps').text(totalSectors);

            // Update progress bar
            var progress = ((currentIndex) / totalSectors) * 100;
            $('#bi-wizard-progress-fill').css('width', progress + '%');

            // Render dots
            var $dots = $('#bi-wizard-sectors-dots');
            $dots.empty();
            for (var i = 0; i < totalSectors; i++) {
                var dotClass = i < currentIndex ? 'completed' : (i === currentIndex ? 'active' : 'pending');
                var dotColor = i < currentIndex ? '#10b981' : (i === currentIndex ? '#2271b1' : '#e0e0e0');
                var sectorId = sectorsList[i];
                var sectorName = sectorNames[sectorId] || 'Setor ' + (i + 1);

                $dots.append(
                    '<div title="' + sectorName + '" style="width:28px; height:28px; border-radius:50%; background:' + dotColor + '; color:white; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; cursor:help;">' +
                    (i + 1) +
                    '</div>'
                );
            }

            biUpdateWizardButtons();
        }

        function biLoadCurrentSectorItems() {
            biApiCall('GET', '/' + biCurrentSessionId + '/current-sector').done(function (resp) {
                if (resp.success) {
                    biRenderCountItems(resp.items);
                    biUpdateWizardButtons();
                }
            }).fail(function () {
                alert('Erro ao carregar itens do setor.');
            });
        }

        function biUpdateWizardButtons() {
            if (!biWizardMode) return;

            var isLastSector = biWizardData.current_sector_index >= (biWizardData.total_sectors - 1);

            if (biWizardCompleted) {
                // Wizard completed - show all items for final review
                $('#bi-wizard-next-sector').hide();
                $('#bi-submit-count').show();
                $('#bi-wizard-progress-fill').css('width', '100%');
            } else if (isLastSector) {
                // Last sector - show "Complete Sectors" button
                $('#bi-wizard-next-sector').show().text('✅ Concluir Setores');
                $('#bi-submit-count').hide();
            } else {
                // Not last sector - show "Next Sector" button
                $('#bi-wizard-next-sector').show().html('➡️ <?php _e("Próximo Setor", "sistur"); ?>');
                $('#bi-submit-count').hide();
            }
        }

        function biAdvanceToNextSector() {
            var $btn = $('#bi-wizard-next-sector');
            $btn.prop('disabled', true).text('Salvando...');

            // Save current sector items first
            var promises = [];
            $('.bi-qty-input').each(function () {
                var val = $(this).val();
                if (val !== '') {
                    promises.push(biApiCall('PUT', '/' + biCurrentSessionId + '/item/' + $(this).data('product-id'), {
                        physical_qty: parseFloat(val)
                    }));
                }
            });

            $.when.apply($, promises).done(function () {
                // Call advance endpoint
                biApiCall('POST', '/' + biCurrentSessionId + '/advance').done(function (resp) {
                    if (resp.success) {
                        if (resp.completed) {
                            // Wizard completed - load ALL items for final review
                            biWizardCompleted = true;
                            biApiCall('GET', '/' + biCurrentSessionId).done(function (sessionResp) {
                                if (sessionResp.success) {
                                    biRenderCountItems(sessionResp.items);
                                    biUpdateWizardButtons();
                                    $('#bi-wizard-sector-label').text('Revisão Final - Todos os Setores');
                                    var startScope = $('#bi-count-scope').text().split(' › ')[0];
                                    $('#bi-count-scope').text(startScope + ' › Revisão Final');
                                }
                            });
                        } else {
                            // Move to next sector
                            biWizardData.current_sector_index = resp.current_sector_index;
                            biInitWizardUI();
                            biLoadCurrentSectorItems();
                        }
                    } else {
                        alert('Erro ao avançar: ' + resp.message);
                    }
                }).fail(function () {
                    alert('Erro ao avançar para próximo setor.');
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            }).fail(function () {
                alert('Erro ao salvar itens do setor atual.');
                $btn.prop('disabled', false);
            });
        }

        // Filter products
        $(document).on('input', '#bi-search-product', function () {
            var q = $(this).val().toLowerCase();
            $('.bi-count-row').each(function () {
                $(this).toggle($(this).data('name').indexOf(q) !== -1);
            });
        });

        // Auto-save on change
        var biSaveTimeout;
        $(document).on('change', '.bi-qty-input', function () {
            var $input = $(this);
            var productId = $input.data('product-id');
            var value = $input.val();
            var $row = $input.closest('tr');

            if (value !== '') {
                $input.css('border-color', '#86efac');
                $row.css('background', '#f0fdf4');
            } else {
                $input.css('border-color', '#ddd');
                $row.css('background', '');
            }

            var filled = $('.bi-qty-input').filter(function () { return $(this).val() !== ''; }).length;
            $('#bi-items-filled').text(filled);

            clearTimeout(biSaveTimeout);
            biSaveTimeout = setTimeout(function () {
                if (value !== '') {
                    biApiCall('PUT', '/' + biCurrentSessionId + '/item/' + productId, {
                        physical_qty: parseFloat(value)
                    });
                }
            }, 500);
        });

        // Save progress
        $('#bi-save-progress').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Salvando...');
            var promises = [];
            $('.bi-qty-input').each(function () {
                var val = $(this).val();
                if (val !== '') {
                    promises.push(biApiCall('PUT', '/' + biCurrentSessionId + '/item/' + $(this).data('product-id'), {
                        physical_qty: parseFloat(val)
                    }));
                }
            });
            $.when.apply($, promises).always(function () {
                $btn.prop('disabled', false).html('💾 Salvar');
                alert('Progresso salvo!');
            });
        });

        // Submit count
        $('#bi-submit-count').on('click', function () {
            var filled = $('.bi-qty-input').filter(function () { return $(this).val() !== ''; }).length;
            var total = $('.bi-qty-input').length;

            if (filled === 0) {
                alert('Insira pelo menos uma quantidade antes de finalizar.');
                return;
            }
            if (filled < total) {
                if (!confirm('Apenas ' + filled + '/' + total + ' itens foram contados. Deseja finalizar mesmo assim?')) return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Processando...');

            biApiCall('POST', '/' + biCurrentSessionId + '/submit').done(function (resp) {
                if (resp.success) {
                    alert('Inventário processado!\n' +
                        'Perdas: ' + resp.summary.losses_created + '\n' +
                        'Ajustes: ' + resp.summary.adjustments_created + '\n' +
                        'Impacto: R$ ' + resp.summary.total_divergence_value.toFixed(2));
                    biOpenReport(biCurrentSessionId);
                } else {
                    alert('Erro: ' + resp.message);
                }
            }).fail(function () {
                alert('Erro ao processar inventário');
            }).always(function () {
                $btn.prop('disabled', false).html('✅ Finalizar');
            });
        });

        // v2.15.0 - Wizard: Next Sector button
        $('#bi-wizard-next-sector').on('click', function () {
            biAdvanceToNextSector();
        });

        // Back to sessions from count
        $('#bi-back-to-sessions').on('click', function () {
            biShowView('sessions');
            biLoadSessions();
        });

        // Continue session
        $(document).on('click', '.bi-continue', function () {
            biOpenCount($(this).data('id'));
        });

        // View report
        $(document).on('click', '.bi-view-report', function () {
            biOpenReport($(this).data('id'));
        });

        // Open report
        function biOpenReport(sessionId) {
            $('#bi-report-session-id').text('#' + sessionId);
            biShowView('report');

            biApiCall('GET', '/' + sessionId + '/report').done(function (resp) {
                if (resp.success) {
                    // Scope
                    var scopeText = '';
                    if (resp.session && resp.session.location_name) {
                        scopeText = '📍 ' + resp.session.location_name;
                        if (resp.session.sector_name) scopeText += ' › ' + resp.session.sector_name;
                    }
                    $('#bi-report-scope').text(scopeText);

                    // Summary cards
                    var sum = resp.summary;
                    var summaryHtml = ''
                        + '<div style="padding:12px; border-radius:6px; background:#f5f5f5; border:1px solid #e0e0e0; text-align:center;"><div style="font-size:20px; font-weight:700;">' + sum.total_items + '</div><div style="font-size:11px; color:#666;">Itens</div></div>'
                        + '<div style="padding:12px; border-radius:6px; background:#fce4ec; border:1px solid #f8bbd0; text-align:center;"><div style="font-size:20px; font-weight:700;">' + sum.items_with_loss + '</div><div style="font-size:11px; color:#666;">Faltas</div></div>'
                        + '<div style="padding:12px; border-radius:6px; background:#e8f5e9; border:1px solid #c8e6c9; text-align:center;"><div style="font-size:20px; font-weight:700;">' + sum.items_with_gain + '</div><div style="font-size:11px; color:#666;">Sobras</div></div>'
                        + '<div style="padding:12px; border-radius:6px; background:' + (sum.net_impact < 0 ? '#fce4ec' : '#e8f5e9') + '; border:1px solid ' + (sum.net_impact < 0 ? '#f8bbd0' : '#c8e6c9') + '; text-align:center;"><div style="font-size:20px; font-weight:700;">R$ ' + sum.net_impact.toFixed(2) + '</div><div style="font-size:11px; color:#666;">Impacto</div></div>';
                    $('#bi-report-summary').html(summaryHtml);

                    // Items table
                    var $body = $('#bi-report-body');
                    $body.empty();
                    resp.items.forEach(function (item) {
                        var divColor = item.divergence < 0 ? '#dc2626' : (item.divergence > 0 ? '#16a34a' : '#888');
                        var statusBadge = item.has_loss
                            ? '<span style="background:#fecaca; color:#991b1b; padding:2px 6px; border-radius:4px; font-size:10px;">Perda</span>'
                            : (item.divergence > 0 ? '<span style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-size:10px;">Ajuste</span>' : '-');

                        $body.append(
                            '<tr style="border-bottom:1px solid #f0f0f0;">' +
                            '<td style="padding:6px 10px; font-size:12px;">' + item.product_name + '</td>' +
                            '<td style="padding:6px 10px; text-align:center; font-size:12px;">' + item.theoretical_qty + ' ' + (item.unit || '') + '</td>' +
                            '<td style="padding:6px 10px; text-align:center; font-size:12px;">' + item.physical_qty + ' ' + (item.unit || '') + '</td>' +
                            '<td style="padding:6px 10px; text-align:center; font-size:12px; color:' + divColor + '; font-weight:600;">' + (item.divergence > 0 ? '+' : '') + item.divergence + '</td>' +
                            '<td style="padding:6px 10px; text-align:right; font-size:12px; color:' + divColor + ';">R$ ' + item.divergence_value.toFixed(2) + '</td>' +
                            '<td style="padding:6px 10px; text-align:center;">' + statusBadge + '</td>' +
                            '</tr>'
                        );
                    });
                }
            });
        }

        // Back to sessions from report
        $('#bi-report-back').on('click', function () {
            biShowView('sessions');
            biLoadSessions();
        });
    });
</script>