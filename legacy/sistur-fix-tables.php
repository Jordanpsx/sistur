<?php
/**
 * SISTUR - Script de Correção de Tabelas
 *
 * Este script corrige problemas com as tabelas wp_sistur_products e wp_sistur_settings
 * Execute este arquivo uma vez via browser ou WP-CLI e depois delete-o.
 *
 * URL: https://seu-site.com/wp-content/plugins/sistur/sistur-fix-tables.php
 *
 * @package SISTUR
 */

// Carregar WordPress
require_once('../../../wp-load.php');

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Você não tem permissão para executar este script.');
}

global $wpdb;

echo "<h1>SISTUR - Correção de Tabelas</h1>";
echo "<pre>";

$charset_collate = $wpdb->get_charset_collate();
$errors = array();
$success = array();

// ==============================================
// 1. CORRIGIR TABELA wp_sistur_products
// ==============================================
echo "\n=== CORRIGINDO TABELA wp_sistur_products ===\n";

$table_products = $wpdb->prefix . 'sistur_products';

// Verificar se a tabela existe
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_products'") === $table_products;

if ($table_exists) {
    echo "✓ Tabela existe. Verificando estrutura...\n";

    // Verificar se há índice duplicado
    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_products WHERE Key_name = 'sku'");

    if (count($indexes) > 1) {
        echo "! Encontrado índice duplicado 'sku'. Removendo...\n";
        $wpdb->query("ALTER TABLE $table_products DROP INDEX sku");
        echo "✓ Índice 'sku' removido.\n";
    }

    // Recriar o índice corretamente
    $has_sku_index = $wpdb->get_var("SHOW INDEX FROM $table_products WHERE Key_name = 'sku'");
    if (!$has_sku_index) {
        $wpdb->query("ALTER TABLE $table_products ADD KEY sku (sku)");
        echo "✓ Índice 'sku' recriado.\n";
    }

    $success[] = "Tabela wp_sistur_products corrigida com sucesso!";

} else {
    echo "! Tabela não existe. Criando...\n";

    $sql = "CREATE TABLE $table_products (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        description text DEFAULT NULL,
        sku varchar(100) DEFAULT NULL,
        barcode varchar(100) DEFAULT NULL,
        category_id mediumint(9) DEFAULT NULL,
        cost_price decimal(10,2) DEFAULT 0.00,
        selling_price decimal(10,2) DEFAULT 0.00,
        min_stock int DEFAULT 0,
        current_stock int DEFAULT 0,
        status enum('active','inactive') DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY sku (sku),
        KEY category_id (category_id),
        KEY status (status)
    ) $charset_collate;";

    $result = $wpdb->query($sql);

    if ($result === false) {
        $errors[] = "Erro ao criar tabela wp_sistur_products: " . $wpdb->last_error;
    } else {
        $success[] = "Tabela wp_sistur_products criada com sucesso!";
    }
}

// ==============================================
// 2. CORRIGIR TABELA wp_sistur_settings
// ==============================================
echo "\n=== CORRIGINDO TABELA wp_sistur_settings ===\n";

$table_settings = $wpdb->prefix . 'sistur_settings';

// Verificar se a tabela existe
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_settings'") === $table_settings;

