<?php
/**
 * SISTUR Database Check - Verificação de Schema
 *
 * INSTRUÇÕES:
 * 1. Coloque este arquivo na raiz do WordPress
 * 2. Acesse: http://seusite.com/sistur-db-check.php
 * 3. Leia os resultados e siga as instruções
 * 4. DELETE este arquivo após o uso (segurança)
 */

// Carregar WordPress
require_once('wp-load.php');

// Verificar se é admin
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

global $wpdb;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SISTUR - Verificação de Banco de Dados</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #0073aa;
            padding-bottom: 10px;
        }
        h2 {
            color: #0073aa;
            margin-top: 30px;
        }
        .success {
            color: #46b450;
            font-weight: bold;
        }
        .error {
            color: #dc3232;
            font-weight: bold;
        }
        .warning {
            color: #ffb900;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #0073aa;
            color: white;
        }
        table tr:hover {
            background: #f5f5f5;
        }
        .action-box {
            background: #fff3cd;
            border-left: 4px solid #ffb900;
            padding: 15px;
            margin: 20px 0;
        }
        .code-box {
            background: #f4f4f4;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            overflow-x: auto;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #0073aa;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 5px;
        }
        .btn:hover {
            background: #005a87;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 SISTUR - Verificação de Banco de Dados (v1.4.0)</h1>

        <?php
        // Verificar versões
        $plugin_version = defined('SISTUR_VERSION') ? SISTUR_VERSION : 'Não definida';
        $db_version = get_option('sistur_db_version', 'Não definida');
        $expected_version = '1.4.0';

        echo "<h2>📊 Informações Gerais</h2>";
        echo "<table>";
        echo "<tr><th>Item</th><th>Valor</th><th>Status</th></tr>";
        echo "<tr><td>Versão do Plugin</td><td>{$plugin_version}</td><td>" . ($plugin_version === $expected_version ? '<span class="success">✓ OK</span>' : '<span class="warning">⚠ Desatualizado</span>') . "</td></tr>";
        echo "<tr><td>Versão do BD</td><td>{$db_version}</td><td>" . ($db_version === $expected_version ? '<span class="success">✓ OK</span>' : '<span class="error">✗ Desatualizado</span>') . "</td></tr>";
        echo "<tr><td>Prefixo de Tabelas</td><td>{$wpdb->prefix}</td><td><span class='success'>✓ OK</span></td></tr>";
        echo "</table>";

        // Verificar tabelas principais
        echo "<h2>📦 Tabelas do Sistema</h2>";

        $required_tables = array(
            'sistur_employees' => 'Funcionários',
            'sistur_departments' => 'Departamentos',
            'sistur_time_entries' => 'Registros de Ponto',
            'sistur_time_days' => 'Dias de Ponto',
            'sistur_contract_types' => 'Tipos de Contrato (v1.4.0)',
            'sistur_settings' => 'Configurações (v1.4.0)',
            'sistur_payment_records' => 'Pagamentos',
            'sistur_leads' => 'Leads',
            'sistur_roles' => 'Papéis/Roles',
            'sistur_permissions' => 'Permissões',
            'sistur_audit_logs' => 'Logs de Auditoria'
        );

        echo "<table>";
        echo "<tr><th>Tabela</th><th>Descrição</th><th>Status</th></tr>";

        $missing_tables = array();
        foreach ($required_tables as $table_name => $description) {
            $full_table_name = $wpdb->prefix . $table_name;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'") === $full_table_name;

            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$full_table_name}");
                echo "<tr><td>{$table_name}</td><td>{$description}</td><td><span class='success'>✓ OK ({$count} registros)</span></td></tr>";
            } else {
                echo "<tr><td>{$table_name}</td><td>{$description}</td><td><span class='error'>✗ NÃO EXISTE</span></td></tr>";
                $missing_tables[] = $table_name;
            }
        }
        echo "</table>";

        // Verificar colunas da tabela employees (v1.4.0)
        echo "<h2>🔧 Colunas da Tabela wp_sistur_employees</h2>";

        $employees_table = $wpdb->prefix . 'sistur_employees';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$employees_table}");

        $required_columns_v14 = array(
            'token_qr' => 'Token UUID para QR Code',
            'contract_type_id' => 'Referência ao tipo de contrato',
            'perfil_localizacao' => 'Perfil de localização (INTERNO/EXTERNO)'
        );

        echo "<table>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Status v1.4.0</th></tr>";

        $existing_columns = array();
        foreach ($columns as $col) {
            $existing_columns[] = $col->Field;
            $is_new = array_key_exists($col->Field, $required_columns_v14);
            $marker = $is_new ? ' <strong>[NOVA v1.4.0]</strong>' : '';
            echo "<tr><td>{$col->Field}{$marker}</td><td>{$col->Type}</td><td>" . ($is_new ? '<span class="success">✓ Campo v1.4.0</span>' : '-') . "</td></tr>";
        }
        echo "</table>";

        // Verificar colunas faltantes
        $missing_columns = array();
        foreach ($required_columns_v14 as $col => $desc) {
            if (!in_array($col, $existing_columns)) {
                $missing_columns[$col] = $desc;
            }
        }

        // Verificar configurações (v1.4.0)
        $settings_table = $wpdb->prefix . 'sistur_settings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$settings_table}'") === $settings_table) {
            echo "<h2>⚙️ Configurações do Sistema (v1.4.0)</h2>";
            $settings = $wpdb->get_results("SELECT * FROM {$settings_table}");

            if ($settings) {
                echo "<table>";
                echo "<tr><th>Chave</th><th>Valor</th><th>Tipo</th></tr>";
                foreach ($settings as $setting) {
                    // Ocultar chave secreta por segurança
                    $value = $setting->setting_key === 'cron_secret_key' ? '***************' : $setting->setting_value;
                    echo "<tr><td>{$setting->setting_key}</td><td>{$value}</td><td>{$setting->setting_type}</td></tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='warning'>⚠ Nenhuma configuração encontrada. Execute as migrações.</p>";
            }
        }

        // DIAGNÓSTICO E AÇÕES
        echo "<h2>🎯 Diagnóstico e Ações Necessárias</h2>";

        if (empty($missing_tables) && empty($missing_columns) && $db_version === $expected_version) {
            echo "<div class='action-box' style='background: #d4edda; border-color: #46b450;'>";
            echo "<h3 style='color: #46b450; margin-top: 0;'>✓ Banco de Dados COMPLETO!</h3>";
            echo "<p>Todas as tabelas e colunas necessárias para v1.4.0 estão presentes.</p>";
            echo "<p><strong>Você pode criar funcionários normalmente.</strong></p>";
            echo "</div>";
        } else {
            echo "<div class='action-box'>";
            echo "<h3 style='color: #dc3232; margin-top: 0;'>✗ Migrações Pendentes</h3>";

            if (!empty($missing_tables)) {
                echo "<p><strong>Tabelas faltando:</strong> " . implode(', ', $missing_tables) . "</p>";
            }

            if (!empty($missing_columns)) {
                echo "<p><strong>Colunas faltando em wp_sistur_employees:</strong></p>";
                echo "<ul>";
                foreach ($missing_columns as $col => $desc) {
                    echo "<li><code>{$col}</code> - {$desc}</li>";
                }
                echo "</ul>";
            }

            echo "<h4>📝 Como Corrigir:</h4>";
            echo "<ol>";
            echo "<li>Vá para <strong>WordPress Admin → Plugins</strong></li>";
            echo "<li>Localize <strong>SISTUR - Sistema de Turismo</strong></li>";
            echo "<li>Clique em <strong>Desativar</strong></li>";
            echo "<li>Aguarde a desativação completa</li>";
            echo "<li>Clique em <strong>Ativar</strong></li>";
            echo "<li>As migrações serão executadas automaticamente</li>";
            echo "<li>Volte a esta página e clique em <strong>Verificar Novamente</strong></li>";
            echo "</ol>";

            echo "<a href='?' class='btn'>🔄 Verificar Novamente</a>";
            echo "</div>";
        }

        // Verificar logs de erro
        echo "<h2>📋 Últimos Erros (se houver)</h2>";

        // Tentar ler erro_log
        $log_files = array(
            'wp-content/debug.log',
            '../debug.log',
            'debug.log'
        );

        $found_errors = false;
        foreach ($log_files as $log_file) {
            if (file_exists($log_file)) {
                $lines = array_slice(file($log_file), -20);
                $sistur_errors = array_filter($lines, function($line) {
                    return stripos($line, 'SISTUR') !== false || stripos($line, 'sistur') !== false;
                });

                if (!empty($sistur_errors)) {
                    echo "<div class='code-box'>";
                    echo "<strong>Arquivo: {$log_file}</strong><br>";
                    foreach ($sistur_errors as $error) {
                        echo htmlspecialchars($error) . "<br>";
                    }
                    echo "</div>";
                    $found_errors = true;
                }
            }
        }

        if (!$found_errors) {
            echo "<p class='success'>✓ Nenhum erro SISTUR encontrado nos logs.</p>";
            echo "<p><em>Nota: Para habilitar logs de erro, adicione ao wp-config.php:</em></p>";
            echo "<div class='code-box'>define('WP_DEBUG', true);<br>define('WP_DEBUG_LOG', true);<br>define('WP_DEBUG_DISPLAY', false);</div>";
        }

        ?>

        <hr style="margin: 40px 0;">
        <p style="text-align: center; color: #666;">
            <strong>⚠️ IMPORTANTE:</strong> Delete este arquivo após o uso!<br>
            <code>sistur-db-check.php</code> contém informações sensíveis sobre seu banco de dados.
        </p>
    </div>
</body>
</html>
