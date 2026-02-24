<?php
/**
 * Classe de gerenciamento de sessão
 *
 * @package SISTUR
 */

class SISTUR_Session {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Tempo de expiração da sessão (8 horas)
     */
    const SESSION_EXPIRE = 28800; // 8 horas em segundos

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $this->init_session();
    }

    /**
     * Retorna instância única da classe
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa a sessão PHP com configurações de segurança
     */
    private function init_session() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configurações de segurança para a sessão
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_samesite', 'Lax');

            // Se estiver usando HTTPS, marcar cookie como secure
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', 1);
            }

            // Definir tempo de vida da sessão
            ini_set('session.gc_maxlifetime', self::SESSION_EXPIRE);

            session_start();

            // Verificar se a sessão expirou
            $this->check_session_expiry();
        }
    }

    /**
     * Verifica se a sessão expirou
     */
    private function check_session_expiry() {
        if (isset($_SESSION['sistur_funcionario_expire'])) {
            if (time() > $_SESSION['sistur_funcionario_expire']) {
                $this->destroy_employee_session();
            }
        }
    }

    /**
     * Cria uma sessão para o funcionário
     */
    public function create_employee_session($employee_data) {
        $_SESSION['sistur_funcionario_id'] = $employee_data['id'];
        $_SESSION['sistur_funcionario_nome'] = $employee_data['name'];
        $_SESSION['sistur_funcionario_email'] = $employee_data['email'];
        $_SESSION['sistur_funcionario_cpf'] = $employee_data['cpf'];
        $_SESSION['sistur_funcionario_role_id'] = $employee_data['role_id'] ?? null;
        $_SESSION['sistur_funcionario_expire'] = time() + self::SESSION_EXPIRE;

        return true;
    }

    /**
     * Verifica se o funcionário está logado
     */
    public function is_employee_logged_in() {
        if (!isset($_SESSION['sistur_funcionario_id'])) {
            return false;
        }

        // Verificar se a sessão não expirou
        if (isset($_SESSION['sistur_funcionario_expire'])) {
            if (time() > $_SESSION['sistur_funcionario_expire']) {
                $this->destroy_employee_session();
                return false;
            }

            // Renovar o tempo de expiração
            $_SESSION['sistur_funcionario_expire'] = time() + self::SESSION_EXPIRE;
        }

        return true;
    }

    /**
     * Retorna os dados do funcionário logado
     */
    public function get_employee_data() {
        if (!$this->is_employee_logged_in()) {
            return null;
        }

        return array(
            'id' => $_SESSION['sistur_funcionario_id'] ?? null,
            'nome' => $_SESSION['sistur_funcionario_nome'] ?? null,
            'email' => $_SESSION['sistur_funcionario_email'] ?? null,
            'cpf' => $_SESSION['sistur_funcionario_cpf'] ?? null,
            'role_id' => $_SESSION['sistur_funcionario_role_id'] ?? null
        );
    }

    /**
     * Destrói a sessão do funcionário
     */
    public function destroy_employee_session() {
        unset($_SESSION['sistur_funcionario_id']);
        unset($_SESSION['sistur_funcionario_nome']);
        unset($_SESSION['sistur_funcionario_email']);
        unset($_SESSION['sistur_funcionario_cpf']);
        unset($_SESSION['sistur_funcionario_role_id']);
        unset($_SESSION['sistur_funcionario_expire']);

        return true;
    }

    /**
     * Retorna o ID do funcionário logado
     */
    public function get_employee_id() {
        if ($this->is_employee_logged_in()) {
            return $_SESSION['sistur_funcionario_id'] ?? null;
        }
        return null;
    }
}
