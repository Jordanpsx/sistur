<?php
/**
 * SISTUR Audit Log System
 * Sistema de logs de auditoria
 *
 * @package SISTUR
 * @version 1.2.0
 */

class SISTUR_Audit {

    /**
     * Tipos de ação
     */
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_VIEW = 'view';
    const ACTION_EXPORT = 'export';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_PERMISSION_CHANGE = 'permission_change';

    /**
     * Instância única (Singleton)
     */
    private static $instance = null;

    /**
     * Retorna instância única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor privado
     */
    private function __construct() {
        // Pode adicionar hooks aqui se necessário
    }

    /**
     * Registrar log de auditoria
     *
     * @param string $action Tipo de ação
     * @param string $module Módulo do sistema
     * @param int $item_id ID do item afetado
     * @param array $details Detalhes adicionais
     * @param int $user_id ID do usuário (opcional)
     * @return int|false ID do log ou false
     */
    public function log($action, $module, $item_id = null, $details = array(), $user_id = null) {
        global $wpdb;

        // Determinar usuário
        if ($user_id === null) {
            $user_id = $this->get_current_user_id();
        }

        // Obter IP
        $ip_address = $this->get_client_ip();

        // Obter user agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

        // Preparar dados
        $data = array(
            'user_id' => $user_id,
            'user_type' => $this->get_user_type(),
            'action' => $action,
            'module' => $module,
            'item_id' => $item_id,
            'details' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'created_at' => current_time('mysql')
        );

        // Inserir no banco
        $result = $wpdb->insert(
            $wpdb->prefix . 'sistur_audit_logs',
            $data,
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Atalhos para ações comuns
     */
    public function log_create($module, $item_id, $details = array()) {
        return $this->log(self::ACTION_CREATE, $module, $item_id, $details);
    }

    public function log_update($module, $item_id, $details = array()) {
        return $this->log(self::ACTION_UPDATE, $module, $item_id, $details);
    }

    public function log_delete($module, $item_id, $details = array()) {
        return $this->log(self::ACTION_DELETE, $module, $item_id, $details);
    }

    public function log_export($module, $details = array()) {
        return $this->log(self::ACTION_EXPORT, $module, null, $details);
    }

    public function log_login($user_id, $success = true) {
        return $this->log(
            self::ACTION_LOGIN,
            'auth',
            $user_id,
            array('success' => $success),
            $user_id
        );
    }

    public function log_logout($user_id) {
        return $this->log(self::ACTION_LOGOUT, 'auth', $user_id, array(), $user_id);
    }

    /**
     * Buscar logs
     *
     * @param array $args Argumentos de busca
     * @return array Logs
     */
    public function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'user_id' => null,
            'action' => null,
            'module' => null,
            'item_id' => null,
            'start_date' => null,
            'end_date' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $table = $wpdb->prefix . 'sistur_audit_logs';

        // Filtros
        if ($args['user_id']) {
            $where[] = $wpdb->prepare('user_id = %d', $args['user_id']);
        }

        if ($args['action']) {
            $where[] = $wpdb->prepare('action = %s', $args['action']);
        }

        if ($args['module']) {
            $where[] = $wpdb->prepare('module = %s', $args['module']);
        }

        if ($args['item_id']) {
            $where[] = $wpdb->prepare('item_id = %d', $args['item_id']);
        }

        if ($args['start_date']) {
            $where[] = $wpdb->prepare('created_at >= %s', $args['start_date']);
        }

        if ($args['end_date']) {
            $where[] = $wpdb->prepare('created_at <= %s', $args['end_date']);
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT * FROM $table WHERE $where_clause ORDER BY {$args['orderby']} {$args['order']} LIMIT {$args['limit']} OFFSET {$args['offset']}";

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Contar logs
     *
     * @param array $args Mesmos argumentos de get_logs
     * @return int Total de logs
     */
    public function count_logs($args = array()) {
        global $wpdb;

        $args['limit'] = 999999;
        $args['offset'] = 0;

        $logs = $this->get_logs($args);

        return count($logs);
    }

    /**
     * Limpar logs antigos
     *
     * @param int $days Dias para manter (padrão: 90)
     * @return int Número de logs removidos
     */
    public function cleanup_old_logs($days = 90) {
        global $wpdb;

        $date_threshold = date('Y-m-d H:i:s', strtotime("-$days days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}sistur_audit_logs WHERE created_at < %s",
            $date_threshold
        ));

        return $result;
    }

    /**
     * Obter ID do usuário atual
     *
     * @return int|null
     */
    private function get_current_user_id() {
        // Verificar se é funcionário logado
        $session = SISTUR_Session::get_instance();
        if ($session->is_employee_logged_in()) {
            $employee = $session->get_employee_data();
            return isset($employee['id']) ? $employee['id'] : null;
        }

        // Verificar se é admin WordPress
        if (is_user_logged_in()) {
            return get_current_user_id();
        }

        return null;
    }

    /**
     * Obter tipo de usuário
     *
     * @return string
     */
    private function get_user_type() {
        $session = SISTUR_Session::get_instance();

        if ($session->is_employee_logged_in()) {
            return 'employee';
        }

        if (is_user_logged_in()) {
            return 'admin';
        }

        return 'guest';
    }

    /**
     * Obter IP do cliente
     *
     * @return string
     */
    private function get_client_ip() {
        $ip = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Validar e limpar
        $ip = filter_var($ip, FILTER_VALIDATE_IP);

        return $ip ? $ip : 'unknown';
    }

    /**
     * Formatar log para exibição
     *
     * @param array $log Log
     * @return string Descrição legível
     */
    public static function format_log_description($log) {
        $action_labels = array(
            self::ACTION_CREATE => 'criou',
            self::ACTION_UPDATE => 'atualizou',
            self::ACTION_DELETE => 'excluiu',
            self::ACTION_VIEW => 'visualizou',
            self::ACTION_EXPORT => 'exportou',
            self::ACTION_LOGIN => 'fez login',
            self::ACTION_LOGOUT => 'fez logout',
            self::ACTION_PERMISSION_CHANGE => 'alterou permissões'
        );

        $module_labels = array(
            'employees' => 'funcionário',
            'time_tracking' => 'ponto eletrônico',
            'payments' => 'pagamento',
            'leads' => 'lead',
            'inventory' => 'produto',
            'departments' => 'departamento',
            'auth' => 'autenticação'
        );

        $action = isset($action_labels[$log['action']]) ? $action_labels[$log['action']] : $log['action'];
        $module = isset($module_labels[$log['module']]) ? $module_labels[$log['module']] : $log['module'];

        $description = $action . ' ' . $module;

        if ($log['item_id']) {
            $description .= ' #' . $log['item_id'];
        }

        return $description;
    }
}
