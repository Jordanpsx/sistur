<?php
/**
 * Script de diagnóstico web
 * Acesse via: http://localhost/wordpress/wp-content/plugins/sistur/diagnostic-web.php
 */

echo "<h1>Diagnóstico SISTUR - QR Code</h1>";
echo "<pre>";

echo "=== INFORMAÇÕES DO SERVIDOR ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Current Dir: " . __DIR__ . "\n";
echo "Script Path: " . __FILE__ . "\n\n";

echo "=== VERIFICAÇÃO DE ARQUIVOS ===\n";

$files_to_check = [
    'composer.json' => __DIR__ . '/composer.json',
    'vendor/' => __DIR__ . '/vendor/',
    'vendor/autoload.php' => __DIR__ . '/vendor/autoload.php',
    'vendor/endroid/' => __DIR__ . '/vendor/endroid/',
    'sistur.php' => __DIR__ . '/sistur.php',
];

foreach ($files_to_check as $name => $path) {
    $exists = file_exists($path);
    echo "$name: " . ($exists ? "✓ EXISTS" : "✗ NOT FOUND") . "\n";
    echo "  Path: $path\n";
    if ($exists && is_dir($path)) {
        echo "  Type: Directory\n";
    } elseif ($exists) {
        echo "  Type: File (" . filesize($path) . " bytes)\n";
    }
    echo "\n";
}

echo "=== VERIFICAÇÃO DE CLASSES ===\n";

// Tentar carregar autoload
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    echo "Carregando autoload...\n";
    require_once $autoload_path;
    echo "✓ Autoload carregado\n\n";
} else {
    echo "✗ Autoload não encontrado\n\n";
}

$classes_to_check = [
    'Endroid\QrCode\QrCode',
    'Endroid\QrCode\Writer\PngWriter',
    'Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh',
];

foreach ($classes_to_check as $class) {
    $exists = class_exists($class);
    echo "$class: " . ($exists ? "✓ LOADED" : "✗ NOT FOUND") . "\n";
}

echo "\n=== TESTE DE GERAÇÃO ===\n";

if (class_exists('Endroid\QrCode\QrCode')) {
    try {
        $test_token = '550e8400-e29b-41d4-a716-446655440000';
        $temp_file = sys_get_temp_dir() . '/sistur_test_qr.png';

        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $qrCode = \Endroid\QrCode\QrCode::create($test_token)
            ->setSize(300)
            ->setMargin(10)
            ->setErrorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh());

        $result = $writer->write($qrCode);
        $result->saveToFile($temp_file);

        if (file_exists($temp_file)) {
            echo "✓ QR Code gerado com sucesso!\n";
            echo "  Arquivo: $temp_file\n";
            echo "  Tamanho: " . filesize($temp_file) . " bytes\n";
            @unlink($temp_file);
        } else {
            echo "✗ QR Code não foi gerado\n";
        }
    } catch (Exception $e) {
        echo "✗ ERRO: " . $e->getMessage() . "\n";
        echo "  Trace: " . $e->getTraceAsString() . "\n";
    }
} else {
    echo "✗ Não é possível testar - classes não encontradas\n";
}

echo "\n=== GD EXTENSION ===\n";
if (extension_loaded('gd')) {
    echo "✓ GD Extension está habilitada\n";
    $gd_info = gd_info();
    foreach ($gd_info as $key => $value) {
        echo "  $key: " . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) . "\n";
    }
} else {
    echo "✗ GD Extension NÃO está habilitada\n";
}

echo "\n=== PERMISSÕES ===\n";
$upload_dir = wp_upload_dir();
$qrcode_dir = $upload_dir['basedir'] . '/sistur/qrcodes/';

echo "WordPress Uploads Dir: " . $upload_dir['basedir'] . "\n";
echo "QRCode Dir: $qrcode_dir\n";
echo "QRCode Dir Exists: " . (is_dir($qrcode_dir) ? "Yes" : "No") . "\n";

if (is_dir($qrcode_dir)) {
    echo "QRCode Dir Writable: " . (is_writable($qrcode_dir) ? "Yes" : "No") . "\n";
    echo "QRCode Dir Permissions: " . substr(sprintf('%o', fileperms($qrcode_dir)), -4) . "\n";
}

echo "</pre>";

echo "<p><strong>Próximos passos:</strong></p>";
echo "<ol>";
echo "<li>Se vendor/ não existe, execute: <code>composer install --no-dev</code> no diretório do plugin</li>";
echo "<li>Se as classes não estão carregadas, verifique se o autoload está sendo chamado em sistur.php</li>";
echo "<li>Se GD não está habilitada, instale: <code>sudo apt-get install php-gd</code></li>";
echo "</ol>";
