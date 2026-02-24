<?php
/**
 * Script para testar add_ingredient diretamente
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== TESTE ADD_INGREDIENT ===\n\n";

// Simular dados vindos do JavaScript
$test_data = array(
    'parent_product_id' => 2,     // Prato Teste
    'child_product_id' => 1,      // Ingrediente Teste
    'quantity_net' => 100,
    'yield_factor' => 1,
    'unit_id' => null
);

echo "1. Dados de teste:\n";
echo str_repeat("-", 50) . "\n";
print_r($test_data);

// Verificar se os produtos existem
global $wpdb;
echo "\n2. Verificar produtos:\n";
echo str_repeat("-", 50) . "\n";

$parent = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sistur_products WHERE id = 2");
$child = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sistur_products WHERE id = 1");

echo "   Parent (ID 2): " . ($parent ? $parent->name : "NÃO ENCONTRADO") . "\n";
echo "   Child (ID 1): " . ($child ? $child->name : "NÃO ENCONTRADO") . "\n";

// Verificar se já existe na receita
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}sistur_recipes WHERE parent_product_id = %d AND child_product_id = %d",
    2, 1
));
echo "   Já existe na receita? " . ($existing ? "SIM (ID: $existing)" : "NÃO") . "\n";

// Tentar adicionar
echo "\n3. Testar add_ingredient:\n";
echo str_repeat("-", 50) . "\n";

$manager = Sistur_Recipe_Manager::get_instance();
$result = $manager->add_ingredient($test_data);

if (is_wp_error($result)) {
    echo "   ✗ ERRO: " . $result->get_error_code() . " - " . $result->get_error_message() . "\n";
    $error_data = $result->get_error_data();
    if ($error_data) {
        echo "   Dados do erro:\n";
        print_r($error_data);
    }
} else {
    echo "   ✓ Sucesso! ID do registro: $result\n";
}

// Se já existe, listar os ingredientes
echo "\n4. Ingredientes atuais da receita (produto 2):\n";
echo str_repeat("-", 50) . "\n";

$ingredients = $manager->get_recipe_ingredients(2);
if (empty($ingredients)) {
    echo "   Nenhum ingrediente\n";
} else {
    foreach ($ingredients as $ing) {
        echo "   - ID {$ing->id}: {$ing->ingredient_name} | Net: {$ing->quantity_net} | Factor: {$ing->yield_factor} | Gross: {$ing->quantity_gross}\n";
    }
}

echo "\n=== TESTE CONCLUÍDO ===\n";
