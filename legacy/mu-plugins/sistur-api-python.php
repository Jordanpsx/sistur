<?php
/**
 * Plugin Name: SISTUR API Python Integration
 * Description: Endpoint seguro para registro de ponto via script Python.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) { exit; }

// 1. Cria o Endpoint
add_action('rest_api_init', function () {
    register_rest_route('meu-sistema/v1', '/registrar-ponto', array(
        'methods' => 'POST',
        'callback' => 'api_registrar_ponto_sistur',
        'permission_callback' => '__return_true', // Autenticação via Hash
    ));
});

// 2. Função de Processamento
function api_registrar_ponto_sistur($request) {
    global $wpdb;

    // --- CONFIGURAÇÃO ---
    // Chave forte gerada para você (copie esta mesma chave para o Python)
    $shared_secret = "x7k9PzL2mN5qR8vJ3wA6yB4cE1dF0gH"; 

    // Dados do Python
    $params = $request->get_json_params();
    $qr_token = isset($params['qr_token']) ? sanitize_text_field($params['qr_token']) : '';
    $timestamp = isset($params['timestamp']) ? (int)$params['timestamp'] : 0;
    $hash_recebido = isset($params['hash']) ? $params['hash'] : '';

    // --- SEGURANÇA ---

    // 1. Recria assinatura
    // Payload agora é: qr_token + timestamp + secret
    $payload_string = $qr_token . $timestamp . $shared_secret;
    $hash_esperado = hash('sha256', $payload_string);

    // 2. Valida assinatura (Timing Attack Safe)
    if (!hash_equals($hash_esperado, $hash_recebido)) {
        return new WP_Error('forbidden', 'Assinatura de segurança inválida.', array('status' => 403));
    }

    // 3. Valida tempo (Janela de 60 segundos)
    $diff = time() - $timestamp;
    if (abs($diff) > 60) {
        return new WP_Error('timeout', 'Relógio dessincronizado (diferença > 60s).', array('status' => 408));
    }

    // --- INTEGRAÇÃO SISTUR ---

    // Buscar funcionário pelo TOKEN DO QR CODE
    $table_employees = $wpdb->prefix . 'sistur_employees';
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM $table_employees WHERE token_qr = %s AND status = 1", 
        $qr_token
    ));

    if (!$employee) {
        return new WP_Error('not_found', 'QR Code inválido ou funcionário inativo.', array('status' => 404));
    }

    // Inserir na tabela de Ponto do SISTUR
    $table_entries = $wpdb->prefix . 'sistur_time_entries';
    $agora_mysql = date('Y-m-d H:i:s', $timestamp); // Usa o horário enviado pelo Python (preciso)
    $hoje_date = date('Y-m-d', $timestamp);

    // Verificar duplicidade no último minuto para evitar "dedo nervoso"
    $duplicado = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_entries WHERE employee_id = %d AND ABS(TIMESTAMPDIFF(SECOND, punch_time, %s)) < 60",
        $employee->id, $agora_mysql
    ));

    if ($duplicado) {
        return new WP_REST_Response(array('status' => 'ignorado', 'mensagem' => 'Registro duplicado recente.'), 200);
    }

    // LÓGICA DE SEQUÊNCIA INTELIGENTE
    // Conta quantas batidas já existem hoje para definir o tipo da próxima
    $existing_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_entries WHERE employee_id = %d AND shift_date = %s",
        $employee->id, $hoje_date
    ));

    $punch_type = 'auto'; // Padrão

    switch ((int)$existing_count) {
        case 0:
            $punch_type = 'clock_in';    // 1ª batida: Entrada
            break;
        case 1:
            $punch_type = 'lunch_start'; // 2ª batida: Saída para Intervalo
            break;
        case 2:
            $punch_type = 'lunch_end';   // 3ª batida: Volta do Intervalo
            break;
        case 3:
            $punch_type = 'clock_out';   // 4ª batida: Saída
            break;
        default:
            $punch_type = 'auto';        // 5ª+ batida: Deixa o sistema decidir (hora extra, etc)
            break;
    }

    // INSERÇÃO LIMPA E DIRETA
    // Sem necessidade de "notes" ou auditoria manual. O 'source' => 'system' já audita que veio da API.
    $inseriu = $wpdb->insert(
        $table_entries,
        array(
            'employee_id' => $employee->id,
            'punch_type' => $punch_type, // Tipo definido explicitamente
            'punch_time' => $agora_mysql,
            'shift_date' => $hoje_date,
            'source' => 'system',        // Identificador automático de origem
            'processing_status' => 'PENDENTE'
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );

    if ($inseriu) {
        // Aciona o processamento IMEDIATO do banco de horas
        // Isso garante que ao bater o ponto, o saldo de horas já seja recalculado sem intervenção humana
        if (class_exists('SISTUR_Punch_Processing')) {
            $processor = SISTUR_Punch_Processing::get_instance();
            if (method_exists($processor, 'process_employee_day')) {
                $processor->process_employee_day($employee->id, $hoje_date);
            }
        }

        // Mapeia punch_type para texto amigável em português
        $tipos_batida = array(
            'clock_in'    => 'entrada',
            'lunch_start' => 'início almoço',
            'lunch_end'   => 'fim almoço',
            'clock_out'   => 'saída',
            'auto'        => 'registro'
        );
        $tipo_texto = isset($tipos_batida[$punch_type]) ? $tipos_batida[$punch_type] : 'registro';

        // Formata horário para impressão (ex: 08h00)
        $hora_formatada = date('H\hi', $timestamp);

        // Data e hora completas para rodapé
        $data_completa = date('d/m/Y H:i:s', $timestamp);

        return new WP_REST_Response(array(
            'status' => 'sucesso',
            'mensagem' => 'Ponto registrado para ' . $employee->name,
            'horario' => $agora_mysql,
            'impressao' => array(
                'nome'          => $employee->name,
                'hora'          => $hora_formatada,
                'tipo'          => $tipo_texto,
                'data_completa' => $data_completa
            )
        ), 200);
    }

    return new WP_Error('db_error', 'Erro ao salvar no banco.', array('status' => 500));
}
