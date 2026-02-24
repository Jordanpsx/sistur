<?php
/**
 * Sistema de login de funcionários
 *
 * @package SISTUR
 */

// Se este arquivo for chamado diretamente, abortar
if (!defined('WPINC')) {
    die;
}

/**
 * Handler AJAX para login de funcionário
 */
add_action('wp_ajax_sistur_funcionario_login', 'sistur_handle_funcionario_login');
add_action('wp_ajax_nopriv_sistur_funcionario_login', 'sistur_handle_funcionario_login');

function sistur_handle_funcionario_login() {
    global $wpdb;

    // Verificar nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sistur_login_nonce')) {
        wp_send_json_error(array(
            'message' => 'Erro de segurança. Por favor, recarregue a página e tente novamente.'
        ));
    }

    // Obter e validar CPF
    $cpf = isset($_POST['cpf']) ? sanitize_text_field($_POST['cpf']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($cpf)) {
        wp_send_json_error(array(
            'message' => 'Por favor, informe o CPF.'
        ));
    }

    if (empty($password)) {
        wp_send_json_error(array(
            'message' => 'Por favor, informe a senha.'
        ));
    }

    // Remover formatação do CPF
    $cpf = preg_replace('/[^0-9]/', '', $cpf);

    // Validar formato do CPF (11 dígitos)
    if (strlen($cpf) !== 11) {
        wp_send_json_error(array(
            'message' => 'CPF inválido. Deve conter 11 dígitos.'
        ));
    }

    // Validar CPF com algoritmo correto
    if (!sistur_validate_cpf($cpf)) {
        wp_send_json_error(array(
            'message' => 'CPF inválido.'
        ));
    }

    // Buscar funcionário no banco de dados
    $table = $wpdb->prefix . 'sistur_employees';
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE cpf = %s AND status = 1",
        $cpf
    ), ARRAY_A);

    if (!$employee) {
        wp_send_json_error(array(
            'message' => 'CPF não encontrado ou funcionário inativo. Por favor, verifique o CPF informado.'
        ));
    }

    // Verificar senha
    if (empty($employee['password'])) {
        wp_send_json_error(array(
            'message' => 'Senha não cadastrada para este funcionário. Entre em contato com o administrador.'
        ));
    }

    if (!wp_check_password($password, $employee['password'])) {
        wp_send_json_error(array(
            'message' => 'Senha incorreta. Por favor, verifique sua senha e tente novamente.'
        ));
    }

    // Criar sessão para o funcionário
    $session = SISTUR_Session::get_instance();
    $session->create_employee_session($employee);

    // Retornar sucesso
    wp_send_json_success(array(
        'message' => 'Login realizado com sucesso!',
        'redirect' => home_url('/painel-funcionario/'),
        'employee' => array(
            'id' => $employee['id'],
            'name' => $employee['name'],
            'email' => $employee['email']
        )
    ));
}

/**
 * Handler AJAX para logout de funcionário
 */
add_action('wp_ajax_sistur_funcionario_logout', 'sistur_handle_funcionario_logout');
add_action('wp_ajax_nopriv_sistur_funcionario_logout', 'sistur_handle_funcionario_logout');

function sistur_handle_funcionario_logout() {
    // Destruir sessão
    $session = SISTUR_Session::get_instance();
    $session->destroy_employee_session();

    // Se for requisição AJAX
    if (wp_doing_ajax()) {
        wp_send_json_success(array(
            'message' => 'Logout realizado com sucesso!',
            'redirect' => home_url('/login-funcionario/')
        ));
    }

    // Se for requisição normal, redirecionar
    wp_redirect(home_url('/login-funcionario/'));
    exit;
}

/**
 * Valida CPF usando o algoritmo correto
 */
function sistur_validate_cpf($cpf) {
    // Remove caracteres não numéricos
    $cpf = preg_replace('/[^0-9]/', '', $cpf);

    // Verifica se tem 11 dígitos
    if (strlen($cpf) != 11) {
        return false;
    }

    // Verifica se todos os dígitos são iguais
    if (preg_match('/(\d)\1{10}/', $cpf)) {
        return false;
    }

    // Calcula os dígitos verificadores
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }

    return true;
}

/**
 * Redireciona para login se não estiver autenticado
 */
function sistur_require_employee_login() {
    $session = SISTUR_Session::get_instance();

    if (!$session->is_employee_logged_in()) {
        wp_redirect(home_url('/login-funcionario/'));
        exit;
    }
}

/**
 * Retorna os dados do funcionário logado
 */
function sistur_get_current_employee() {
    // Retornar dados da sessão
    $session = SISTUR_Session::get_instance();
    $employee_data = $session->get_employee_data();

    if ($employee_data) {
        // Verificar se o usuário também é admin do WordPress
        $employee_data['is_admin'] = current_user_can('manage_options');
        return $employee_data;
    }

    return null;
}

/**
 * Verifica se o funcionário atual pode acessar determinado recurso
 */
function sistur_employee_can_access($employee_id = null) {
    // Admins do WordPress podem acessar tudo
    if (current_user_can('manage_options')) {
        return true;
    }

    // Se não foi especificado ID, apenas verificar se está logado
    if ($employee_id === null) {
        $session = SISTUR_Session::get_instance();
        return $session->is_employee_logged_in();
    }

    // Verificar se o funcionário logado é o mesmo que o solicitado
    $current = sistur_get_current_employee();
    if ($current && isset($current['id'])) {
        return (int)$current['id'] === (int)$employee_id;
    }

    return false;
}
