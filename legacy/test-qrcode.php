<?php
/**
 * Script de Teste - Geração de QR Code
 * 
 * Execute este script para verificar se a geração de QR codes está funcionando.
 * Acesse: /wp-content/plugins/sistur2/test-qrcode.php
 */

// Carregar WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

// Verificar se é admin
if (!current_user_can('manage_options')) {
    die('Acesso negado. Faça login como administrador.');
}

echo "<h1>🧪 Teste de Geração de QR Code</h1>";

// 1. Verificar diretório
echo "<h2>1. Verificando Diretório</h2>";
$upload_dir = wp_upload_dir();
$qrcode_dir = $upload_dir['basedir'] . '/sistur/qrcodes/';

echo "<p><strong>Diretório:</strong> {$qrcode_dir}</p>";

if (!file_exists($qrcode_dir)) {
    echo "<p>⚠️ Diretório não existe. Criando...</p>";
    $created = wp_mkdir_p($qrcode_dir);
    echo $created ? "<p>✅ Diretório criado com sucesso!</p>" : "<p>❌ Falha ao criar diretório</p>";
} else {
    echo "<p>✅ Diretório existe</p>";
}

if (is_writable($qrcode_dir)) {
    echo "<p>✅ Diretório é gravável</p>";
} else {
    echo "<p>❌ Diretório NÃO é gravável - ISSO É UM PROBLEMA!</p>";
}

// 2. Testar conectividade com APIs
echo "<h2>2. Testando Conectividade com APIs de QR Code</h2>";

require_once SISTUR_PLUGIN_DIR . 'includes/lib/phpqrcode.php';

if (class_exists('SISTUR_QRCodeGenerator')) {
    echo "<p>✅ Classe SISTUR_QRCodeGenerator encontrada</p>";
    
    $results = SISTUR_QRCodeGenerator::testConnectivity();
    
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>API</th><th>Status</th><th>Tempo (ms)</th><th>Tamanho</th></tr>";
    
    foreach ($results as $api => $result) {
        $status = $result['success'] ? '✅ OK' : '❌ Falhou';
        echo "<tr>";
        echo "<td>{$api}</td>";
        echo "<td>{$status}</td>";
        echo "<td>{$result['time_ms']}</td>";
        echo "<td>{$result['size']} bytes</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>❌ Classe SISTUR_QRCodeGenerator NÃO encontrada</p>";
}

// 3. Buscar um funcionário para testar
echo "<h2>3. Testando Geração para um Funcionário</h2>";

global $wpdb;
$table = $wpdb->prefix . 'sistur_employees';
$employee = $wpdb->get_row("SELECT * FROM $table WHERE status = 1 LIMIT 1", ARRAY_A);

if ($employee) {
    echo "<p><strong>Funcionário de teste:</strong> {$employee['name']} (ID: {$employee['id']})</p>";
    echo "<p><strong>Token atual:</strong> " . ($employee['token_qr'] ?: '(nenhum)') . "</p>";
    
    // Tentar gerar QR code
    if (class_exists('SISTUR_QRCode')) {
        $qrcode = SISTUR_QRCode::get_instance();
        
        echo "<p>🔄 Tentando gerar QR Code...</p>";
        
        // Deletar arquivo existente para forçar regeneração
        $hash = md5($employee['id'] . $employee['cpf']);
        $filename = 'employee_' . $employee['id'] . '_' . $hash . '.png';
        $filepath = $qrcode_dir . $filename;
        
        if (file_exists($filepath)) {
            unlink($filepath);
            echo "<p>🗑️ Arquivo existente removido para teste</p>";
        }
        
        $result = $qrcode->generate_qrcode($employee['id']);
        
        if ($result) {
            echo "<p>✅ <strong>QR Code gerado com sucesso!</strong></p>";
            echo "<p><strong>URL:</strong> <a href='{$result}' target='_blank'>{$result}</a></p>";
            echo "<p><img src='{$result}' width='200' alt='QR Code'></p>";
        } else {
            echo "<p>❌ <strong>Falha ao gerar QR Code</strong></p>";
            echo "<p>Verifique o arquivo <code>wp-content/debug.log</code> para ver os detalhes do erro.</p>";
        }
    } else {
        echo "<p>❌ Classe SISTUR_QRCode não encontrada</p>";
    }
} else {
    echo "<p>⚠️ Nenhum funcionário encontrado no banco de dados</p>";
}

// 4. Verificar logs recentes
echo "<h2>4. Logs Recentes (últimas 20 linhas relacionadas ao QR)</h2>";

$debug_log = WP_CONTENT_DIR . '/debug.log';
if (file_exists($debug_log)) {
    $lines = file($debug_log);
    $qr_lines = array_filter($lines, function($line) {
        return stripos($line, 'SISTUR QR') !== false;
    });
    
    $recent = array_slice($qr_lines, -20);
    
    if (!empty($recent)) {
        echo "<pre style='background: #1e1e1e; color: #d4d4d4; padding: 15px; overflow-x: auto; font-size: 12px;'>";
        foreach ($recent as $line) {
            // Colorir erros
            if (stripos($line, 'Error') !== false) {
                echo "<span style='color: #f44336;'>" . htmlspecialchars($line) . "</span>";
            } elseif (stripos($line, 'Debug') !== false) {
                echo "<span style='color: #4caf50;'>" . htmlspecialchars($line) . "</span>";
            } else {
                echo htmlspecialchars($line);
            }
        }
        echo "</pre>";
    } else {
        echo "<p>Nenhum log de QR encontrado. Tente gerar um QR code primeiro.</p>";
    }
} else {
    echo "<p>⚠️ Arquivo debug.log não encontrado. Habilite WP_DEBUG_LOG no wp-config.php</p>";
    echo "<pre>define('WP_DEBUG', true);\ndefine('WP_DEBUG_LOG', true);</pre>";
}

echo "<hr><p><em>Teste concluído em " . date('Y-m-d H:i:s') . "</em></p>";
