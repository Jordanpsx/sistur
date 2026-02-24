<?php
/**
 * Página de Tipos de Produtos (Insumos Genéricos - RESOURCE)
 *
 * Interface dedicada para gerenciar produtos do tipo RESOURCE.
 * RESOURCE não é um produto físico, apenas agrupa marcas específicas.
 *
 * @package SISTUR
 * @subpackage Stock
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;
$prefix = $wpdb->prefix . 'sistur_';

// Buscar RESOURCEs com:
// - Contagem de filhos
// - Estoque agregado dos filhos (convertido usando content_quantity E fator de conversão)
// - Preço médio ponderado dos filhos
$resources = $wpdb->get_results("
    SELECT 
        p.id,
        p.name,
        p.description,
        u.name as unit_name,
        u.symbol as unit_symbol,
        p.base_unit_id,
        (SELECT COUNT(*) 
         FROM {$prefix}products c 
         WHERE c.resource_parent_id = p.id AND c.status = 'active') as child_count,
        -- Estoque total convertido:
        -- quantidade × content_quantity × fator_conversão
        -- O fator converte a content_unit para a unidade base do RESOURCE (ex: mL → L = 0.001)
        (SELECT COALESCE(SUM(
            b.quantity * COALESCE(c.content_quantity, 1) 
            * COALESCE(
                (SELECT uc.factor FROM {$prefix}unit_conversions uc 
                 WHERE uc.from_unit_id = c.content_unit_id 
                 AND uc.to_unit_id = p.base_unit_id 
                 AND uc.product_id IS NULL LIMIT 1),
                1
            )
        ), 0) 
         FROM {$prefix}inventory_batches b 
         INNER JOIN {$prefix}products c ON b.product_id = c.id 
         WHERE c.resource_parent_id = p.id 
         AND b.status = 'active'
         AND b.quantity > 0) as total_stock,
        -- Custo médio por unidade do RESOURCE (kg, L, etc)
        -- = custo total / quantidade total convertida
        (SELECT ROUND(
            COALESCE(
                SUM(b.quantity * b.cost_price) / NULLIF(SUM(
                    b.quantity * COALESCE(c.content_quantity, 1)
                    * COALESCE(
                        (SELECT uc.factor FROM {$prefix}unit_conversions uc 
                         WHERE uc.from_unit_id = c.content_unit_id 
                         AND uc.to_unit_id = p.base_unit_id 
                         AND uc.product_id IS NULL LIMIT 1),
                        1
                    )
                ), 0), 
                0
            ), 4)
         FROM {$prefix}inventory_batches b 
         INNER JOIN {$prefix}products c ON b.product_id = c.id 
         WHERE c.resource_parent_id = p.id 
         AND b.status = 'active'
         AND b.quantity > 0
         AND b.cost_price > 0) as average_cost
    FROM {$prefix}products p
    LEFT JOIN {$prefix}units u ON p.base_unit_id = u.id
    WHERE FIND_IN_SET('RESOURCE', p.type) > 0 AND p.status = 'active'
    ORDER BY p.name ASC
");

// Buscar unidades para o formulário
$units = $wpdb->get_results("SELECT id, name, symbol, type FROM {$prefix}units ORDER BY type, name");
?>

<div class="wrap sistur-product-types">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-category" style="font-size: 28px; margin-right: 8px;"></span>
        <?php _e('Tipos de Produtos (Insumos Genéricos)', 'sistur'); ?>
    </h1>
    
    <a href="#" id="btn-new-resource" class="page-title-action">
        <span class="dashicons dashicons-plus-alt2"></span>
        <?php _e('Novo Tipo', 'sistur'); ?>
    </a>

    <hr class="wp-header-end">

    <div class="notice notice-info" style="margin: 20px 0;">
        <p>
            <strong><?php _e('O que são Insumos Genéricos?', 'sistur'); ?></strong><br>
            <?php _e('São tipos conceituais como "Arroz" ou "Óleo" que agrupam marcas específicas. Ao criar uma receita com um tipo genérico, o sistema consome automaticamente de qualquer marca vinculada.', 'sistur'); ?>
        </p>
        <p style="margin-top: 10px;">
            <strong><?php _e('Como usar:', 'sistur'); ?></strong>
            <?php _e('1) Crie o tipo genérico aqui → 2) Na Gestão de Estoque, vincule produtos a este tipo → 3) Use o tipo genérico nas receitas', 'sistur'); ?>
        </p>
    </div>

    <?php if (empty($resources)): ?>
    <div class="notice notice-warning">
        <p><?php _e('Nenhum tipo de produto cadastrado. Clique em "Novo Tipo" para começar.', 'sistur'); ?></p>
    </div>
    <?php else: ?>
    <table class="wp-list-table widefat fixed striped" id="resources-table">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-name" style="width: 30%;"><?php _e('Nome', 'sistur'); ?></th>
                <th scope="col" class="manage-column" style="width: 100px;"><?php _e('Unidade', 'sistur'); ?></th>
                <th scope="col" class="manage-column" style="width: 180px;"><?php _e('Marcas Vinculadas', 'sistur'); ?></th>
                <th scope="col" class="manage-column" style="width: 130px; text-align: right;"><?php _e('Estoque Total', 'sistur'); ?></th>
                <th scope="col" class="manage-column" style="width: 130px; text-align: right;"><?php _e('Custo Médio', 'sistur'); ?></th>
                <th scope="col" class="manage-column" style="width: 100px;"><?php _e('Ações', 'sistur'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resources as $res): ?>
            <tr data-id="<?php echo esc_attr($res->id); ?>">
                <td>
                    <strong style="font-size: 14px;"><?php echo esc_html($res->name); ?></strong>
                    <?php if ($res->description): ?>
                    <br><span class="description"><?php echo esc_html(wp_trim_words($res->description, 10)); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="unit-badge"><?php echo esc_html($res->unit_symbol ?: $res->unit_name ?: '-'); ?></span>
                </td>
                <td>
                    <?php if ($res->child_count > 0): ?>
                    <a href="#" class="view-children" data-id="<?php echo esc_attr($res->id); ?>" data-name="<?php echo esc_attr($res->name); ?>">
                        <span class="dashicons dashicons-networking" style="font-size: 16px; vertical-align: text-bottom;"></span>
                        <?php printf(_n('%d marca', '%d marcas', $res->child_count, 'sistur'), $res->child_count); ?>
                    </a>
                    <?php else: ?>
                    <span class="description" style="color: #d63638;">
                        <span class="dashicons dashicons-warning" style="font-size: 14px;"></span>
                        <?php _e('Sem marcas', 'sistur'); ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td style="text-align: right;">
                    <strong style="font-size: 14px;"><?php echo number_format((float)$res->total_stock, 3, ',', '.'); ?></strong>
                    <span class="unit-symbol"><?php echo esc_html($res->unit_symbol ?: ''); ?></span>
                </td>
                <td style="text-align: right;">
                    <?php if ($res->average_cost > 0): ?>
                    <strong>R$ <?php echo number_format((float)$res->average_cost, 4, ',', '.'); ?></strong>
                    <?php else: ?>
                    <span class="description">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="button button-small edit-resource" 
                            data-id="<?php echo esc_attr($res->id); ?>"
                            data-name="<?php echo esc_attr($res->name); ?>"
                            data-unit="<?php echo esc_attr($res->base_unit_id); ?>"
                            data-description="<?php echo esc_attr($res->description); ?>"
                            title="<?php _e('Editar', 'sistur'); ?>">
                        <span class="dashicons dashicons-edit" style="font-size: 14px;"></span>
                    </button>
                    <button type="button" class="button button-small delete-resource" 
                            data-id="<?php echo esc_attr($res->id); ?>"
                            data-name="<?php echo esc_attr($res->name); ?>"
                            data-children="<?php echo esc_attr($res->child_count); ?>"
                            title="<?php _e('Excluir', 'sistur'); ?>">
                        <span class="dashicons dashicons-trash" style="font-size: 14px;"></span>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Modal: Novo/Editar RESOURCE (formulário simplificado) -->
<div id="modal-resource" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 500px;">
        <div class="sistur-modal-header">
            <h2 id="modal-resource-title"><?php _e('Novo Tipo de Produto', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <form id="form-resource">
                <input type="hidden" id="resource-id" name="id" value="">
                <input type="hidden" name="type" value="RESOURCE">
                
                <table class="form-table">
                    <tr>
                        <th><label for="resource-name"><?php _e('Nome *', 'sistur'); ?></label></th>
                        <td>
                            <input type="text" id="resource-name" name="name" class="regular-text" required 
                                   placeholder="<?php _e('Ex: Arroz, Óleo, Carne Bovina', 'sistur'); ?>">
                            <p class="description"><?php _e('Nome genérico do insumo (sem marca)', 'sistur'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resource-unit"><?php _e('Unidade *', 'sistur'); ?></label></th>
                        <td>
                            <select id="resource-unit" name="base_unit_id" required>
                                <option value=""><?php _e('Selecione...', 'sistur'); ?></option>
                                <?php foreach ($units as $unit): ?>
                                <option value="<?php echo esc_attr($unit->id); ?>">
                                    <?php echo esc_html($unit->name . ' (' . $unit->symbol . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Unidade usada para agregar estoque (ex: kg, L)', 'sistur'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resource-description"><?php _e('Descrição', 'sistur'); ?></label></th>
                        <td>
                            <textarea id="resource-description" name="description" rows="3" class="large-text" 
                                      placeholder="<?php _e('Observações opcionais...', 'sistur'); ?>"></textarea>
                        </td>
                    </tr>
                </table>
                
                <div class="notice notice-warning inline" style="margin: 15px 0 0 0;">
                    <p>
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php _e('Tipos genéricos não possuem estoque próprio. O estoque é calculado a partir das marcas vinculadas.', 'sistur'); ?>
                    </p>
                </div>
            </form>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-cancel"><?php _e('Cancelar', 'sistur'); ?></button>
            <button type="button" class="button button-primary" id="btn-save-resource"><?php _e('Salvar', 'sistur'); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Ver Marcas Vinculadas -->
<div id="modal-children" class="sistur-modal" style="display: none;">
    <div class="sistur-modal-content" style="max-width: 700px;">
        <div class="sistur-modal-header">
            <h2 id="modal-children-title"><?php _e('Marcas Vinculadas', 'sistur'); ?></h2>
            <button type="button" class="sistur-modal-close">&times;</button>
        </div>
        <div class="sistur-modal-body">
            <table class="wp-list-table widefat fixed striped" id="children-table">
                <thead>
                    <tr>
                        <th style="width: 40%;"><?php _e('Produto', 'sistur'); ?></th>
                        <th style="width: 15%;"><?php _e('SKU', 'sistur'); ?></th>
                        <th style="width: 20%; text-align: right;"><?php _e('Estoque', 'sistur'); ?></th>
                        <th style="width: 25%; text-align: right;"><?php _e('Custo Médio', 'sistur'); ?></th>
                    </tr>
                </thead>
                <tbody id="children-tbody">
                </tbody>
            </table>
        </div>
        <div class="sistur-modal-footer">
            <button type="button" class="button sistur-modal-cancel"><?php _e('Fechar', 'sistur'); ?></button>
        </div>
    </div>
</div>

<style>
.sistur-product-types .unit-badge {
    display: inline-block;
    background: #e7f3ff;
    padding: 4px 10px;
    border-radius: 4px;
    font-weight: 600;
    color: #0073aa;
    font-size: 12px;
}

.sistur-product-types .unit-symbol {
    color: #666;
    font-size: 12px;
    margin-left: 3px;
}

.sistur-product-types .view-children {
    text-decoration: none;
    color: #0073aa;
}

.sistur-product-types .view-children:hover {
    color: #005a8c;
    text-decoration: underline;
}

.sistur-product-types .wp-list-table td {
    vertical-align: middle;
}

.sistur-product-types .button-small .dashicons {
    width: 14px;
    height: 14px;
    vertical-align: middle;
    margin-top: -2px;
}

/* Modal Styles */
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
}

