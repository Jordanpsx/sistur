<?php
/**
 * Dashboard de Inventário com Volume por Categoria
 *
 * @package SISTUR
 * @since 2.3.0
 */

if (!current_user_can('manage_options')) {
    wp_die(__('Você não tem permissão para acessar esta página.', 'sistur'));
}

global $wpdb;
$products_table = $wpdb->prefix . 'sistur_products';
$categories_table = $wpdb->prefix . 'sistur_product_categories';
$units_table = $wpdb->prefix . 'sistur_units';
$batches_table = $wpdb->prefix . 'sistur_inventory_batches';

$unit_conversions_table = $wpdb->prefix . 'sistur_unit_conversions';

// Volume por Produto/Grupo - Lógica Unificada (v2.7)
// Agrupa por PAI (se houver) ou pelo próprio produto
// Converte tudo para a unidade base do PAI/Produto para somar volumes corretos
$category_volumes = $wpdb->get_results("
    SELECT 
        -- Nome do Grupo
        CASE 
            WHEN (p.type LIKE '%RAW%' OR p.type LIKE '%MANUFACTURED%') AND p.resource_parent_id > 0 THEN parent.name 
            ELSE p.name 
        END as category_name,
        
        -- ID do Grupo
        CASE 
            WHEN (p.type LIKE '%RAW%' OR p.type LIKE '%MANUFACTURED%') AND p.resource_parent_id > 0 THEN parent.id 
            ELSE p.id 
        END as group_id,

        -- Unidade de Exibição
        COALESCE(u_target.symbol, u_content.symbol) as content_unit,
        COALESCE(u_target.name, u_content.name) as content_unit_name,
        
        -- Estoque Mínimo do Grupo (Do Pai se houver, ou do Próprio)
        MAX(COALESCE(
            CASE 
                WHEN (p.type LIKE '%RAW%' OR p.type LIKE '%MANUFACTURED%') AND p.resource_parent_id > 0 THEN parent.min_stock 
                ELSE p.min_stock 
            END, 
        0)) as group_min_stock,
        
        COUNT(DISTINCT p.id) as product_count,
        
        -- Soma com conversão
        SUM(
            COALESCE(
                (SELECT SUM(b.quantity) FROM $batches_table b WHERE b.product_id = p.id AND b.status = 'active'),
                p.current_stock
            ) 
            * COALESCE(p.content_quantity, 1)
            * COALESCE(uc.factor, 1)
        ) as total_volume

    FROM $products_table p
    LEFT JOIN $products_table parent ON p.resource_parent_id = parent.id
    LEFT JOIN $units_table u_content ON p.content_unit_id = u_content.id
    LEFT JOIN $units_table u_target ON 
        CASE 
            WHEN (p.type LIKE '%RAW%' OR p.type LIKE '%MANUFACTURED%') AND p.resource_parent_id > 0 THEN parent.base_unit_id 
            ELSE p.base_unit_id 
        END = u_target.id
    LEFT JOIN $unit_conversions_table uc ON 
        uc.from_unit_id = p.content_unit_id 
        AND uc.to_unit_id = u_target.id 
        AND uc.product_id IS NULL

    WHERE p.status = 'active' 
      AND p.content_quantity IS NOT NULL 
      AND p.content_unit_id IS NOT NULL 
      AND (p.type NOT LIKE '%RESOURCE%') -- Garante que não conta o pai duplicado se ele aparecer na lista principal

    GROUP BY group_id, COALESCE(u_target.id, p.content_unit_id)
    ORDER BY category_name, content_unit
", ARRAY_A);

// Calcular Totais baseados na visualização agrupada
$total_products = count($category_volumes);
$low_stock = 0;

foreach ($category_volumes as $item) {
    if ((float) $item['total_volume'] <= (float) $item['group_min_stock']) {
        $low_stock++;
    }
}

// Buscar todas as unidades para uso na interface
$units = $wpdb->get_results("SELECT id, name, symbol, type FROM $units_table ORDER BY type, name", ARRAY_A);
?>

<div class="wrap">
    <h1><?php _e('Gestão de Estoque', 'sistur'); ?></h1>

    <!-- Cards de Resumo -->
    <div class="sistur-cards"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; color: #64748b; font-size: 14px; text-transform: uppercase;">
                <?php _e('Total de Produtos', 'sistur'); ?>
            </h3>
            <p style="font-size: 36px; font-weight: bold; color: #2271b1; margin: 10px 0;">
                <?php echo esc_html($total_products); ?>
            </p>
        </div>

        <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 10px 0; color: #64748b; font-size: 14px; text-transform: uppercase;">
                <?php _e('Estoque Baixo', 'sistur'); ?>
            </h3>
            <p style="font-size: 36px; font-weight: bold; color: #d63638; margin: 10px 0;">
                <?php echo esc_html($low_stock); ?>
            </p>
        </div>
    </div>

    <!-- Volume por Categoria - Novo Requisito v2.3 -->
    <?php if (!empty($category_volumes)): ?>
        <div
            style="background: white; padding: 20px; margin-top: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2
                style="margin: 0 0 20px 0; color: #1e293b; border-bottom: 2px solid #f59e0b; padding-bottom: 10px; display: inline-block;">
                <?php _e('Volume Total por Categoria', 'sistur'); ?>
            </h2>
            <p style="color: #64748b; margin-bottom: 20px; font-size: 14px;">
                <?php _e('Cálculo baseado em: Quantidade em Estoque × Conteúdo da Embalagem', 'sistur'); ?>
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
                <?php foreach ($category_volumes as $cat): ?>
                    <div
                        style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 20px; border-radius: 12px; border-left: 4px solid #0d9488;">
                        <h4 style="margin: 0 0 8px 0; color: #334155; font-size: 16px;">
                            <?php echo esc_html($cat['category_name']); ?>
                        </h4>
                        <p style="margin: 0; color: #64748b; font-size: 13px;">
                            <?php printf(__('%d produto(s)', 'sistur'), $cat['product_count']); ?>
                        </p>
                        <p style="font-size: 28px; font-weight: 700; color: #0d9488; margin: 10px 0 0 0;">
                            <?php
                            // Formatar número com 2 casas decimais
                            $formatted_volume = number_format((float) $cat['total_volume'], 2, ',', '.');
                            echo esc_html($formatted_volume);
                            ?>
                            <span style="font-size: 16px; font-weight: 500; color: #64748b;">
                                <?php echo esc_html($cat['content_unit'] ?: 'un'); ?>
                            </span>
                        </p>
                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;">
                            <?php _e('Total Disponível', 'sistur'); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div style="background: #fefce8; padding: 20px; margin-top: 20px; border-radius: 8px; border: 1px solid #fde047;">
            <h3 style="margin: 0 0 10px 0; color: #854d0e;">
                <span class="dashicons dashicons-info" style="margin-right: 8px;"></span>
                <?php _e('Volume por Categoria não disponível', 'sistur'); ?>
            </h3>
            <p style="margin: 0; color: #a16207;">
                <?php _e('Para visualizar o volume total por categoria, preencha os campos "Conteúdo da Embalagem" e "Unidade do Conteúdo" nos produtos.', 'sistur'); ?>
            </p>
            <p style="margin: 10px 0 0 0; color: #a16207;">
                <strong><?php _e('Exemplo:', 'sistur'); ?></strong>
                <?php _e('Uma lata de óleo de 900ml deve ter Conteúdo = 0.9 e Unidade = Litros', 'sistur'); ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Funcionalidades -->
    <div
        style="background: white; padding: 20px; margin-top: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="margin: 0 0 15px 0;"><?php _e('Funcionalidades do Estoque', 'sistur'); ?></h2>
        <ul style="color: #64748b; line-height: 1.8;">
            <li><?php _e('Gerenciamento de produtos e categorias', 'sistur'); ?></li>
            <li><?php _e('Controle de movimentações (entradas, saídas, ajustes)', 'sistur'); ?></li>
            <li><?php _e('Alertas de estoque baixo', 'sistur'); ?></li>
            <li><?php _e('Relatórios de movimentação', 'sistur'); ?></li>
            <li><strong
                    style="color: #0d9488;"><?php _e('Novo: Volume total por categoria (Litros, Kg)', 'sistur'); ?></strong>
            </li>
        </ul>
    </div>
</div>