<?php
/**
 * Script de teste para Unit Converter e validação de produção
 */

chdir('c:/xampp/htdocs/trabalho/wordpress');
require_once('wp-load.php');

echo "=== TESTE DO UNIT CONVERTER E VALIDAÇÃO ===\n\n";

// 1. Verificar classe existe
echo "1. Verificar classes:\n";
echo str_repeat("-", 50) . "\n";

$converter_exists = class_exists('Sistur_Unit_Converter');
$manager_exists = class_exists('Sistur_Recipe_Manager');

echo "   Sistur_Unit_Converter: " . ($converter_exists ? "✓ Existe" : "✗ Não existe") . "\n";
echo "   Sistur_Recipe_Manager: " . ($manager_exists ? "✓ Existe" : "✗ Não existe") . "\n";

// 2. Testar validação de unidade
echo "\n2. Testar validação de unidade:\n";
echo str_repeat("-", 50) . "\n";

$converter = Sistur_Unit_Converter::get_instance();

// Teste com unidade válida
$valid = $converter->validate_unit(1);
echo "   Validar unit_id=1: " . (is_wp_error($valid) ? "✗ " . $valid->get_error_message() : "✓ Válida") . "\n";

// Teste com unidade inválida
$invalid = $converter->validate_unit(9999);
echo "   Validar unit_id=9999: " . (is_wp_error($invalid) ? "✓ Erro: unidade inexistente" : "✗ Deveria falhar") . "\n";

// Teste impedir unidade null se obrigatória
$null = $converter->validate_unit(null);
echo "   Validar unit_id=null: " . (is_wp_error($null) ? "✓ Erro: unidade obrigatória" : "✗ Deveria falhar") . "\n";

// 3. Testar conversão (caso não exista, deve retornar erro)
echo "\n3. Testar conversão:\n";
echo str_repeat("-", 50) . "\n";

// Verificar se há conversões na tabela
global $wpdb;
$conversions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sistur_unit_conversions");
echo "   Conversões cadastradas: $conversions\n";

// Testar converter mesma unidade (deve funcionar)
$same = $converter->convert(100, 1, 1);
echo "   Converter 100 de ID 1 para ID 1: " . (is_wp_error($same) ? "✗ Erro" : "✓ $same (sem conversão)") . "\n";

// Testar converter para unidade diferente (deve falhar se não há conversão)
$diff = $converter->convert(100, 1, 2);
if (is_wp_error($diff)) {
    echo "   Converter 100 de ID 1 para ID 2: ✓ Erro (conversão não definida)\n";
    echo "   Mensagem: " . $diff->get_error_message() . "\n";
} else {
    echo "   Converter 100 de ID 1 para ID 2: Resultado = $diff\n";
}

// 4. Verificar estrutura da tabela inventory_transactions
echo "\n4. Estrutura da tabela inventory_transactions:\n";
echo str_repeat("-", 50) . "\n";

$columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}sistur_inventory_transactions");
echo "   Colunas:\n";
foreach ($columns as $col) {
    echo "   - {$col->Field} ({$col->Type})" . ($col->Null === 'NO' ? ' NOT NULL' : '') . "\n";
}

// 5. Listar unidades existentes
echo "\n5. Unidades cadastradas:\n";
echo str_repeat("-", 50) . "\n";

$units = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sistur_units");
foreach ($units as $u) {
    echo "   ID {$u->id}: {$u->name} ({$u->symbol})\n";
}

echo "\n=== TESTE CONCLUÍDO ===\n";