.sistur-modal-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #0073aa 0%, #005a8c 100%);
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
    background: none;
    border: none;
    line-height: 1;
}

.sistur-modal-close:hover {
    color: #ffcc00;
}

.sistur-modal-body {
    padding: 20px;
    max-height: 65vh;
    overflow-y: auto;
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const apiBase = '<?php echo esc_url(rest_url('sistur/v1/stock/')); ?>';
    const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';

    // Novo RESOURCE
    document.getElementById('btn-new-resource')?.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('form-resource').reset();
        document.getElementById('resource-id').value = '';
        document.getElementById('modal-resource-title').textContent = '<?php _e('Novo Tipo de Produto', 'sistur'); ?>';
        document.getElementById('modal-resource').style.display = 'flex';
        document.getElementById('resource-name').focus();
    });

    // Editar RESOURCE
    document.querySelectorAll('.edit-resource').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('resource-id').value = this.dataset.id;
            document.getElementById('resource-name').value = this.dataset.name;
            document.getElementById('resource-unit').value = this.dataset.unit;
            document.getElementById('resource-description').value = this.dataset.description || '';
            document.getElementById('modal-resource-title').textContent = '<?php _e('Editar Tipo de Produto', 'sistur'); ?>';
            document.getElementById('modal-resource').style.display = 'flex';
        });
    });

    // Excluir RESOURCE
    document.querySelectorAll('.delete-resource').forEach(btn => {
        btn.addEventListener('click', async function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const children = parseInt(this.dataset.children) || 0;

            let msg = `Excluir o tipo "${name}"?`;
            if (children > 0) {
                msg += `\n\n⚠️ Atenção: ${children} produto(s) vinculado(s) perderão a associação.`;
            }

            if (!confirm(msg)) return;

            try {
                const response = await fetch(apiBase + `products/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': nonce }
                });

                if (response.ok) {
                    window.location.reload();
                } else {
                    const result = await response.json();
                    alert(result.message || '<?php _e('Erro ao excluir', 'sistur'); ?>');
                }
            } catch (error) {
                alert('<?php _e('Erro de conexão', 'sistur'); ?>');
            }
        });
    });

    // Salvar RESOURCE
    document.getElementById('btn-save-resource')?.addEventListener('click', async function() {
        const form = document.getElementById('form-resource');
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            if (value !== '') data[key] = value;
        });

        if (!data.name || !data.base_unit_id) {
            alert('<?php _e('Preencha o nome e a unidade', 'sistur'); ?>');
            return;
        }

        const id = document.getElementById('resource-id').value;
        const method = id ? 'PUT' : 'POST';
        const endpoint = id ? `products/${id}` : 'products';

        this.disabled = true;
        this.textContent = '<?php _e('Salvando...', 'sistur'); ?>';

        try {
            const response = await fetch(apiBase + endpoint, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (response.ok) {
                window.location.reload();
            } else {
                alert(result.message || '<?php _e('Erro ao salvar', 'sistur'); ?>');
            }
        } catch (error) {
            alert('<?php _e('Erro de conexão', 'sistur'); ?>');
        } finally {
            this.disabled = false;
            this.textContent = '<?php _e('Salvar', 'sistur'); ?>';
        }
    });

    // Ver marcas vinculadas
    document.querySelectorAll('.view-children').forEach(link => {
        link.addEventListener('click', async function(e) {
            e.preventDefault();
            const resourceId = this.dataset.id;
            const resourceName = this.dataset.name;

            document.getElementById('modal-children-title').textContent = 
                'Marcas de "' + resourceName + '"';

            const tbody = document.getElementById('children-tbody');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px;"><?php _e('Carregando...', 'sistur'); ?></td></tr>';
            document.getElementById('modal-children').style.display = 'flex';

            try {
                const response = await fetch(apiBase + 'products?per_page=100&resource_parent_id=' + resourceId, {
                    headers: { 'X-WP-Nonce': nonce }
                });
                const data = await response.json();

                // Filter products that have this resource_parent_id
                const children = (data.products || []).filter(p => p.resource_parent_id == resourceId);

                if (children.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px; color: #666;"><?php _e('Nenhuma marca vinculada ainda', 'sistur'); ?></td></tr>';
                } else {
                    tbody.innerHTML = children.map(p => `
                        <tr>
                            <td>
                                <strong>${escapeHtml(p.name)}</strong>
                                ${p.barcode ? '<br><small style="color:#666;">Cód: ' + escapeHtml(p.barcode) + '</small>' : ''}
                            </td>
                            <td><code>${escapeHtml(p.sku || '-')}</code></td>
                            <td style="text-align:right;">
                                <strong>${formatNumber(p.real_stock || 0)}</strong> 
                                <span style="color:#666">${escapeHtml(p.unit_symbol || '')}</span>
                            </td>
                            <td style="text-align:right;">
                                ${p.average_cost ? 'R$ ' + formatNumber(p.average_cost, 4) : '-'}
                            </td>
                        </tr>
                    `).join('');
                }
            } catch (error) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:red;"><?php _e('Erro ao carregar', 'sistur'); ?></td></tr>';
            }
        });
    });

    // Fechar modais
    document.querySelectorAll('.sistur-modal-close, .sistur-modal-cancel').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.sistur-modal').style.display = 'none';
        });
    });

    // Fechar ao clicar fora
    document.querySelectorAll('.sistur-modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // ESC to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.sistur-modal').forEach(m => m.style.display = 'none');
        }
    });

    // Helpers
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatNumber(value, decimals = 3) {
        return parseFloat(value || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }
});
</script>
