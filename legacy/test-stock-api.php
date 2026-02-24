<?php
/**
 * Script de teste para verificar a API REST de estoque
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== TESTE DA API REST DE ESTOQUE ===\n\n";

// 1. Verificar rotas registradas
echo "1. Rotas REST registradas:\n";
echo str_repeat("-", 50) . "\n";

$routes = rest_get_server()->get_routes();
$stock_routes = array_filter(array_keys($routes), function($r) {
    return strpos($r, 'sistur/v1/stock') !== false;
});

foreach ($stock_routes as $route) {
    echo "   " . $route . "\n";
}
echo "\n   Total: " . count($stock_routes) . " rotas\n\n";

// 2. Testar endpoint de unidades
echo "2. Teste GET /sistur/v1/stock/units:\n";
echo str_repeat("-", 50) . "\n";

$request = new WP_REST_Request('GET', '/sistur/v1/stock/units');
$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

// Simular usuário admin
wp_set_current_user(1);

$response = rest_get_server()->dispatch($request);
$data = $response->get_data();

if (is_array($data)) {
    echo "   Unidades encontradas: " . count($data) . "\n";
    foreach (array_slice($data, 0, 5) as $unit) {
        echo "   - " . $unit->name . " (" . $unit->symbol . ")\n";
    }
} else {
    echo "   Erro: " . print_r($data, true) . "\n";
}

// 3. Testar endpoint de produtos
echo "\n3. Teste GET /sistur/v1/stock/products:\n";
echo str_repeat("-", 50) . "\n";

$request = new WP_REST_Request('GET', '/sistur/v1/stock/products');
$response = rest_get_server()->dispatch($request);
$data = $response->get_data();

if (isset($data['products'])) {
    echo "   Produtos encontrados: " . $data['total'] . "\n";
    echo "   Página: " . $data['page'] . " de " . $data['total_pages'] . "\n";
} else {
    echo "   Resposta: " . print_r($data, true) . "\n";
}

// 4. Testar endpoint de locais
echo "\n4. Teste GET /sistur/v1/stock/locations:\n";
echo str_repeat("-", 50) . "\n";

$request = new WP_REST_Request('GET', '/sistur/v1/stock/locations');
$response = rest_get_server()->dispatch($request);
$data = $response->get_data();

if (is_array($data)) {
    echo "   Locais encontrados: " . count($data) . "\n";
    foreach ($data as $loc) {
        echo "   - [" . $loc->code . "] " . $loc->name . "\n";
    }
} else {
    echo "   Erro: " . print_r($data, true) . "\n";
}

echo "\n=== TESTE CONCLUÍDO ===\n";
