<?php
/**
 * Script temporário para verificar a estrutura das tabelas do módulo de estoque
 * Execute via: c:\xampp\php\php.exe verify-stock-tables.php
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

global $wpdb;

echo "=== VERIFICAÇÃO DO MÓDULO DE ESTOQUE E CMV ===\n\n";

// Verificar colunas da tabela products
echo "1. Colunas da tabela sistur_products:\n";
echo str_repeat("-", 50) . "\n";
$cols = $wpdb->get_results('SHOW COLUMNS FROM ' . $wpdb->prefix . 'sistur_products');
foreach ($cols as $c) {
    echo sprintf("   %-25s %s\n", $c->Field, $c->Type);
}

echo "\n2. Verificar novas colunas ERP:\n";
echo str_repeat("-", 50) . "\n";
$required = array('type', 'base_unit_id', 'average_cost', 'cached_stock', 'is_perishable');
foreach ($required as $col) {
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = %s 
         AND COLUMN_NAME = %s",
        $wpdb->prefix . 'sistur_products',
        $col
    ));
    $status = $exists ? '✓ OK' : '✗ FALTANDO';
    echo sprintf("   %-20s %s\n", $col, $status);
}

echo "\n3. Tabelas do módulo de estoque:\n";
echo str_repeat("-", 50) . "\n";
require_once(WP_CONTENT_DIR . '/plugins/sistur2/includes/class-sistur-stock-installer.php');
$info = SISTUR_Stock_Installer::get_tables_info();
foreach ($info as $table => $data) {
    $status = $data['exists'] ? '✓' : '✗';
    echo sprintf("   %s %-30s %d registros\n", $status, $data['name'], $data['count']);
}

echo "\n4. Unidades cadastradas:\n";
echo str_repeat("-", 50) . "\n";
$units = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sistur_units');
foreach ($units as $u) {
    echo sprintf("   %-15s (%s) - %s\n", $u->name, $u->symbol, $u->type);
}

echo "\n5. Conversões de unidades:\n";
echo str_repeat("-", 50) . "\n";
$conversions = $wpdb->get_results('
    SELECT c.*, 
           uf.symbol as from_symbol, 
           ut.symbol as to_symbol 
    FROM ' . $wpdb->prefix . 'sistur_unit_conversions c
    JOIN ' . $wpdb->prefix . 'sistur_units uf ON c.from_unit_id = uf.id
    JOIN ' . $wpdb->prefix . 'sistur_units ut ON c.to_unit_id = ut.id
');
foreach ($conversions as $c) {
    echo sprintf("   1 %s = %.4f %s\n", $c->from_symbol, $c->factor, $c->to_symbol);
}

echo "\n6. Locais de armazenamento:\n";
echo str_repeat("-", 50) . "\n";
$locations = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'sistur_storage_locations');
foreach ($locations as $l) {
    echo sprintf("   [%s] %s - %s\n", $l->code, $l->name, $l->location_type);
}

echo "\n=== VERIFICAÇÃO CONCLUÍDA ===\n";