if ($table_exists) {
    echo "✓ Tabela existe. Verificando estrutura...\n";

    // Verificar se há índice duplicado
    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_settings WHERE Key_name = 'setting_key'");

    if (count($indexes) > 1) {
        echo "! Encontrado índice duplicado 'setting_key'. Removendo...\n";
        $wpdb->query("ALTER TABLE $table_settings DROP INDEX setting_key");
        echo "✓ Índice 'setting_key' removido.\n";
    }

    // Recriar o índice corretamente
    $has_setting_key_index = $wpdb->get_var("SHOW INDEX FROM $table_settings WHERE Key_name = 'setting_key'");
    if (!$has_setting_key_index) {
        $wpdb->query("ALTER TABLE $table_settings ADD KEY setting_key (setting_key)");
        echo "✓ Índice 'setting_key' recriado.\n";
    }

    $success[] = "Tabela wp_sistur_settings corrigida com sucesso!";

} else {
    echo "! Tabela não existe. Criando...\n";

    $sql = "CREATE TABLE $table_settings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_key varchar(100) NOT NULL,
        setting_value text DEFAULT NULL,
        setting_type enum('string','integer','boolean','json') DEFAULT 'string',
        description text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY setting_key (setting_key)
    ) $charset_collate;";

    $result = $wpdb->query($sql);

    if ($result === false) {
        $errors[] = "Erro ao criar tabela wp_sistur_settings: " . $wpdb->last_error;
    } else {
        $success[] = "Tabela wp_sistur_settings criada com sucesso!";

        // Inserir configurações padrão
        echo "\n--- Inserindo configurações padrão ---\n";

        $default_settings = array(
            array(
                'setting_key' => 'tolerance_minutes_per_punch',
                'setting_value' => '5',
                'setting_type' => 'integer',
                'description' => 'Tolerância em minutos para cada batida de ponto'
            ),
            array(
                'setting_key' => 'tolerance_type',
                'setting_value' => 'PER_PUNCH',
                'setting_type' => 'string',
                'description' => 'Tipo de tolerância: PER_PUNCH ou DAILY_ACCUMULATED'
            ),
            array(
                'setting_key' => 'cron_secret_key',
                'setting_value' => wp_generate_password(32, false),
                'setting_type' => 'string',
                'description' => 'Chave secreta para autenticação do endpoint de cron'
            ),
            array(
                'setting_key' => 'auto_processing_enabled',
                'setting_value' => '1',
                'setting_type' => 'boolean',
                'description' => 'Habilita o processamento automático de ponto'
            ),
            array(
                'setting_key' => 'processing_batch_size',
                'setting_value' => '50',
                'setting_type' => 'integer',
                'description' => 'Quantidade de funcionários processados por lote no job'
            ),
            array(
                'setting_key' => 'processing_time',
                'setting_value' => '01:00',
                'setting_type' => 'string',
                'description' => 'Horário de execução do processamento automático (formato HH:MM)'
            )
        );

        foreach ($default_settings as $setting) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_settings WHERE setting_key = %s",
                $setting['setting_key']
            ));

            if (!$exists) {
                $wpdb->insert($table_settings, $setting, array('%s', '%s', '%s', '%s'));
                echo "✓ Configuração '{$setting['setting_key']}' inserida.\n";
            } else {
                echo "- Configuração '{$setting['setting_key']}' já existe.\n";
            }
        }
    }
}

// ==============================================
// 3. VERIFICAR STATUS FINAL
// ==============================================
echo "\n=== STATUS FINAL ===\n";

$products_ok = $wpdb->get_var("SHOW TABLES LIKE '$table_products'") === $table_products;
$settings_ok = $wpdb->get_var("SHOW TABLES LIKE '$table_settings'") === $table_settings;

echo "wp_sistur_products: " . ($products_ok ? "✓ OK" : "✗ ERRO") . "\n";
echo "wp_sistur_settings: " . ($settings_ok ? "✓ OK" : "✗ ERRO") . "\n";

// ==============================================
// RESUMO
// ==============================================
echo "\n=== RESUMO ===\n";

if (!empty($success)) {
    echo "\n✓ SUCESSOS:\n";
    foreach ($success as $msg) {
        echo "  - $msg\n";
    }
}

if (!empty($errors)) {
    echo "\n✗ ERROS:\n";
    foreach ($errors as $msg) {
        echo "  - $msg\n";
    }
}

if (empty($errors)) {
    echo "\n✓✓✓ CORREÇÃO CONCLUÍDA COM SUCESSO! ✓✓✓\n";
    echo "\nVocê pode agora:\n";
    echo "1. Acessar o Dashboard do SISTUR sem erros\n";
    echo "2. Deletar este arquivo (sistur-fix-tables.php) por segurança\n";
} else {
    echo "\n✗✗✗ CORREÇÃO CONCLUÍDA COM ERROS ✗✗✗\n";
    echo "\nPor favor, verifique os erros acima e tente executar o script novamente.\n";
}

echo "</pre>";
?>
