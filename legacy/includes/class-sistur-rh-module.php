<?php
/**
 * Módulo de RH - SISTUR
 *
 * Gerencia a interface do módulo de RH com navegação por abas,
 * integração de permissões e sistema de auditoria.
 *
 * @package SISTUR
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SISTUR_RH_Module
{

    /**
     * Instância singleton
     */
    private static $instance = null;

    /**
     * Classe de permissões
     */
    private $permissions;

    /**
     * Classe de auditoria
     */
    private $audit;

    /**
     * Timebank manager
     */
    private $timebank;

    /**
     * ID do funcionário atual
     */
    private $current_employee_id;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        $this->permissions = SISTUR_Permissions::get_instance();
        $this->audit = SISTUR_Audit::get_instance();

        // Timebank manager pode não estar carregado ainda
        add_action('init', array($this, 'init_timebank'));

        // Registrar hooks AJAX
        $this->register_ajax_handlers();
    }

    /**
     * Retorna instância única
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa o timebank manager
     */
    public function init_timebank()
    {
        if (class_exists('SISTUR_Timebank_Manager')) {
            $this->timebank = SISTUR_Timebank_Manager::get_instance();
        }

        // Identificar funcionário atual
        $this->identify_current_employee();
    }

    /**
     * Identifica o funcionário atual baseado no usuário WP ou sessão
     */
    private function identify_current_employee()
    {
        global $wpdb;

        // 1. Tentar via sessão do portal (SISTUR_Session usa 'sistur_funcionario_id')
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            $session_employee_id = $session->get_employee_id();
            if ($session_employee_id) {
                $this->current_employee_id = intval($session_employee_id);
                return;
            }
        }

        // 2. Fallback: chave legada 'sistur_employee_id'
        if (!$this->current_employee_id && isset($_SESSION['sistur_employee_id'])) {
            $this->current_employee_id = intval($_SESSION['sistur_employee_id']);
            return;
        }

        // 3. Fallback: buscar via user_id do WordPress
        if (!$this->current_employee_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $this->current_employee_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sistur_employees WHERE user_id = %d AND status = 1",
                $user_id
            ));
        }
    }

    /**
     * Registra handlers AJAX
     */
    private function register_ajax_handlers()
    {
        $handlers = array(
            'sistur_rh_get_dashboard_stats' => 'ajax_get_dashboard_stats',
            'sistur_rh_get_employees' => 'ajax_get_employees',
            'sistur_rh_get_employee' => 'ajax_get_employee',
            'sistur_rh_save_employee' => 'ajax_save_employee',
            'sistur_rh_get_timebank_data' => 'ajax_get_timebank_data',
            'sistur_rh_liquidate_hours' => 'ajax_liquidate_hours',
            'sistur_rh_grant_folga' => 'ajax_grant_folga',
            'sistur_rh_get_transactions' => 'ajax_get_transactions',
            'sistur_rh_get_punch_alerts' => 'ajax_get_punch_alerts',
            'sistur_rh_get_punches_spreadsheet' => 'ajax_get_punches_spreadsheet',
        );

        foreach ($handlers as $action => $method) {
            // wp_ajax_* = usuários WP logados
            // wp_ajax_nopriv_* = usuários do portal (não são WP users)
            add_action('wp_ajax_' . $action, array($this, $method));
            add_action('wp_ajax_nopriv_' . $action, array($this, $method));
        }
    }

    /**
     * Verifica autenticação via sessão do portal.
     * Substitui verify_nonce() pois usuários do portal não são WP users
     * e wp_verify_nonce() requer sessão WordPress ativa.
     */
    private function verify_portal_session()
    {
        // Garantir que a sessão está iniciada
        if (class_exists('SISTUR_Session')) {
            $session = SISTUR_Session::get_instance();
            if ($session->is_employee_logged_in()) {
                return; // Autenticado via portal
            }
        }

        // Fallback: aceitar WP admin também
        if (current_user_can('manage_options')) {
            return;
        }

        wp_send_json_error(array('message' => 'Não autenticado. Faça login no portal.'), 401);
        exit;
    }

    /**
     * Verifica se o usuário atual tem permissão
     * 
     * @param string $module Módulo
     * @param string $action Ação
     * @return bool
     */
    public function can($module, $action)
    {
        // Admin WordPress sempre tem permissão
        if (current_user_can('manage_options')) {
            return true;
        }

        // Lazy-load: garantir que o funcionário foi identificado
        // (necessário em contexto AJAX onde init pode não ter disparado)
        if (!$this->current_employee_id) {
            $this->identify_current_employee();
        }

        if (!$this->current_employee_id) {
            return false;
        }

        // Super admin do portal (is_admin=1 na tabela sistur_roles) tem todas as permissões
        if ($this->permissions->is_admin($this->current_employee_id)) {
            return true;
        }

        return $this->permissions->can($this->current_employee_id, $module, $action);
    }

    /**
     * Retorna permissões do usuário atual para o frontend
     */
    public function get_current_user_permissions()
    {
        return array(
            'view_dashboard' => $this->can('dashboard', 'view'),
            'view_employees' => $this->can('employees', 'view'),
            'edit_employees' => $this->can('employees', 'edit'),
            'manage_timebank' => $this->can('timebank', 'manage'),
            'manage_permissions' => $this->can('permissions', 'manage'),
            // is_admin: true para WP admin OU super admin do portal (is_admin=1 em sistur_roles)
            'is_admin' => current_user_can('manage_options') || ($this->current_employee_id && $this->permissions->is_admin($this->current_employee_id))
        );
    }

    /**
     * Renderiza o módulo de RH
     * 
     * @param bool $is_embedded Se true, renderiza sem header/nav duplicados para embutir em outros locais
     */
    public function render($is_embedded = false)
    {
        // Garantir que o funcionário atual foi identificado
        // (pode não ter sido se init ainda não disparou ou se a sessão mudou)
        if (!$this->current_employee_id) {
            $this->identify_current_employee();
        }

        // Verificar se tem pelo menos uma permissão para acessar
        if (
            !$this->can('dashboard', 'view') &&
            !$this->can('employees', 'view') &&
            !$this->can('timebank', 'manage') &&
            !current_user_can('manage_options')
        ) {
            return '<div class="sistur-rh-error">
                <h2>Acesso Negado</h2>
                <p>Você não tem permissão para acessar o módulo de RH.</p>
            </div>';
        }

        // Carregar assets
        $this->enqueue_assets();

        // Renderizar template
        ob_start();
        // A variável $is_embedded estará disponível no template
        include SISTUR_PLUGIN_DIR . 'templates/rh-module.php';
        return ob_get_clean();
    }

    /**
     * Carrega CSS e JS do módulo
     */
    private function enqueue_assets()
    {
        wp_enqueue_style(
            'sistur-rh-module',
            SISTUR_PLUGIN_URL . 'assets/css/rh-module.css',
            array(),
            SISTUR_VERSION
        );

        wp_enqueue_script(
            'sistur-rh-module',
            SISTUR_PLUGIN_URL . 'assets/js/rh-module.js',
            array('jquery'),
            SISTUR_VERSION,
            true
        );

        wp_localize_script('sistur-rh-module', 'sisturRH', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_rh_nonce'),
            'permissions' => $this->get_current_user_permissions(),
            'i18n' => array(
                'loading' => 'Carregando...',
                'error' => 'Ocorreu um erro',
                'confirm_liquidate' => 'Confirma a liquidação das horas?',
                'confirm_folga' => 'Confirma o lançamento da folga?',
                'success' => 'Operação realizada com sucesso'
            ),
            'departments' => SISTUR_Employees::get_all_departments(1),
            'roles' => SISTUR_Permissions::get_instance()->get_all_roles(),
            'shift_patterns' => SISTUR_Shift_Patterns::get_instance()->get_all_shift_patterns(1),
            'restBase' => rest_url('sistur/v1/time-bank/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'timeTrackingNonce' => wp_create_nonce('sistur_time_tracking_nonce')
        ));
    }

    // ==================== AJAX: DASHBOARD ====================

    /**
     * Retorna estatísticas do dashboard
     */
    public function ajax_get_dashboard_stats()
    {
        $this->verify_portal_session();

        if (!$this->can('dashboard', 'view') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Total de funcionários
        $total_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}sistur_employees WHERE status = 1");
        $inactive_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}sistur_employees WHERE status = 0");

        // Alertas de batidas incompletas (últimos 7 dias)
        $incomplete_punches = $this->get_incomplete_punch_count();

        // Saldo total do banco de horas
        $total_bank_minutes = $wpdb->get_var("SELECT SUM(saldo_final_minutos) FROM {$prefix}sistur_time_days");
        $total_bank_hours = round($total_bank_minutes / 60, 1);

        // Pendências de aprovação
        $pending_deductions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}sistur_timebank_deductions WHERE approval_status = 'pendente'"
        );

        wp_send_json_success(array(
            'total_employees' => (int) $total_employees,
            'inactive_employees' => (int) $inactive_employees,
            'incomplete_punches' => (int) $incomplete_punches,
            'total_bank_hours' => $total_bank_hours,
            'total_bank_formatted' => $this->format_hours($total_bank_minutes),
            'pending_deductions' => (int) $pending_deductions
        ));
    }

    /**
     * Conta dias com batidas incompletas nos últimos 7 dias
     */
    private function get_incomplete_punch_count()
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');

        // Buscar dias com número ímpar de batidas ou menos de 4
        $query = "
            SELECT COUNT(DISTINCT CONCAT(employee_id, '-', shift_date)) as count
            FROM (
                SELECT 
                    employee_id, 
                    shift_date, 
                    COUNT(*) as punch_count
                FROM {$prefix}sistur_time_entries
                WHERE shift_date BETWEEN %s AND %s
                GROUP BY employee_id, shift_date
                HAVING punch_count %% 2 != 0 OR (punch_count < 4 AND punch_count > 0)
            ) as incomplete
        ";

        return $wpdb->get_var($wpdb->prepare($query, $start_date, $end_date)) ?: 0;
    }

    // ==================== AJAX: COLABORADORES ====================

    /**
     * Lista funcionários
     */
    public function ajax_get_employees()
    {
        $this->verify_portal_session();

        if (!$this->can('employees', 'view') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $department = isset($_POST['department']) ? intval($_POST['department']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $where = array();
        $params = array();

        if ($status === 'active') {
            $where[] = 'e.status = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'e.status = 0';
        }

        if ($search) {
            $where[] = '(e.name LIKE %s OR e.email LIKE %s OR e.cpf LIKE %s)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($department) {
            $where[] = 'e.department_id = %d';
            $params[] = $department;
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Contagem total
        $count_query = "SELECT COUNT(*) FROM {$prefix}sistur_employees e $where_clause";
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare($count_query, ...$params));
        } else {
            $total = $wpdb->get_var($count_query);
        }

        // Buscar funcionários com saldo do banco
        $query = "
            SELECT 
                e.id,
                e.name,
                e.email,
                e.phone,
                e.cpf,
                e.position,
                e.hire_date,
                e.status,
                d.name as department_name,
                r.name as role_name,
                COALESCE(SUM(td.saldo_final_minutos), 0) as bank_balance
            FROM {$prefix}sistur_employees e
            LEFT JOIN {$prefix}sistur_departments d ON e.department_id = d.id
            LEFT JOIN {$prefix}sistur_roles r ON e.role_id = r.id
            LEFT JOIN {$prefix}sistur_time_days td ON e.id = td.employee_id
            $where_clause
            GROUP BY e.id
            ORDER BY e.name ASC
            LIMIT %d OFFSET %d
        ";

        $params[] = $per_page;
        $params[] = $offset;

        $employees = $wpdb->get_results($wpdb->prepare($query, ...$params));

        // Formatar saldos
        foreach ($employees as &$emp) {
            $emp->bank_balance_formatted = $this->format_hours($emp->bank_balance);
        }

        wp_send_json_success(array(
            'employees' => $employees,
            'total' => (int) $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }

    /**
     * Busca um funcionário específico
     */
    public function ajax_get_employee()
    {
        $this->verify_portal_session();

        if (!$this->can('employees', 'view') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;

        if (!$employee_id) {
            wp_send_json_error(array('message' => 'ID inválido'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $employee = $wpdb->get_row($wpdb->prepare("
            SELECT 
                e.*,
                d.name as department_name,
                r.name as role_name,
                r.id as role_id
            FROM {$prefix}sistur_employees e
            LEFT JOIN {$prefix}sistur_departments d ON e.department_id = d.id
            LEFT JOIN {$prefix}sistur_roles r ON e.role_id = r.id
            WHERE e.id = %d
        ", $employee_id));

        if (!$employee) {
            wp_send_json_error(array('message' => 'Funcionário não encontrado'));
        }

        // Buscar saldo do banco
        $bank_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM {$prefix}sistur_time_days WHERE employee_id = %d",
            $employee_id
        ));

        $employee->bank_balance = (int) $bank_balance;
        $employee->bank_balance_formatted = $this->format_hours($bank_balance);

        // Buscar últimas batidas
        $recent_punches = $wpdb->get_results($wpdb->prepare("
            SELECT shift_date, COUNT(*) as punch_count
            FROM {$prefix}sistur_time_entries
            WHERE employee_id = %d
            GROUP BY shift_date
            ORDER BY shift_date DESC
            LIMIT 7
        ", $employee_id));

        $employee->recent_punches = $recent_punches;

        wp_send_json_success($employee);
    }

    /**
     * Salva dados de um funcionário (com auditoria)
     */
    public function ajax_save_employee()
    {
        $this->verify_portal_session();

        if (!$this->can('employees', 'edit') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão para editar'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $is_new = ($employee_id === 0);

        global $wpdb;
        $prefix = $wpdb->prefix;
        $old_data = null;

        // Se for edição, buscar dados antigos
        if (!$is_new) {
            $old_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}sistur_employees WHERE id = %d",
                $employee_id
            ), ARRAY_A);

            if (!$old_data) {
                wp_send_json_error(array('message' => 'Funcionário não encontrado'));
            }
        }

        // Preparar novos dados
        $new_data = array();
        $format = array();

        $allowed_fields = array(
            'name' => '%s',
            'email' => '%s',
            'phone' => '%s',
            'cpf' => '%s',
            'matricula' => '%s',
            'ctps' => '%s',
            'ctps_uf' => '%s',
            'cbo' => '%s',
            'position' => '%s',
            'department_id' => '%d',
            'role_id' => '%d',
            'hire_date' => '%s',
            'shift_pattern_id' => '%d',
            'status' => '%d'
        );

        foreach ($allowed_fields as $field => $fmt) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $new_data[$field] = $fmt === '%d' ? intval($_POST[$field]) : sanitize_text_field($_POST[$field]);
                $format[] = $fmt;
            }
        }

        // Tratar senha separadamente (hash)
        if (!empty($_POST['password'])) {
            $new_data['password_hash'] = wp_hash_password($_POST['password']);
            $format[] = '%s';
        }

        if (empty($new_data)) {
            wp_send_json_error(array('message' => 'Nenhum dado para atualizar'));
        }

        // Debug: ver o que está sendo salvo
        error_log('💾 Salvando funcionário ID ' . $employee_id . ': ' . print_r($new_data, true));

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        try {
            if ($is_new) {
                // CRIAR funcionário
                $result = $wpdb->insert(
                    $prefix . 'sistur_employees',
                    $new_data,
                    $format
                );

                if ($result === false) {
                    throw new Exception('Erro ao criar: ' . $wpdb->last_error);
                }

                $employee_id = $wpdb->insert_id;

                // Auditoria
                $this->audit->log(
                    SISTUR_Audit::ACTION_CREATE,
                    'employees',
                    $employee_id,
                    array(
                        'new_values' => $new_data,
                        'created_by' => get_current_user_id()
                    )
                );

                $msg = 'Funcionário criado com sucesso';

            } else {
                // ATUALIZAR funcionário
                $result = $wpdb->update(
                    $prefix . 'sistur_employees',
                    $new_data,
                    array('id' => $employee_id),
                    $format,
                    array('%d')
                );

                if ($result === false) {
                    throw new Exception('Erro ao atualizar: ' . $wpdb->last_error);
                }

                // Auditoria
                $this->audit->log(
                    SISTUR_Audit::ACTION_UPDATE,
                    'employees',
                    $employee_id,
                    array(
                        'old_values' => array_intersect_key($old_data, $new_data),
                        'new_values' => $new_data,
                        'changed_by' => get_current_user_id()
                    )
                );

                $msg = 'Funcionário atualizado com sucesso';
            }

            $wpdb->query('COMMIT');

            wp_send_json_success(array('message' => $msg));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    // ==================== AJAX: BANCO DE HORAS ====================

    /**
     * Busca dados do banco de horas de um funcionário
     */
    public function ajax_get_timebank_data()
    {
        $this->verify_portal_session();

        if (!$this->can('timebank', 'manage') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;

        if (!$employee_id) {
            wp_send_json_error(array('message' => 'ID inválido'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Dados do funcionário
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$prefix}sistur_employees WHERE id = %d",
            $employee_id
        ));

        // Saldo atual
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM {$prefix}sistur_time_days WHERE employee_id = %d",
            $employee_id
        ));

        // Debug do Banco de Horas
        error_log("💰 DBG Timebank Employee $employee_id: Total Balance Minutes = $balance");
        if ($balance == 0 || $balance === null) {
            // Verificar se há registros
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}sistur_time_days WHERE employee_id = %d", $employee_id));
            error_log("💰 DBG Timebank: Found $count days for employee $employee_id");
        }

        // Histórico recente de dias
        $recent_days = $wpdb->get_results($wpdb->prepare("
            SELECT 
                shift_date,
                status,
                minutos_trabalhados,
                saldo_calculado_minutos,
                saldo_final_minutos,
                needs_review
            FROM {$prefix}sistur_time_days
            WHERE employee_id = %d
            ORDER BY shift_date DESC
            LIMIT 30
        ", $employee_id));

        // Configuração de pagamento
        $payment_config = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}sistur_employee_payment_config WHERE employee_id = %d",
            $employee_id
        ));

        wp_send_json_success(array(
            'employee' => $employee,
            'balance_minutes' => (int) $balance,
            'balance_formatted' => $this->format_hours($balance),
            'recent_days' => $recent_days,
            'payment_config' => $payment_config
        ));
    }

    /**
     * Liquida horas (pagamento) com auditoria e transação
     */
    public function ajax_liquidate_hours()
    {
        $this->verify_portal_session();

        if (!$this->can('timebank', 'manage') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $minutes = isset($_POST['minutes']) ? intval($_POST['minutes']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

        if (!$employee_id || !$minutes) {
            wp_send_json_error(array('message' => 'Dados inválidos'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Buscar saldo atual
        $current_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM {$prefix}sistur_time_days WHERE employee_id = %d",
            $employee_id
        ));

        if ($minutes > $current_balance) {
            wp_send_json_error(array('message' => 'Saldo insuficiente. Saldo atual: ' . $this->format_hours($current_balance)));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $new_balance = $current_balance - $minutes;

            // Inserir registro de dedução
            $wpdb->insert($prefix . 'sistur_timebank_deductions', array(
                'employee_id' => $employee_id,
                'deduction_type' => 'pagamento',
                'minutes_deducted' => $minutes,
                'balance_before_minutes' => $current_balance,
                'balance_after_minutes' => $new_balance,
                'notes' => $notes,
                'approval_status' => 'aprovado', // Auto-aprovado pelo gestor
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            ));

            $deduction_id = $wpdb->insert_id;

            // Atualizar saldos nos dias (distribuir dedução)
            $this->distribute_deduction($employee_id, $minutes);

            // Registrar auditoria
            $this->audit->log(
                'liquidate_hours',
                'timebank',
                $employee_id,
                array(
                    'deduction_id' => $deduction_id,
                    'minutes_deducted' => $minutes,
                    'old_balance' => $current_balance,
                    'new_balance' => $new_balance,
                    'notes' => $notes,
                    'operator_id' => get_current_user_id()
                )
            );

            $wpdb->query('COMMIT');

            wp_send_json_success(array(
                'message' => 'Horas liquidadas com sucesso',
                'new_balance' => $new_balance,
                'new_balance_formatted' => $this->format_hours($new_balance)
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => 'Erro: ' . $e->getMessage()));
        }
    }

    /**
     * Lança folga (compensação) com auditoria e transação
     */
    public function ajax_grant_folga()
    {
        $this->verify_portal_session();

        if (!$this->can('timebank', 'manage') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $minutes = isset($_POST['minutes']) ? intval($_POST['minutes']) : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : $start_date;
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        if (!$employee_id || !$minutes || !$start_date) {
            wp_send_json_error(array('message' => 'Dados inválidos'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Buscar saldo atual
        $current_balance = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM {$prefix}sistur_time_days WHERE employee_id = %d",
            $employee_id
        ));

        if ($minutes > $current_balance) {
            wp_send_json_error(array('message' => 'Saldo insuficiente. Saldo atual: ' . $this->format_hours($current_balance)));
        }

        $wpdb->query('START TRANSACTION');

        try {
            $new_balance = $current_balance - $minutes;

            // Inserir registro de dedução
            $wpdb->insert($prefix . 'sistur_timebank_deductions', array(
                'employee_id' => $employee_id,
                'deduction_type' => 'folga',
                'minutes_deducted' => $minutes,
                'balance_before_minutes' => $current_balance,
                'balance_after_minutes' => $new_balance,
                'time_off_start_date' => $start_date,
                'time_off_end_date' => $end_date,
                'time_off_description' => $description,
                'approval_status' => 'aprovado',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            ));

            $deduction_id = $wpdb->insert_id;

            // Atualizar saldos nos dias
            $this->distribute_deduction($employee_id, $minutes);

            // Registrar auditoria
            $this->audit->log(
                'grant_folga',
                'timebank',
                $employee_id,
                array(
                    'deduction_id' => $deduction_id,
                    'minutes_deducted' => $minutes,
                    'old_balance' => $current_balance,
                    'new_balance' => $new_balance,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'description' => $description,
                    'operator_id' => get_current_user_id()
                )
            );

            $wpdb->query('COMMIT');

            wp_send_json_success(array(
                'message' => 'Folga lançada com sucesso',
                'new_balance' => $new_balance,
                'new_balance_formatted' => $this->format_hours($new_balance)
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => 'Erro: ' . $e->getMessage()));
        }
    }

    /**
     * Distribui dedução entre os dias com saldo positivo
     */
    private function distribute_deduction($employee_id, $minutes_to_deduct)
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Buscar dias com saldo positivo (mais antigos primeiro)
        $days = $wpdb->get_results($wpdb->prepare("
            SELECT id, saldo_final_minutos
            FROM {$prefix}sistur_time_days
            WHERE employee_id = %d AND saldo_final_minutos > 0
            ORDER BY shift_date ASC
        ", $employee_id));

        $remaining = $minutes_to_deduct;

        foreach ($days as $day) {
            if ($remaining <= 0)
                break;

            $deduct_from_day = min($day->saldo_final_minutos, $remaining);
            $new_day_balance = $day->saldo_final_minutos - $deduct_from_day;

            $wpdb->update(
                $prefix . 'sistur_time_days',
                array('saldo_final_minutos' => $new_day_balance),
                array('id' => $day->id),
                array('%d'),
                array('%d')
            );

            $remaining -= $deduct_from_day;
        }
    }

    /**
     * Lista transações do banco de horas
     */
    public function ajax_get_transactions()
    {
        $this->verify_portal_session();

        if (!$this->can('timebank', 'manage') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $limit = isset($_POST['limit']) ? min(100, intval($_POST['limit'])) : 50;

        $where = array('1=1');
        $params = array();

        if ($employee_id) {
            $where[] = 'd.employee_id = %d';
            $params[] = $employee_id;
        }

        if ($type) {
            $where[] = 'd.deduction_type = %s';
            $params[] = $type;
        }

        $where_clause = implode(' AND ', $where);

        $query = "
            SELECT 
                d.*,
                e.name as employee_name,
                u.display_name as approved_by_name
            FROM {$prefix}sistur_timebank_deductions d
            LEFT JOIN {$prefix}sistur_employees e ON d.employee_id = e.id
            LEFT JOIN {$wpdb->users} u ON d.approved_by = u.ID
            WHERE $where_clause
            ORDER BY d.created_at DESC
            LIMIT %d
        ";

        $params[] = $limit;

        $transactions = $wpdb->get_results($wpdb->prepare($query, ...$params));

        // Formatar minutos
        foreach ($transactions as &$t) {
            $t->minutes_formatted = $this->format_hours($t->minutes_deducted);
            $t->balance_before_formatted = $this->format_hours($t->balance_before_minutes);
            $t->balance_after_formatted = $this->format_hours($t->balance_after_minutes);
        }

        wp_send_json_success(array('transactions' => $transactions));
    }

    /**
     * Lista alertas de batidas incompletas
     */
    public function ajax_get_punch_alerts()
    {
        $this->verify_portal_session();

        if (!$this->can('timebank', 'manage') && !$this->can('dashboard', 'view') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d', strtotime('-7 days'));
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');

        $query = "
            SELECT 
                e.id as employee_id,
                e.name as employee_name,
                t.shift_date,
                COUNT(t.id) as punch_count,
                GROUP_CONCAT(t.punch_type ORDER BY t.punch_time) as punch_types
            FROM {$prefix}sistur_time_entries t
            JOIN {$prefix}sistur_employees e ON t.employee_id = e.id
            WHERE t.shift_date BETWEEN %s AND %s
              AND e.status = 1
            GROUP BY e.id, t.shift_date
            HAVING punch_count %% 2 != 0 OR (punch_count < 4 AND punch_count > 0)
            ORDER BY t.shift_date DESC, e.name
        ";

        $alerts = $wpdb->get_results($wpdb->prepare($query, $start_date, $end_date));

        wp_send_json_success(array('alerts' => $alerts));
    }

    /**
     * Retorna dados para a planilha de batidas por funcionário
     * Organizado por mês, com cada funcionário tendo múltiplas linhas
     */
    public function ajax_get_punches_spreadsheet()
    {
        $this->verify_portal_session();

        if (!$this->can('dashboard', 'view') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Receber mês/ano (formato: YYYY-MM)
        $month_year = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : date('Y-m');

        // Validar formato
        if (!preg_match('/^\d{4}-\d{2}$/', $month_year)) {
            $month_year = date('Y-m');
        }

        // Calcular primeiro e último dia do mês
        $first_day = $month_year . '-01';
        $last_day = date('Y-m-t', strtotime($first_day));
        $days_in_month = (int) date('t', strtotime($first_day));

        // Buscar todos os funcionários ativos
        $employees = $wpdb->get_results("
            SELECT id, name 
            FROM {$prefix}sistur_employees 
            WHERE status = 1 
            ORDER BY name ASC
        ");

        // Buscar todas as batidas do mês agrupadas por funcionário e dia
        $punches = $wpdb->get_results($wpdb->prepare("
            SELECT 
                employee_id,
                shift_date,
                COUNT(*) as punch_count
            FROM {$prefix}sistur_time_entries
            WHERE shift_date BETWEEN %s AND %s
            GROUP BY employee_id, shift_date
            ORDER BY shift_date ASC
        ", $first_day, $last_day));

        // Criar lookup de batidas: employee_id_day => count
        $punch_lookup = array();
        foreach ($punches as $punch) {
            $day = (int) date('j', strtotime($punch->shift_date));
            $key = $punch->employee_id . '_' . $day;
            $punch_lookup[$key] = (int) $punch->punch_count;
        }

        // Montar estrutura para a planilha
        // Cada funcionário tem um bloco com múltiplas linhas possíveis
        $data = array();

        foreach ($employees as $employee) {
            $employee_data = array(
                'id' => $employee->id,
                'name' => $employee->name,
                'rows' => array()
            );

            // Linha 1: Batidas de ponto
            $punches_row = array(
                'label' => 'Batidas de Ponto',
                'values' => array()
            );

            for ($day = 1; $day <= $days_in_month; $day++) {
                $key = $employee->id . '_' . $day;
                $count = isset($punch_lookup[$key]) ? $punch_lookup[$key] : null;
                $punches_row['values'][$day] = $count;
            }

            $employee_data['rows'][] = $punches_row;

            // Aqui podem ser adicionadas mais linhas no futuro
            // Exemplo: Horas trabalhadas, Saldo do dia, etc.

            $data[] = $employee_data;
        }

        // Identificar fins de semana
        $weekends = array();
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = $month_year . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
            $dow = (int) date('w', strtotime($date));
            if ($dow === 0 || $dow === 6) {
                $weekends[] = $day;
            }
        }

        wp_send_json_success(array(
            'month' => $month_year,
            'month_label' => ucfirst(strftime('%B %Y', strtotime($first_day))),
            'days_in_month' => $days_in_month,
            'weekends' => $weekends,
            'employees' => $data,
            'total_employees' => count($employees)
        ));
    }

    // ==================== HELPERS ====================

    /**
     * Formata minutos em formato legível
     */
    private function format_hours($minutes)
    {
        $minutes = (int) $minutes;
        $sign = $minutes >= 0 ? '+' : '-';
        $abs_minutes = abs($minutes);
        $hours = floor($abs_minutes / 60);
        $mins = $abs_minutes % 60;
        return sprintf('%s%dh %02dmin', $sign, $hours, $mins);
    }
}

// Inicializar
add_action('plugins_loaded', function () {
    SISTUR_RH_Module::get_instance();
});
