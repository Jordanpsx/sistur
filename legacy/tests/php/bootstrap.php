<?php
/**
 * PHPUnit Bootstrap File para SISTUR
 *
 * Este arquivo é carregado antes de executar os testes
 *
 * @package SISTUR
 */

// Definir constantes necessárias
define('SISTUR_TESTS', true);
define('ABSPATH', dirname(dirname(dirname(__FILE__))) . '/');

// Carregar WordPress Test Suite se disponível
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Carregar funções WordPress mock se não estiver disponível
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    echo "Could not find $_tests_dir/includes/functions.php\n";
    echo "Please install WordPress test suite or set WP_TESTS_DIR environment variable\n";
    exit(1);
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manualmente carregar o plugin para testes
 */
function _manually_load_plugin() {
    require dirname(dirname(dirname(__FILE__))) . '/sistur.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Iniciar o test suite do WordPress
require $_tests_dir . '/includes/bootstrap.php';

echo "\n\n=================================\n";
echo "SISTUR Test Suite Bootstrap\n";
echo "=================================\n\n";
