<?php
/**
 * Script de teste para módulo de receitas
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== TESTE DO MÓDULO DE RECEITAS ===\n\n";

// 1. Criar tabela de receitas
echo "1. Criar/Verificar tabela sistur_recipes:\n";
echo str_repeat("-", 50) . "\n";

require_once(SISTUR_PLUGIN_DIR . 'includes/class-sistur-stock-installer.php');
SISTUR_Stock_Installer::install();

global $wpdb;
$table = $wpdb->prefix . 'sistur_recipes';
$exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
echo "   Tabela $table: " . ($exists ? "✓ Existe" : "✗ Não existe") . "\n";

// 2. Verificar rotas registradas
echo "\n2. Rotas REST para recipes:\n";
echo str_repeat("-", 50) . "\n";

$routes = rest_get_server()->get_routes();
$recipe_routes = array_filter(array_keys($routes), function($r) {
    return strpos($r, 'sistur/v1/recipes') !== false;
});

foreach ($recipe_routes as $route) {
    echo "   " . $route . "\n";
}
echo "   Total: " . count($recipe_routes) . " rotas\n";

// 3. Testar Recipe Manager
echo "\n3. Teste Recipe Manager:\n";
echo str_repeat("-", 50) . "\n";

wp_set_current_user(1);

// Simular criação de produto
$test_parent_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sistur_products WHERE type = 'MANUFACTURED' LIMIT 1");
$test_child_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sistur_products WHERE type = 'RAW' LIMIT 1");

if (!$test_parent_id) {
    // Criar produto prato de teste
    $wpdb->insert($wpdb->prefix . 'sistur_products', [
        'name' => 'Prato Teste',
        'type' => 'MANUFACTURED',
        'status' => 'active',
        'cost_price' => 0
    ]);
    $test_parent_id = $wpdb->insert_id;
    echo "   Produto pai criado: ID $test_parent_id\n";
}

if (!$test_child_id) {
    // Criar produto ingrediente de teste
    $wpdb->insert($wpdb->prefix . 'sistur_products', [
        'name' => 'Ingrediente Teste',
        'type' => 'RAW',
        'status' => 'active',
        'cost_price' => 10.00,
        'average_cost' => 10.00
    ]);
    $test_child_id = $wpdb->insert_id;
    echo "   Produto filho criado: ID $test_child_id\n";
}

echo "   Produto pai (prato): ID $test_parent_id\n";
echo "   Produto filho (ingrediente): ID $test_child_id\n";

// Testar adição de ingrediente
$manager = Sistur_Recipe_Manager::get_instance();

$result = $manager->add_ingredient([
    'parent_product_id' => $test_parent_id,
    'child_product_id' => $test_child_id,
    'quantity_net' => 250,
    'yield_factor' => 2.5,
    'unit_id' => 2 // g
]);

if (is_wp_error($result)) {
    echo "   Adicionar ingrediente: " . $result->get_error_message() . "\n";
} else {
    echo "   ✓ Ingrediente adicionado com ID: $result\n";
}

// Verificar cálculo
echo "\n4. Teste de cálculo:\n";
echo str_repeat("-", 50) . "\n";

$cost = $manager->calculate_plate_cost($test_parent_id);

if (is_wp_error($cost)) {
    echo "   Erro: " . $cost->get_error_message() . "\n";
} else {
    echo "   Custo total do prato: R$ " . number_format($cost['total_cost'], 2, ',', '.') . "\n";
    echo "   Ingredientes: " . $cost['ingredient_count'] . "\n";
    
    if (!empty($cost['ingredients'])) {
        foreach ($cost['ingredients'] as $ing) {
            echo "   - {$ing['ingredient_name']}: " . 
                 "{$ing['quantity_net']}g líquido × fator {$ing['yield_factor']} = " .
                 "{$ing['quantity_gross']}g bruto @ R$ {$ing['unit_cost']}/g = R$ {$ing['ingredient_cost']}\n";
        }
    }
}

// Limpar teste
$wpdb->delete($table, ['parent_product_id' => $test_parent_id]);
echo "\n   ✓ Dados de teste limpos\n";

echo "\n=== TESTE CONCLUÍDO ===\n";
