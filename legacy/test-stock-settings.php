<?php
/**
 * Script de teste para verificar endpoints de configurações de estoque
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== TESTE DA API DE CONFIGURAÇÕES DE ESTOQUE ===\n\n";

// 1. Verificar rotas registradas
echo "1. Rotas REST para stock:\n";
echo str_repeat("-", 50) . "\n";

$routes = rest_get_server()->get_routes();
$stock_routes = array_filter(array_keys($routes), function($r) {
    return strpos($r, 'sistur/v1/stock') !== false;
});

foreach ($stock_routes as $route) {
    echo "   " . $route . "\n";
}
echo "\n   Total: " . count($stock_routes) . " rotas\n\n";

// 2. Simular admin
wp_set_current_user(1);

// 3. Testar POST /stock/units (criar unidade)
echo "2. Teste POST /stock/units:\n";
echo str_repeat("-", 50) . "\n";

$request = new WP_REST_Request('POST', '/sistur/v1/stock/units');
$request->set_header('Content-Type', 'application/json');
$request->set_body(json_encode([
    'name' => 'Teste Unidade',
    'symbol' => 'TU',
    'type' => 'unitary'
]));

$response = rest_get_server()->dispatch($request);
$data = $response->get_data();

if (isset($data['success']) && $data['success']) {
    $test_unit_id = $data['id'];
    echo "   ✓ Unidade criada com ID: $test_unit_id\n";
    
    // Deletar a unidade de teste
    $del_request = new WP_REST_Request('DELETE', "/sistur/v1/stock/units/$test_unit_id");
    rest_get_server()->dispatch($del_request);
    echo "   ✓ Unidade de teste excluída\n";
} else {
    echo "   Resposta: " . json_encode($data) . "\n";
}

// 4. Testar POST /stock/locations (criar local)
echo "\n3. Teste POST /stock/locations:\n";
echo str_repeat("-", 50) . "\n";

$request = new WP_REST_Request('POST', '/sistur/v1/stock/locations');
$request->set_header('Content-Type', 'application/json');
$request->set_body(json_encode([
    'name' => 'Dispensa Teste',
    'location_type' => 'shelf'
]));

$response = rest_get_server()->dispatch($request);
$data = $response->get_data();

if (isset($data['success']) && $data['success']) {
    $test_loc_id = $data['id'];
    echo "   ✓ Local criado com ID: $test_loc_id\n";
    
    // Deletar o local de teste
    $del_request = new WP_REST_Request('DELETE', "/sistur/v1/stock/locations/$test_loc_id");
    rest_get_server()->dispatch($del_request);
    echo "   ✓ Local de teste excluído\n";
} else {
    echo "   Resposta: " . json_encode($data) . "\n";
}

// 5. Verificar que rotas de update existem
echo "\n4. Verificação de métodos HTTP:\n";
echo str_repeat("-", 50) . "\n";

$check_routes = [
    '/sistur/v1/stock/units/(?P<id>\d+)' => ['PUT', 'DELETE'],
    '/sistur/v1/stock/locations/(?P<id>\d+)' => ['PUT', 'DELETE']
];

foreach ($check_routes as $route => $methods) {
    if (isset($routes[$route])) {
        echo "   ✓ $route existe\n";
    } else {
        echo "   ✗ $route NÃO encontrada\n";
    }
}

echo "\n=== TESTE CONCLUÍDO ===\n";
