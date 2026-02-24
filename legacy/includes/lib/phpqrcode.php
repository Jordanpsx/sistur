<?php
/**
 * PHP QR Code Generator - Standalone version
 * Biblioteca minimalista para geração de QR codes
 * Baseada em: https://github.com/kazuhikoarase/qrcode-generator
 * Adaptada para uso standalone (sem dependências)
 *
 * @package SISTUR
 * @version 1.1
 */

class SISTUR_QRCodeGenerator {

    /**
     * Lista de APIs de QR Code (em ordem de prioridade)
     */
    private static $apis = array(
        'qrserver' => 'https://api.qrserver.com/v1/create-qr-code/?size=%dx%d&margin=%d&data=%s',
        'goqr'     => 'https://api.goqr.me/v1/create-qr-code/?size=%dx%d&margin=%d&data=%s',
        'quickchart' => 'https://quickchart.io/qr?size=%d&margin=%d&text=%s'
    );

    /**
     * Gera QR code PNG a partir de texto
     *
     * @param string $text Texto a codificar
     * @param int $size Tamanho em pixels (padrão: 300)
     * @param int $margin Margem em pixels (padrão: 10)
     * @return string|false Dados binários do PNG ou false em caso de erro
     */
    public static function generate($text, $size = 300, $margin = 10) {
        error_log("SISTUR QRGenerator: Iniciando geração para texto: " . substr($text, 0, 50) . "...");

        // Tentar cada API em sequência
        foreach (self::$apis as $api_name => $api_template) {
            error_log("SISTUR QRGenerator: Tentando API: {$api_name}");

            // Montar URL de acordo com a API
            if ($api_name === 'quickchart') {
                $url = sprintf($api_template, $size, $margin, urlencode($text));
            } else {
                $url = sprintf($api_template, $size, $size, $margin, urlencode($text));
            }

            // Fazer requisição com timeout e error handling
            $context = stream_context_create(array(
                'http' => array(
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'user_agent' => 'SISTUR QR Generator/1.1'
                ),
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false
                )
            ));

            $image_data = @file_get_contents($url, false, $context);

            // Verificar se a resposta é válida (imagem PNG)
            if ($image_data !== false && strlen($image_data) > 100) {
                // Verificar se é realmente uma imagem PNG (magic bytes)
                if (substr($image_data, 0, 8) === "\x89PNG\r\n\x1a\n" || 
                    substr($image_data, 0, 4) === "\x89PNG") {
                    error_log("SISTUR QRGenerator: Sucesso via API {$api_name} (" . strlen($image_data) . " bytes)");
                    return $image_data;
                } else {
                    error_log("SISTUR QRGenerator: API {$api_name} retornou dados inválidos (não é PNG)");
                }
            } else {
                $error = error_get_last();
                $error_msg = $error ? $error['message'] : 'Resposta vazia ou muito pequena';
                error_log("SISTUR QRGenerator: Falha na API {$api_name}: {$error_msg}");
            }
        }

        // Se todas as APIs falharam
        error_log("SISTUR QRGenerator: TODAS as APIs falharam. Verifique a conexão com internet.");
        return false;
    }

    /**
     * Gera e salva QR code em arquivo
     *
     * @param string $text Texto a codificar
     * @param string $filepath Caminho do arquivo de saída
     * @param int $size Tamanho em pixels
     * @param int $margin Margem em pixels
     * @return bool Sucesso
     */
    public static function generateAndSave($text, $filepath, $size = 300, $margin = 10) {
        $image_data = self::generate($text, $size, $margin);

        if ($image_data === false || empty($image_data)) {
            error_log("SISTUR QRGenerator: Nenhum dado de imagem para salvar");
            return false;
        }

        // Verificar se o diretório existe
        $dir = dirname($filepath);
        if (!file_exists($dir)) {
            error_log("SISTUR QRGenerator: Criando diretório: {$dir}");
            if (!wp_mkdir_p($dir)) {
                error_log("SISTUR QRGenerator: Falha ao criar diretório: {$dir}");
                return false;
            }
        }

        $result = @file_put_contents($filepath, $image_data);

        if ($result === false) {
            error_log("SISTUR QRGenerator: Falha ao salvar arquivo: {$filepath}");
            return false;
        }

        error_log("SISTUR QRGenerator: Arquivo salvo com sucesso: {$filepath} ({$result} bytes)");
        return true;
    }

    /**
     * Gera QR code como data URL (base64)
     *
     * @param string $text Texto a codificar
     * @param int $size Tamanho em pixels
     * @return string|false Data URL ou false
     */
    public static function generateDataURL($text, $size = 300) {
        $image_data = self::generate($text, $size);

        if ($image_data === false) {
            return false;
        }

        return 'data:image/png;base64,' . base64_encode($image_data);
    }

    /**
     * Testa conectividade com as APIs de QR Code
     *
     * @return array Resultado do teste para cada API
     */
    public static function testConnectivity() {
        $results = array();
        $test_text = 'test';

        foreach (self::$apis as $api_name => $api_template) {
            if ($api_name === 'quickchart') {
                $url = sprintf($api_template, 100, 5, urlencode($test_text));
            } else {
                $url = sprintf($api_template, 100, 100, 5, urlencode($test_text));
            }

            $context = stream_context_create(array(
                'http' => array('timeout' => 5)
            ));

            $start = microtime(true);
            $data = @file_get_contents($url, false, $context);
            $time = round((microtime(true) - $start) * 1000);

            $results[$api_name] = array(
                'success' => $data !== false && strlen($data) > 100,
                'time_ms' => $time,
                'size' => $data ? strlen($data) : 0
            );
        }

        return $results;
    }
}

