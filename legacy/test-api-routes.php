<?php
/**
 * Script de teste para verificar rotas REST da API
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== VERIFICAÇÃO DE ROTAS REST API ===\n\n";

// 1. Verificar namespace
echo "1. Verificar constante API_NAMESPACE:\n";
echo str_repeat("-", 50) . "\n";

if (class_exists('SISTUR_Stock_API')) {
    $reflection = new ReflectionClass('SISTUR_Stock_API');
    $constants = $reflection->getConstants();
    echo "   API_NAMESPACE: " . ($constants['API_NAMESPACE'] ?? 'NÃO DEFINIDO') . "\n";
} else {
    echo "   ✗ Classe SISTUR_Stock_API não existe!\n";
}

// 2. Listar todas as rotas registradas
echo "\n2. Rotas registradas no namespace sistur/v1:\n";
echo str_repeat("-", 50) . "\n";

$server = rest_get_server();
$routes = $server->get_routes();

$sistur_routes = array_filter(array_keys($routes), function($route) {
    return strpos($route, '/sistur/v1') !== false;
});

if (empty($sistur_routes)) {
    echo "   ✗ Nenhuma rota SISTUR registrada!\n";
} else {
    foreach ($sistur_routes as $route) {
        echo "   " . $route . "\n";
    }
}

// 3. Verificar especificamente rotas de recipes
echo "\n3. Rotas de recipes:\n";
echo str_repeat("-", 50) . "\n";

$recipe_routes = array_filter($sistur_routes, function($route) {
    return strpos($route, 'recipes') !== false;
});

if (empty($recipe_routes)) {
    echo "   ✗ Nenhuma rota de recipes encontrada!\n";
    echo "   Verifique se as rotas estão sendo registradas em register_routes()\n";
} else {
    echo "   ✓ Rotas de recipes encontradas:\n";
    foreach ($recipe_routes as $route) {
        echo "   - $route\n";
        $route_data = $routes[$route];
        foreach ($route_data as $endpoint) {
            $methods = is_array($endpoint['methods']) ? implode(', ', array_keys($endpoint['methods'])) : '';
            echo "     Métodos: $methods\n";
        }
    }
}

// 4. Verificar hooks registrados
echo "\n4. Verificar se rest_api_init está configurado:\n";
echo str_repeat("-", 50) . "\n";

global $wp_filter;
$rest_hooks = isset($wp_filter['rest_api_init']) ? $wp_filter['rest_api_init'] : null;

if ($rest_hooks) {
    echo "   ✓ Hook rest_api_init tem callbacks registrados\n";
    foreach ($rest_hooks->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $id => $callback) {
            if (is_array($callback['function'])) {
                $class = is_object($callback['function'][0]) ? get_class($callback['function'][0]) : $callback['function'][0];
                $method = $callback['function'][1];
                if (strpos($class, 'SISTUR') !== false || strpos($class, 'Sistur') !== false) {
                    echo "   - [$priority] $class::$method\n";
                }
            }
        }
    }
} else {
    echo "   ✗ Nenhum callback em rest_api_init!\n";
}

echo "\n=== TESTE CONCLUÍDO ===\n";
