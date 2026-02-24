<?php
/**
 * Classe de registro de ponto eletrônico
 *
 * @package SISTUR
 */

class SISTUR_Time_Tracking {

    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $this->init_hooks();
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
     * Traduz o tipo de batida para português
     *
     * @param string $punch_type Tipo de batida em inglês
     * @return string Tipo de batida traduzido
     */
    public static function translate_punch_type($punch_type) {
        $translations = array(
            'clock_in' => 'Entrada',
            'lunch_start' => 'Início Almoço',
            'lunch_end' => 'Fim Almoço',
            'clock_out' => 'Saída',
            'auto' => 'Automático',
            'extra' => 'Extra'
        );

        return isset($translations[$punch_type]) ? $translations[$punch_type] : ucfirst($punch_type);
    }

    /**
     * Detecta o papel do usuário atual (admin ou gerente)
     *
     * @return string 'administrador' ou 'gerente'
     */
    private function get_user_role_label() {
        global $wpdb;
        $current_user_id = get_current_user_id();

        // Verificar se é administrador do WordPress
        if (current_user_can('administrator')) {
            return 'administrador';
        }

        // Verificar se tem papel de gerente no sistema SISTUR
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $table_roles = $wpdb->prefix . 'sistur_roles';

        $role_name = $wpdb->get_var($wpdb->prepare(
            "SELECT r.name
            FROM $table_employees e
            INNER JOIN $table_roles r ON e.role_id = r.id
            WHERE e.user_id = %d",
            $current_user_id
        ));

        if ($role_name && (stripos($role_name, 'gerente') !== false || stripos($role_name, 'supervisor') !== false)) {
            return 'gerente';
        }

        // Padrão: administrador (se tem manage_options)
        return current_user_can('manage_options') ? 'administrador' : 'gerente';
    }

    /**
     * Inicializa os hooks
     */
    private function init_hooks() {
        // AJAX handlers (admin)
        add_action('wp_ajax_sistur_time_get_profiles', array($this, 'ajax_get_profiles'));
        add_action('wp_ajax_sistur_time_save_profile', array($this, 'ajax_save_profile'));
        add_action('wp_ajax_sistur_time_get_sheet', array($this, 'ajax_get_sheet'));
        add_action('wp_ajax_sistur_time_save_entry', array($this, 'ajax_save_entry'));
        add_action('wp_ajax_sistur_time_update_entry', array($this, 'ajax_update_entry'));
        add_action('wp_ajax_sistur_time_delete_entry', array($this, 'ajax_delete_entry'));
        add_action('wp_ajax_sistur_time_day_status', array($this, 'ajax_day_status'));
        add_action('wp_ajax_sistur_time_get_day_detail', array($this, 'ajax_get_day_detail'));

        // AJAX handlers (público)
        add_action('wp_ajax_sistur_time_public_clock', array($this, 'ajax_public_clock'));
        add_action('wp_ajax_nopriv_sistur_time_public_clock', array($this, 'ajax_public_clock'));
        add_action('wp_ajax_sistur_time_public_status', array($this, 'ajax_public_status'));
        add_action('wp_ajax_nopriv_sistur_time_public_status', array($this, 'ajax_public_status'));

        // Scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_scripts'));
    }

    /**
     * Carrega scripts do admin
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'sistur-time-tracking') === false) {
            return;
        }

        wp_enqueue_script('sistur-time-tracking-admin', SISTUR_PLUGIN_URL . 'assets/js/time-tracking-admin.js', array('jquery', 'jquery-ui-datepicker'), SISTUR_VERSION, true);

        wp_localize_script('sistur-time-tracking-admin', 'sisturTimeTracking', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sistur_time_tracking_nonce')
        ));
    }

    /**
     * Carrega scripts públicos
     */
    public function enqueue_public_scripts() {
        if (is_page('painel-funcionario')) {
            wp_enqueue_script('sistur-time-tracking-public', SISTUR_PLUGIN_URL . 'assets/js/time-tracking-public.js', array('jquery'), SISTUR_VERSION, true);

            wp_localize_script('sistur-time-tracking-public', 'sisturTimePublic', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sistur_time_public_nonce')
            ));
        }
    }

    /**
     * AJAX: Obter perfis de funcionários
     */
    public function ajax_get_profiles() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employees = SISTUR_Employees::get_all_employees(array('status' => 1));

        wp_send_json_success(array('profiles' => $employees));
    }

    /**
     * AJAX: Salvar perfil de funcionário
     */
    public function ajax_save_profile() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employees';

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $time_expected_minutes = isset($_POST['time_expected_minutes']) ? intval($_POST['time_expected_minutes']) : 480;
        $lunch_minutes = isset($_POST['lunch_minutes']) ? intval($_POST['lunch_minutes']) : 60;

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        $result = $wpdb->update(
            $table,
            array(
                'time_expected_minutes' => $time_expected_minutes,
                'lunch_minutes' => $lunch_minutes
            ),
            array('id' => $employee_id),
            array('%d', '%d'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao salvar perfil.'));
        }

        wp_send_json_success(array('message' => 'Perfil atualizado com sucesso!'));
    }

    /**
     * AJAX: Obter folha de ponto
     */
    public function ajax_get_sheet() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-t');

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'Funcionário inválido.'));
        }

        global $wpdb;
        $entries_table = $wpdb->prefix . 'sistur_time_entries';
        $days_table = $wpdb->prefix . 'sistur_time_days';

        // Obter registros de ponto
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $entries_table
            WHERE employee_id = %d
            AND shift_date BETWEEN %s AND %s
            ORDER BY shift_date ASC, punch_time ASC",
            $employee_id, $start_date, $end_date
        ), ARRAY_A);

        // Obter status dos dias
        $day_statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $days_table
            WHERE employee_id = %d
            AND shift_date BETWEEN %s AND %s",
            $employee_id, $start_date, $end_date
        ), ARRAY_A);

        // Organizar por data
        $sheet_data = array();
        foreach ($entries as $entry) {
            $date = $entry['shift_date'];
            if (!isset($sheet_data[$date])) {
                $sheet_data[$date] = array(
                    'date' => $date,
                    'entries' => array(),
                    'status' => 'present'
                );
            }
            // Adicionar tradução do tipo de batida
            $entry['punch_type_label'] = self::translate_punch_type($entry['punch_type']);
            $sheet_data[$date]['entries'][] = $entry;
        }

        // Adicionar status dos dias
        foreach ($day_statuses as $day) {
            $date = $day['shift_date'];
            if (isset($sheet_data[$date])) {
                $sheet_data[$date]['status'] = $day['status'];
                $sheet_data[$date]['notes'] = $day['notes'];
            } else {
                $sheet_data[$date] = array(
                    'date' => $date,
                    'entries' => array(),
                    'status' => $day['status'],
                    'notes' => $day['notes']
                );
            }
        }

        wp_send_json_success(array('sheet' => array_values($sheet_data)));
    }

    /**
     * AJAX: Salvar registro de ponto
     */
    public function ajax_save_entry() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $punch_type = isset($_POST['punch_type']) ? sanitize_text_field($_POST['punch_type']) : '';
        $punch_time = isset($_POST['punch_time']) ? sanitize_text_field($_POST['punch_time']) : '';
        $shift_date = isset($_POST['shift_date']) ? sanitize_text_field($_POST['shift_date']) : date('Y-m-d');
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $admin_change_reason = isset($_POST['admin_change_reason']) ? sanitize_textarea_field($_POST['admin_change_reason']) : '';

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'Funcionário inválido.'));
        }

        $valid_types = array('clock_in', 'lunch_start', 'lunch_end', 'clock_out');
        if (!in_array($punch_type, $valid_types)) {
            wp_send_json_error(array('message' => 'Tipo de registro inválido.'));
        }

        // VALIDAÇÃO: Exigir motivo de alteração
        if (empty($admin_change_reason)) {
            wp_send_json_error(array('message' => 'É obrigatório informar o motivo da alteração administrativa.'));
        }

        // Detectar papel do usuário
        $user_role = $this->get_user_role_label();
        $current_user_id = get_current_user_id();

        // Formatar o motivo com informação do tipo de usuário
        $formatted_reason = "Alterado por {$user_role} devido: {$admin_change_reason}";

        // Verificar quais colunas existem na tabela
        $columns = $wpdb->get_col("DESCRIBE $table", 0);
        
        // Dados OBRIGATÓRIOS (colunas que devem existir em qualquer versão)
        $data = array(
            'employee_id' => $employee_id,
            'punch_type' => $punch_type,
            'punch_time' => $punch_time,
            'shift_date' => $shift_date
        );
        
        $format = array('%d', '%s', '%s', '%s');
        
        // Adicionar todas as outras colunas APENAS se existirem
        if (in_array('notes', $columns)) {
            $data['notes'] = $notes;
            $format[] = '%s';
        }
        if (in_array('source', $columns)) {
            $data['source'] = 'admin';
            $format[] = '%s';
        }
        if (in_array('created_by', $columns)) {
            $data['created_by'] = $current_user_id;
            $format[] = '%d';
        }
        if (in_array('admin_change_reason', $columns)) {
            $data['admin_change_reason'] = $formatted_reason;
            $format[] = '%s';
        }
        if (in_array('changed_by_user_id', $columns)) {
            $data['changed_by_user_id'] = $current_user_id;
            $format[] = '%d';
        }
        if (in_array('changed_by_role', $columns)) {
            $data['changed_by_role'] = $user_role;
            $format[] = '%s';
        }

        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            error_log("SISTUR BULK INSERT ERRO: " . $wpdb->last_error);
            error_log("SISTUR BULK INSERT DATA: " . print_r($data, true));
            error_log("SISTUR BULK INSERT FORMAT: " . print_r($format, true));
            wp_send_json_error(array('message' => 'Erro ao salvar registro: ' . $wpdb->last_error));
        }

        // Registrar na auditoria
        if (class_exists('SISTUR_Audit')) {
            $audit = SISTUR_Audit::get_instance();
            $audit->log(
                'create',
                'time_tracking',
                $wpdb->insert_id,
                array(
                    'new_values' => $data,
                    'reason' => $formatted_reason,
                    'user_role' => $user_role
                )
            );
        }

        // RECÁLCULO AUTOMÁTICO: Processar o dia do funcionário após criação
        if (class_exists('SISTUR_Punch_Processing')) {
            $processor = SISTUR_Punch_Processing::get_instance();
            $processor->process_employee_day($employee_id, $shift_date);
            error_log("SISTUR: Banco de horas recalculado após criação para funcionário $employee_id no dia $shift_date");
        }

        wp_send_json_success(array(
            'message' => 'Registro salvo com sucesso!',
            'entry_id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX: Atualizar registro de ponto existente
     */
    public function ajax_update_entry() {
        // Log para debug
        error_log('SISTUR: ajax_update_entry chamado');
        error_log('SISTUR: POST data: ' . print_r($_POST, true));

        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('SISTUR: Permissão negada');
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';

        $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $punch_type = isset($_POST['punch_type']) ? sanitize_text_field($_POST['punch_type']) : '';
        $punch_time = isset($_POST['punch_time']) ? sanitize_text_field($_POST['punch_time']) : '';
        $shift_date = isset($_POST['shift_date']) ? sanitize_text_field($_POST['shift_date']) : date('Y-m-d');
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $admin_change_reason = isset($_POST['admin_change_reason']) ? sanitize_textarea_field($_POST['admin_change_reason']) : '';

        error_log("SISTUR: Dados processados - entry_id: $entry_id, employee_id: $employee_id, punch_type: $punch_type, punch_time: $punch_time");

        if ($entry_id <= 0) {
            error_log('SISTUR: ID de registro inválido');
            wp_send_json_error(array('message' => 'ID de registro inválido.'));
        }

        if ($employee_id <= 0) {
            error_log('SISTUR: Funcionário inválido');
            wp_send_json_error(array('message' => 'Funcionário inválido.'));
        }

        $valid_types = array('clock_in', 'lunch_start', 'lunch_end', 'clock_out');
        if (!in_array($punch_type, $valid_types)) {
            error_log("SISTUR: Tipo de registro inválido: $punch_type");
            wp_send_json_error(array('message' => 'Tipo de registro inválido.'));
        }

        // VALIDAÇÃO: Exigir motivo de alteração
        if (empty($admin_change_reason)) {
            wp_send_json_error(array('message' => 'É obrigatório informar o motivo da alteração administrativa.'));
        }

        // Buscar dados antigos para auditoria
        $old_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $entry_id), ARRAY_A);

        // Detectar papel do usuário
        $user_role = $this->get_user_role_label();
        $current_user_id = get_current_user_id();

        // Formatar o motivo com informação do tipo de usuário
        $formatted_reason = "Alterado por {$user_role} devido: {$admin_change_reason}";

        $data = array(
            'employee_id' => $employee_id,
            'punch_type' => $punch_type,
            'punch_time' => $punch_time,
            'shift_date' => $shift_date,
            'notes' => $notes,
            'admin_change_reason' => $formatted_reason,
            'changed_by_user_id' => $current_user_id,
            'changed_by_role' => $user_role
        );

        error_log('SISTUR: Dados para atualização: ' . print_r($data, true));

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $entry_id),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'),
            array('%d')
        );

        if ($result === false) {
            error_log('SISTUR: Erro no wpdb->update: ' . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Erro ao atualizar registro: ' . $wpdb->last_error));
        }

        error_log("SISTUR: Registro atualizado com sucesso! Linhas afetadas: $result");

        // Registrar na auditoria
        if (class_exists('SISTUR_Audit')) {
            $audit = SISTUR_Audit::get_instance();
            $audit->log(
                'update',
                'time_tracking',
                $entry_id,
                array(
                    'old_values' => $old_data,
                    'new_values' => $data,
                    'reason' => $formatted_reason,
                    'user_role' => $user_role
                )
            );
        }

        // RECÁLCULO AUTOMÁTICO: Processar o dia do funcionário após edição
        if (class_exists('SISTUR_Punch_Processing')) {
            $processor = SISTUR_Punch_Processing::get_instance();
            $processor->process_employee_day($employee_id, $shift_date);
            error_log("SISTUR: Banco de horas recalculado para funcionário $employee_id no dia $shift_date");
        }

        wp_send_json_success(array(
            'message' => 'Registro atualizado com sucesso!',
            'entry_id' => $entry_id,
            'rows_affected' => $result
        ));
    }

    /**
     * AJAX: Deletar registro de ponto
     */
    public function ajax_delete_entry() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
        $admin_change_reason = isset($_POST['admin_change_reason']) ? sanitize_textarea_field($_POST['admin_change_reason']) : '';

        if ($entry_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        // VALIDAÇÃO: Exigir motivo de alteração
        if (empty($admin_change_reason)) {
            wp_send_json_error(array('message' => 'É obrigatório informar o motivo da exclusão.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';

        // Buscar dados antigos para auditoria
        $old_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $entry_id), ARRAY_A);

        // Detectar papel do usuário
        $user_role = $this->get_user_role_label();

        // Formatar o motivo com informação do tipo de usuário
        $formatted_reason = "Excluído por {$user_role} devido: {$admin_change_reason}";

        $result = $wpdb->delete($table, array('id' => $entry_id), array('%d'));

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao excluir registro.'));
        }

        // Registrar na auditoria
        if (class_exists('SISTUR_Audit')) {
            $audit = SISTUR_Audit::get_instance();
            $audit->log(
                'delete',
                'time_tracking',
                $entry_id,
                array(
                    'old_values' => $old_data,
                    'reason' => $formatted_reason,
                    'user_role' => $user_role
                )
            );
        }

        // RECÁLCULO AUTOMÁTICO: Processar o dia do funcionário após exclusão
        if (class_exists('SISTUR_Punch_Processing') && $old_data) {
            $processor = SISTUR_Punch_Processing::get_instance();
            $processor->process_employee_day($old_data['employee_id'], $old_data['shift_date']);
            error_log("SISTUR: Banco de horas recalculado após exclusão para funcionário {$old_data['employee_id']} no dia {$old_data['shift_date']}");
        }

        wp_send_json_success(array('message' => 'Registro excluído com sucesso!'));
    }

    /**
     * AJAX: Salvar status do dia
     */
    public function ajax_day_status() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_days';

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $shift_date = isset($_POST['shift_date']) ? sanitize_text_field($_POST['shift_date']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'present';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $admin_change_reason = isset($_POST['admin_change_reason']) ? sanitize_textarea_field($_POST['admin_change_reason']) : '';

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'Funcionário inválido.'));
        }

        // VALIDAÇÃO: Exigir motivo de alteração
        if (empty($admin_change_reason)) {
            wp_send_json_error(array('message' => 'É obrigatório informar o motivo da alteração do status.'));
        }

        // Detectar papel do usuário
        $user_role = $this->get_user_role_label();
        $current_user_id = get_current_user_id();

        // Formatar o motivo com informação do tipo de usuário
        $formatted_reason = "Alterado por {$user_role} devido: {$admin_change_reason}";

        // Verificar se já existe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE employee_id = %d AND shift_date = %s",
            $employee_id, $shift_date
        ));

        if ($existing) {
            // Buscar dados antigos para auditoria
            $old_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $existing), ARRAY_A);

            // Atualizar
            $result = $wpdb->update(
                $table,
                array(
                    'status' => $status,
                    'notes' => $notes,
                    'admin_change_reason' => $formatted_reason,
                    'changed_by_user_id' => $current_user_id,
                    'changed_by_role' => $user_role
                ),
                array('id' => $existing),
                array('%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );

            // Registrar na auditoria
            if ($result !== false && class_exists('SISTUR_Audit')) {
                $audit = SISTUR_Audit::get_instance();
                $audit->log(
                    'update',
                    'time_days',
                    $existing,
                    array(
                        'old_values' => $old_data,
                        'new_values' => array('status' => $status, 'notes' => $notes),
                        'reason' => $formatted_reason,
                        'user_role' => $user_role
                    )
                );
            }
        } else {
            // Inserir
            $result = $wpdb->insert(
                $table,
                array(
                    'employee_id' => $employee_id,
                    'shift_date' => $shift_date,
                    'status' => $status,
                    'notes' => $notes,
                    'admin_change_reason' => $formatted_reason,
                    'changed_by_user_id' => $current_user_id,
                    'changed_by_role' => $user_role
                ),
                array('%d', '%s', '%s', '%s', '%s', '%d', '%s')
            );

            // Registrar na auditoria
            if ($result !== false && class_exists('SISTUR_Audit')) {
                $audit = SISTUR_Audit::get_instance();
                $audit->log(
                    'create',
                    'time_days',
                    $wpdb->insert_id,
                    array(
                        'new_values' => array('employee_id' => $employee_id, 'shift_date' => $shift_date, 'status' => $status, 'notes' => $notes),
                        'reason' => $formatted_reason,
                        'user_role' => $user_role
                    )
                );
            }
        }

        if ($result === false) {
            wp_send_json_error(array('message' => 'Erro ao salvar status.'));
        }

        wp_send_json_success(array('message' => 'Status salvo com sucesso!'));
    }

    /**
     * AJAX: Obter detalhes do dia
     */
    public function ajax_get_day_detail() {
        check_ajax_referer('sistur_time_tracking_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
        $shift_date = isset($_GET['shift_date']) ? sanitize_text_field($_GET['shift_date']) : '';

        global $wpdb;
        $entries_table = $wpdb->prefix . 'sistur_time_entries';

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $entries_table WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
            $employee_id, $shift_date
        ), ARRAY_A);

        // Adicionar tradução dos tipos de batida
        foreach ($entries as &$entry) {
            $entry['punch_type_label'] = self::translate_punch_type($entry['punch_type']);
        }

        wp_send_json_success(array('entries' => $entries));
    }

    /**
     * AJAX: Registro de ponto público
     */
    public function ajax_public_clock() {
        error_log('========== SISTUR DEBUG: ajax_public_clock INICIADO ==========');
        error_log('SISTUR DEBUG: Timestamp: ' . current_time('Y-m-d H:i:s'));
        error_log('SISTUR DEBUG: POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('sistur_time_public_nonce', 'nonce');
        error_log('SISTUR DEBUG: ✓ Nonce validado');

        // Verificar se o funcionário está logado
        $employee = sistur_get_current_employee();
        error_log('SISTUR DEBUG: Funcionário retornado: ' . print_r($employee, true));
        
        if (!$employee || $employee['id'] <= 0) {
            error_log('SISTUR DEBUG ERRO: Funcionário não está logado ou ID inválido');
            wp_send_json_error(array('message' => 'Você precisa estar logado.'));
        }
        
        error_log('SISTUR DEBUG: ✓ Funcionário logado - ID: ' . $employee['id'] . ', Nome: ' . $employee['name']);

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';
        error_log('SISTUR DEBUG: Tabela de ponto: ' . $table);
        
        // Verificar se tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            error_log('SISTUR DEBUG ERRO CRÍTICO: Tabela não existe: ' . $table);
            wp_send_json_error(array('message' => 'Erro: Tabela de registros de ponto não existe. Entre em contato com o administrador.'));
        }
        error_log('SISTUR DEBUG: ✓ Tabela existe');
        
        $today = current_time('Y-m-d');
        error_log('SISTUR DEBUG: Data de hoje: ' . $today);

        // Buscar registros do dia para determinar o próximo tipo
        $today_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
            $employee['id'],
            $today
        ), ARRAY_A);
        
        if ($wpdb->last_error) {
            error_log('SISTUR DEBUG ERRO SQL: ' . $wpdb->last_error);
            error_log('SISTUR DEBUG: Query executada: SELECT * FROM ' . $table . ' WHERE employee_id = ' . $employee['id'] . ' AND shift_date = ' . $today);
        }
        
        error_log('SISTUR DEBUG: Registros de hoje encontrados: ' . count($today_entries));
        if (!empty($today_entries)) {
            error_log('SISTUR DEBUG: Detalhes dos registros: ' . print_r($today_entries, true));
        }

        // Determinar próximo tipo de registro automaticamente (TIPOS PADRONIZADOS)
        $punch_count = count($today_entries);
        $punch_types = array('clock_in', 'lunch_start', 'lunch_end', 'clock_out');
        error_log('SISTUR DEBUG: Total de batidas hoje: ' . $punch_count);

        if ($punch_count < count($punch_types)) {
            $punch_type = $punch_types[$punch_count];
        } else {
            // Se já completou as 4 batidas, permite registros extras
            $punch_type = 'extra';
        }
        
        error_log('SISTUR DEBUG: Tipo de ponto determinado: ' . $punch_type . ' (' . self::translate_punch_type($punch_type) . ')');

        $data = array(
            'employee_id' => $employee['id'],
            'punch_type' => $punch_type,
            'punch_time' => current_time('mysql'),
            'shift_date' => $today,
            'source' => 'employee'
        );
        
        error_log('SISTUR DEBUG: Dados a inserir: ' . print_r($data, true));
        error_log('SISTUR DEBUG: Executando INSERT...');

        $result = $wpdb->insert($table, $data, array('%d', '%s', '%s', '%s', '%s'));
        
        error_log('SISTUR DEBUG: Resultado do INSERT: ' . ($result === false ? 'FALSE (erro)' : $result));
        error_log('SISTUR DEBUG: Insert ID: ' . $wpdb->insert_id);
        
        if ($wpdb->last_error) {
            error_log('SISTUR DEBUG ERRO SQL: ' . $wpdb->last_error);
            error_log('SISTUR DEBUG: wpdb->last_query: ' . $wpdb->last_query);
        }

        if ($result === false) {
            error_log('SISTUR DEBUG ERRO: INSERT falhou!');
            error_log('SISTUR DEBUG: Último erro SQL: ' . $wpdb->last_error);
            wp_send_json_error(array(
                'message' => 'Erro ao registrar ponto: ' . $wpdb->last_error,
                'debug' => defined('WP_DEBUG') && WP_DEBUG ? $wpdb->last_error : ''
            ));
        }
        
        error_log('SISTUR DEBUG: ✓ Ponto registrado com sucesso! ID: ' . $wpdb->insert_id);

        error_log('========== SISTUR DEBUG: ajax_public_clock FINALIZADO COM SUCESSO ==========');
        
        wp_send_json_success(array(
            'message' => 'Ponto registrado com sucesso!',
            'time' => current_time('H:i:s'),
            'punch_type' => self::translate_punch_type($punch_type)
        ));
    }

    /**
     * AJAX: Status de ponto público
     */
    public function ajax_public_status() {
        check_ajax_referer('sistur_time_public_nonce', 'nonce');

        $employee = sistur_get_current_employee();
        if (!$employee || $employee['id'] <= 0) {
            wp_send_json_error(array('message' => 'Você precisa estar logado.'));
        }

        // Se for admin e especificou um employee_id, usar esse ID
        $employee_id = $employee['id'];
        if (isset($employee['is_admin']) && $employee['is_admin'] && isset($_GET['employee_id'])) {
            $employee_id = intval($_GET['employee_id']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sistur_time_entries';

        $today = current_time('Y-m-d');

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
            $employee_id, $today
        ), ARRAY_A);

        // Adicionar tradução dos tipos de batida
        foreach ($entries as &$entry) {
            $entry['punch_type_label'] = self::translate_punch_type($entry['punch_type']);
        }

        wp_send_json_success(array('entries' => $entries));
    }

    /**
     * Renderiza a página admin
     */
    public static function render_admin_page() {
        include SISTUR_PLUGIN_DIR . 'admin/views/time-tracking/admin-page.php';
    }

    /**
     * Shortcode: Folha de ponto
     */
    public static function shortcode_folha_ponto($atts) {
        ob_start();
        ?>
        <div class="sistur-folha-ponto">
            <h2><?php _e('Folha de Ponto', 'sistur'); ?></h2>
            <p><?php _e('Visualize sua folha de ponto mensal.', 'sistur'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
}
