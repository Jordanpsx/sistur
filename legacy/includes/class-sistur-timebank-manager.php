<?php
/**
 * Gerenciador de Abatimento de Banco de Horas
 *
 * Responsável por:
 * - Abatimento de banco de horas por folga (compensação)
 * - Abatimento de banco de horas por pagamento (dinheiro)
 * - Cálculo de valores considerando tipo de dia (útil/fim de semana/feriado)
 * - Sistema de aprovação de abatimentos
 * - Gestão de feriados e adicionais
 * - Configuração de pagamento por funcionário
 *
 * @since 1.5.0
 * @package SISTUR
 */

if (!defined('ABSPATH')) {
    exit;
}

class SISTUR_Timebank_Manager {

    private static $instance = null;
    private $permissions;
    private $current_employee_id;

    private $table_deductions;
    private $table_holidays;
    private $table_payment_config;
    private $table_employees;
    private $table_time_days;
    private $table_payments;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        global $wpdb;

        $this->permissions = SISTUR_Permissions::get_instance();
        $this->table_deductions = $wpdb->prefix . 'sistur_timebank_deductions';
        $this->table_holidays = $wpdb->prefix . 'sistur_holidays';
        $this->table_payment_config = $wpdb->prefix . 'sistur_employee_payment_config';
        $this->table_employees = $wpdb->prefix . 'sistur_employees';
        $this->table_time_days = $wpdb->prefix . 'sistur_time_days';
        $this->table_payments = $wpdb->prefix . 'sistur_payment_records';

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $this->current_employee_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_employees} WHERE user_id = %d",
                $user_id
            ));
        }

        if (!$this->current_employee_id && function_exists('sistur_get_current_employee')) {
            $employee = sistur_get_current_employee();
            if ($employee && isset($employee['id'])) {
                $this->current_employee_id = intval($employee['id']);
            }
        }

        $this->init_hooks();
    }

    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa hooks do WordPress
     */
    private function init_hooks() {
        // AJAX Handlers
        add_action('wp_ajax_sistur_get_employee_timebank_balance', array($this, 'ajax_get_balance'));
        add_action('wp_ajax_sistur_deduct_timebank_folga', array($this, 'ajax_deduct_folga'));
        add_action('wp_ajax_sistur_deduct_timebank_payment', array($this, 'ajax_deduct_payment'));
        add_action('wp_ajax_sistur_approve_deduction', array($this, 'ajax_approve_deduction'));
        add_action('wp_ajax_sistur_calculate_payment_preview', array($this, 'ajax_calculate_payment_preview'));
        add_action('wp_ajax_sistur_get_pending_deductions', array($this, 'ajax_get_pending_deductions'));
        add_action('wp_ajax_sistur_get_all_deductions', array($this, 'ajax_get_all_deductions'));

        // Feriados
        add_action('wp_ajax_sistur_save_holiday', array($this, 'ajax_save_holiday'));
        add_action('wp_ajax_sistur_delete_holiday', array($this, 'ajax_delete_holiday'));
        add_action('wp_ajax_sistur_get_holidays', array($this, 'ajax_get_holidays'));

        // Configuração de pagamento
        add_action('wp_ajax_sistur_get_payment_config', array($this, 'ajax_get_payment_config'));
        add_action('wp_ajax_sistur_save_payment_config', array($this, 'ajax_save_payment_config'));
        add_action('wp_ajax_sistur_timebank_forgive_absence', array($this, 'ajax_forgive_absence'));

        // Scripts e estilos
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Ativação do plugin (criar tabelas)
        register_activation_hook(__FILE__, array($this, 'create_tables'));
    }

    /**
     * Enfileira scripts e estilos
     */
    public function enqueue_scripts($hook) {
        // Apenas nas páginas relevantes
        $allowed_pages = array(
            'toplevel_page_sistur',
            'sistur_page_sistur-employees',
            'sistur_page_sistur-time-tracking',
        );

        if (!in_array($hook, $allowed_pages)) {
            return;
        }

        wp_enqueue_script(
            'sistur-timebank-manager',
            SISTUR_PLUGIN_URL . 'assets/js/timebank-manager.js',
            array('jquery'),
            SISTUR_VERSION,
            true
        );

        wp_localize_script('sistur-timebank-manager', 'sisturTimebankNonce', array(
            'nonce' => wp_create_nonce('sistur_timebank_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }

    /**
     * Cria tabelas necessárias no banco de dados
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tabela de feriados
        $sql_holidays = "CREATE TABLE IF NOT EXISTS {$this->table_holidays} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_date DATE NOT NULL,
            description VARCHAR(255) NOT NULL,
            holiday_type ENUM('nacional', 'estadual', 'municipal', 'ponto_facultativo') DEFAULT 'nacional',
            multiplicador_adicional DECIMAL(4,2) DEFAULT 1.00,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INT,
            UNIQUE KEY unique_holiday_date (holiday_date),
            INDEX idx_holiday_date (holiday_date),
            INDEX idx_status (status)
        ) $charset_collate;";

        // Tabela de abatimentos
        $sql_deductions = "CREATE TABLE IF NOT EXISTS {$this->table_deductions} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            deduction_type ENUM('folga', 'pagamento') NOT NULL,
            minutes_deducted INT NOT NULL,
            balance_before_minutes INT NOT NULL,
            balance_after_minutes INT NOT NULL,
            time_off_start_date DATE NULL,
            time_off_end_date DATE NULL,
            time_off_description TEXT NULL,
            payment_amount DECIMAL(10,2) NULL,
            payment_record_id INT NULL,
            calculation_details JSON NULL,
            approval_status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            approval_notes TEXT NULL,
            is_partial BOOLEAN DEFAULT FALSE,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_id (employee_id),
            INDEX idx_deduction_type (deduction_type),
            INDEX idx_approval_status (approval_status),
            INDEX idx_time_off_dates (time_off_start_date, time_off_end_date),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";

        // Tabela de configuração de pagamento
        $sql_payment_config = "CREATE TABLE IF NOT EXISTS {$this->table_payment_config} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            salario_base DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            valor_hora_base DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            multiplicador_dia_util DECIMAL(4,2) DEFAULT 1.50,
            multiplicador_fim_semana DECIMAL(4,2) DEFAULT 2.00,
            multiplicador_feriado DECIMAL(4,2) DEFAULT 2.50,
            calculation_method ENUM('automatic', 'manual') DEFAULT 'automatic',
            last_calculated_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_employee (employee_id)
        ) $charset_collate;";

        dbDelta($sql_holidays);
        dbDelta($sql_deductions);
        dbDelta($sql_payment_config);

        // Adicionar colunas em wp_sistur_payment_records se não existirem
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_payments} LIKE 'timebank_deduction_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_payments} ADD COLUMN timebank_deduction_id INT NULL AFTER notes");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_payments} LIKE 'is_timebank_payment'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$this->table_payments} ADD COLUMN is_timebank_payment BOOLEAN DEFAULT FALSE AFTER timebank_deduction_id");
        }

        // Inserir permissões
        $this->insert_permissions();

        // Inserir feriados padrão
        $this->insert_default_holidays();
    }

    /**
     * Insere permissões no sistema
     */
    private function insert_permissions() {
        global $wpdb;
        $table_permissions = $wpdb->prefix . 'sistur_permissions';

        $permissions = array(
            array('name' => 'dar_folga', 'module' => 'time_tracking', 'description' => 'Permitir registrar abatimento de banco de horas como folga/compensação'),
            array('name' => 'pagar_horas_extra', 'module' => 'payments', 'description' => 'Permitir pagar horas extras do banco de horas em dinheiro'),
            array('name' => 'aprovar_abatimento_banco', 'module' => 'time_tracking', 'description' => 'Aprovar ou rejeitar abatimentos de banco de horas'),
            array('name' => 'gerenciar_feriados', 'module' => 'settings', 'description' => 'Gerenciar cadastro de feriados e adicionais'),
            array('module' => 'timebank', 'action' => 'manage', 'name' => 'gerenciar_banco_horas', 'description' => 'Gerenciar todas as operações do banco de horas')
        );

        foreach ($permissions as $perm) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_permissions} WHERE name = %s",
                $perm['name']
            ));

            if ($exists == 0) {
                $wpdb->insert($table_permissions, $perm);
            }
        }
    }

    /**
     * Insere feriados nacionais padrão (2025)
     */
    private function insert_default_holidays() {
        global $wpdb;

        $holidays = array(
            array('2025-01-01', 'Confraternização Universal', 'nacional', 2.00),
            array('2025-02-13', 'Carnaval', 'ponto_facultativo', 1.50),
            array('2025-04-18', 'Sexta-feira Santa', 'nacional', 2.00),
            array('2025-04-21', 'Tiradentes', 'nacional', 2.00),
            array('2025-05-01', 'Dia do Trabalho', 'nacional', 2.00),
            array('2025-06-19', 'Corpus Christi', 'ponto_facultativo', 1.50),
            array('2025-09-07', 'Independência do Brasil', 'nacional', 2.00),
            array('2025-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
            array('2025-11-02', 'Finados', 'nacional', 2.00),
            array('2025-11-15', 'Proclamação da República', 'nacional', 2.00),
            array('2025-11-20', 'Consciência Negra', 'nacional', 2.00),
            array('2025-12-25', 'Natal', 'nacional', 2.00)
        );

        foreach ($holidays as $holiday) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_holidays} WHERE holiday_date = %s",
                $holiday[0]
            ));

            if ($exists == 0) {
                $wpdb->insert($this->table_holidays, array(
                    'holiday_date' => $holiday[0],
                    'description' => $holiday[1],
                    'holiday_type' => $holiday[2],
                    'multiplicador_adicional' => $holiday[3],
                    'created_by' => get_current_user_id()
                ));
            }
        }
    }

    // ==================== MÉTODOS PRINCIPAIS ====================

    /**
     * Obtém saldo atual do banco de horas do funcionário
     *
     * @param int $employee_id ID do funcionário
     * @return array ['total_minutos' => int, 'total_horas' => float, 'formatted' => string]
     */
    public function get_employee_balance($employee_id) {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare("
            SELECT SUM(saldo_final_minutos) as total_minutos
            FROM {$this->table_time_days}
            WHERE employee_id = %d
        ", $employee_id));

        $total_minutos = (int) ($result->total_minutos ?? 0);
        $total_horas = $total_minutos / 60;

        $horas = floor(abs($total_minutos) / 60);
        $minutos = abs($total_minutos) % 60;
        $sinal = $total_minutos >= 0 ? '+' : '-';
        $formatted = sprintf('%s%dh %02dmin', $sinal, $horas, $minutos);

        return array(
            'total_minutos' => $total_minutos,
            'total_horas' => round($total_horas, 2),
            'formatted' => $formatted
        );
    }

    private function has_timebank_manage_permission($employee_id = null) {
        if (current_user_can('manage_options')) {
            return true;
        }

        $target_employee = $employee_id ?: $this->current_employee_id;
        if (!$target_employee) {
            return false;
        }

        return $this->permissions->can($target_employee, 'timebank', 'manage');
    }

    /**
     * Valida se o funcionário tem saldo suficiente
     */
    public function validate_balance($employee_id, $minutes_to_deduct) {
        $balance = $this->get_employee_balance($employee_id);

        if ($balance['total_minutos'] < $minutes_to_deduct) {
            return array(
                'valid' => false,
                'message' => 'Saldo insuficiente no banco de horas. Saldo atual: ' . $balance['formatted'],
                'balance' => $balance['total_minutos'],
                'requested' => $minutes_to_deduct
            );
        }

        return array('valid' => true);
    }

    /**
     * Registra abatimento por folga (compensação)
     *
     * @param array $data Dados do abatimento
     * @return array ['success' => bool, 'deduction_id' => int, 'message' => string]
     */
    public function deduct_timebank_folga($data) {
        global $wpdb;

        $required = ['employee_id', 'minutes_deducted', 'time_off_start_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return array('success' => false, 'message' => "Campo obrigatório: {$field}");
            }
        }

        $validation = $this->validate_balance($data['employee_id'], $data['minutes_deducted']);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => $validation['message']);
        }

        $balance = $this->get_employee_balance($data['employee_id']);

        $insert_data = array(
            'employee_id' => (int) $data['employee_id'],
            'deduction_type' => 'folga',
            'minutes_deducted' => (int) $data['minutes_deducted'],
            'balance_before_minutes' => $balance['total_minutos'],
            'balance_after_minutes' => $balance['total_minutos'] - (int) $data['minutes_deducted'],
            'time_off_start_date' => sanitize_text_field($data['time_off_start_date']),
            'time_off_end_date' => !empty($data['time_off_end_date']) ? sanitize_text_field($data['time_off_end_date']) : sanitize_text_field($data['time_off_start_date']),
            'time_off_description' => sanitize_textarea_field($data['time_off_description'] ?? ''),
            'is_partial' => !empty($data['is_partial']),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'approval_status' => 'pendente',
            'created_by' => get_current_user_id()
        );

        $result = $wpdb->insert($this->table_deductions, $insert_data);

        if ($result === false) {
            return array('success' => false, 'message' => 'Erro ao inserir registro: ' . $wpdb->last_error);
        }

        $deduction_id = $wpdb->insert_id;

        $this->log_audit('timebank_deduction_folga_created', $deduction_id, $data['employee_id']);

        return array(
            'success' => true,
            'deduction_id' => $deduction_id,
            'message' => 'Abatimento por folga registrado com sucesso. Aguardando aprovação.'
        );
    }

    /**
     * Calcula valor a pagar baseado em minutos do banco de horas
     *
     * @param int $employee_id ID do funcionário
     * @param int $minutes_to_pay Minutos a pagar
     * @param array $date_range ['start' => 'Y-m-d', 'end' => 'Y-m-d'] (opcional)
     * @return array Cálculo detalhado
     */
    public function calculate_payment_amount($employee_id, $minutes_to_pay, $date_range = null) {
        global $wpdb;

        $config = $this->get_payment_config($employee_id);

        if (!$config || $config['valor_hora_base'] <= 0) {
            return array(
                'success' => false,
                'message' => 'Configuração de pagamento não encontrada para este funcionário. Por favor, configure os valores de pagamento primeiro.'
            );
        }

        if (!$date_range) {
            $date_range = array(
                'start' => date('Y-m-d', strtotime('-30 days')),
                'end' => date('Y-m-d')
            );
        }

        $days = $wpdb->get_results($wpdb->prepare("
            SELECT shift_date, saldo_final_minutos, status
            FROM {$this->table_time_days}
            WHERE employee_id = %d
            AND shift_date BETWEEN %s AND %s
            AND saldo_final_minutos > 0
            ORDER BY shift_date DESC
        ", $employee_id, $date_range['start'], $date_range['end']));

        $holidays = $wpdb->get_results($wpdb->prepare("
            SELECT holiday_date, multiplicador_adicional
            FROM {$this->table_holidays}
            WHERE holiday_date BETWEEN %s AND %s
            AND status = 'active'
        ", $date_range['start'], $date_range['end']), OBJECT_K);

        $breakdown = array(
            'horas_dia_util' => array('minutos' => 0, 'detalhes' => array()),
            'horas_fim_semana' => array('minutos' => 0, 'detalhes' => array()),
            'horas_feriado' => array('minutos' => 0, 'detalhes' => array())
        );

        $minutes_remaining = $minutes_to_pay;

        foreach ($days as $day) {
            if ($minutes_remaining <= 0) break;

            $date = $day->shift_date;
            $available_minutes = min($day->saldo_final_minutos, $minutes_remaining);

            $day_of_week = date('N', strtotime($date));
            $is_weekend = ($day_of_week >= 6);
            $is_holiday = isset($holidays[$date]);

            if ($is_holiday) {
                $breakdown['horas_feriado']['minutos'] += $available_minutes;
                $breakdown['horas_feriado']['detalhes'][] = array(
                    'data' => $date,
                    'minutos' => $available_minutes,
                    'tipo' => 'feriado',
                    'multiplicador' => $holidays[$date]->multiplicador_adicional
                );
            } elseif ($is_weekend) {
                $breakdown['horas_fim_semana']['minutos'] += $available_minutes;
                $breakdown['horas_fim_semana']['detalhes'][] = array(
                    'data' => $date,
                    'minutos' => $available_minutes,
                    'tipo' => $day_of_week == 6 ? 'sabado' : 'domingo'
                );
            } else {
                $breakdown['horas_dia_util']['minutos'] += $available_minutes;
                $breakdown['horas_dia_util']['detalhes'][] = array(
                    'data' => $date,
                    'minutos' => $available_minutes,
                    'tipo' => 'dia_util'
                );
            }

            $minutes_remaining -= $available_minutes;
        }

        $valor_hora_base = $config['valor_hora_base'];
        $total_valor = 0;

        if ($breakdown['horas_dia_util']['minutos'] > 0) {
            $horas = $breakdown['horas_dia_util']['minutos'] / 60;
            $valor = $horas * $valor_hora_base * $config['multiplicador_dia_util'];
            $breakdown['horas_dia_util']['multiplicador'] = $config['multiplicador_dia_util'];
            $breakdown['horas_dia_util']['valor_hora_base'] = $valor_hora_base;
            $breakdown['horas_dia_util']['valor_total'] = round($valor, 2);
            $total_valor += $valor;
        }

        if ($breakdown['horas_fim_semana']['minutos'] > 0) {
            $horas = $breakdown['horas_fim_semana']['minutos'] / 60;
            $valor = $horas * $valor_hora_base * $config['multiplicador_fim_semana'];
            $breakdown['horas_fim_semana']['multiplicador'] = $config['multiplicador_fim_semana'];
            $breakdown['horas_fim_semana']['valor_hora_base'] = $valor_hora_base;
            $breakdown['horas_fim_semana']['valor_total'] = round($valor, 2);
            $total_valor += $valor;
        }

        if ($breakdown['horas_feriado']['minutos'] > 0) {
            $horas = $breakdown['horas_feriado']['minutos'] / 60;
            $mult_feriado = $config['multiplicador_feriado'];
            $valor = $horas * $valor_hora_base * $mult_feriado;
            $breakdown['horas_feriado']['multiplicador'] = $mult_feriado;
            $breakdown['horas_feriado']['valor_hora_base'] = $valor_hora_base;
            $breakdown['horas_feriado']['valor_total'] = round($valor, 2);
            $total_valor += $valor;
        }

        return array(
            'success' => true,
            'breakdown' => $breakdown,
            'resumo' => array(
                'total_minutos' => $minutes_to_pay - $minutes_remaining,
                'total_horas' => round(($minutes_to_pay - $minutes_remaining) / 60, 2),
                'valor_total' => round($total_valor, 2),
                'minutos_nao_pagos' => $minutes_remaining
            )
        );
    }

    /**
     * Registra abatimento por pagamento (dinheiro)
     */
    public function forgive_absence($data) {
        global $wpdb;

        $required = ['employee_id', 'dates'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return array('success' => false, 'message' => "Campo obrigatório: {$field}");
            }
        }

        $dates = array_filter(array_map('sanitize_text_field', (array)$data['dates']));

        if (empty($dates)) {
            return array('success' => false, 'message' => 'Informe ao menos uma data válida.');
        }

        $normalized_dates = array();
        foreach ($dates as $date) {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                continue;
            }
            $normalized_dates[] = date('Y-m-d', $timestamp);
        }

        if (empty($normalized_dates)) {
            return array('success' => false, 'message' => 'As datas informadas são inválidas.');
        }

        $placeholders = implode(',', array_fill(0, count($normalized_dates), '%s'));
        $params = array_merge(array((int)$data['employee_id']), $normalized_dates);
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_time_days} WHERE employee_id = %d AND shift_date IN ($placeholders)",
            $params
        );

        $days = $wpdb->get_results($query);

        $wpdb->query('START TRANSACTION');

        try {
            foreach ($days as $day) {
                $wpdb->update(
                    $this->table_time_days,
                    array(
                        'status' => 'bank_used',
                        'needs_review' => 0,
                        'admin_change_reason' => sanitize_textarea_field($data['notes'] ?? 'Perdão de falta via gestor de banco de horas'),
                        'changed_by_user_id' => get_current_user_id(),
                        'changed_by_role' => 'timebank_manager',
                        'bank_minutes_adjustment' => 0,
                        'saldo_calculado_minutos' => 0,
                        'saldo_final_minutos' => 0
                    ),
                    array('id' => $day->id)
                );
            }

            $wpdb->query('COMMIT');

            return array('success' => true, 'message' => 'Faltas perdoadas com sucesso.');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => 'Erro ao perdoar faltas: ' . $e->getMessage());
        }
    }

    // ... (rest of the class remains the same)

    /**
     * Cria configuração padrão de pagamento
     */
    private function create_default_payment_config($employee_id) {
        global $wpdb;

        $employee = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->table_employees} WHERE id = %d
        ", $employee_id));

        if (!$employee) {
            return null;
        }

        $salario_base = 0;
        $valor_hora_base = 0;

        $default_config = array(
            'employee_id' => $employee_id,
            'salario_base' => $salario_base,
            'valor_hora_base' => $valor_hora_base,
            'multiplicador_dia_util' => 1.50,
            'multiplicador_fim_semana' => 2.00,
            'multiplicador_feriado' => 2.50,
            'calculation_method' => 'automatic'
        );

        $wpdb->insert($this->table_payment_config, $default_config);

        return $default_config;
    }

    /**
     * Registra log de auditoria
     */
    private function log_audit($action, $deduction_id, $employee_id) {
        global $wpdb;

        $table_audit = $wpdb->prefix . 'sistur_audit_logs';
        $wpdb->insert($table_audit, array(
            'user_id' => get_current_user_id(),
            'action' => $action,
            'module' => 'timebank',
            'record_id' => $deduction_id,
            'details' => json_encode(array(
                'employee_id' => $employee_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
            ))
        ));
    }

    // ==================== AJAX HANDLERS ====================

    public function ajax_get_balance() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);

        if (!$employee_id) {
            wp_send_json_error(array('message' => 'ID do funcionário não informado'));
        }

        $balance = $this->get_employee_balance($employee_id);
        $config = $this->get_payment_config($employee_id);

        wp_send_json_success(array(
            'balance' => $balance,
            'payment_config' => $config
        ));
    }

    public function ajax_deduct_folga() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);

        if (!$this->has_timebank_manage_permission($employee_id)) {
            wp_send_json_error(array('message' => 'Você não tem permissão para dar folga'));
        }

        $result = $this->deduct_timebank_folga($_POST);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_deduct_payment() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);

        if (!$this->has_timebank_manage_permission($employee_id)) {
            wp_send_json_error(array('message' => 'Você não tem permissão para pagar horas extras'));
        }

        $result = $this->deduct_timebank_payment($_POST);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_approve_deduction() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            if (!$this->has_timebank_manage_permission()) {
                wp_send_json_error(array('message' => 'Sem permissão para aprovar'));
            }
        }

        $deduction_id = intval($_POST['deduction_id']);
        $status = sanitize_text_field($_POST['status']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $result = $this->approve_deduction($deduction_id, $status, $notes);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_calculate_payment_preview() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);
        $minutes_to_pay = intval($_POST['minutes_to_pay']);

        $date_range = null;
        if (!empty($_POST['start_date']) && !empty($_POST['end_date'])) {
            $date_range = array(
                'start' => sanitize_text_field($_POST['start_date']),
                'end' => sanitize_text_field($_POST['end_date'])
            );
        }

        $calculation = $this->calculate_payment_amount($employee_id, $minutes_to_pay, $date_range);

        if ($calculation['success']) {
            wp_send_json_success($calculation);
        } else {
            wp_send_json_error($calculation);
        }
    }

    public function ajax_get_pending_deductions() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!$this->has_timebank_manage_permission()) {
            wp_send_json_error(array('message' => 'Você não tem permissão para visualizar abatimentos pendentes.'));
        }

        global $wpdb;

        $deductions = $wpdb->get_results("
            SELECT d.*, e.name as employee_name
            FROM {$this->table_deductions} d
            LEFT JOIN {$this->table_employees} e ON d.employee_id = e.id
            WHERE d.approval_status = 'pendente'
            ORDER BY d.created_at DESC
        ");

        wp_send_json_success(array('deductions' => $deductions));
    }

    public function ajax_get_all_deductions() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!$this->has_timebank_manage_permission()) {
            wp_send_json_error(array('message' => 'Você não tem permissão para visualizar abatimentos.'));
        }

        global $wpdb;

        $status = !empty($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $type = !empty($_POST['type']) ? sanitize_text_field($_POST['type']) : '';

        $where = array('1=1');
        if ($status) {
            $where[] = $wpdb->prepare("d.approval_status = %s", $status);
        }
        if ($type) {
            $where[] = $wpdb->prepare("d.deduction_type = %s", $type);
        }

        $where_clause = implode(' AND ', $where);

        $deductions = $wpdb->get_results("
            SELECT
                d.*,
                e.name as employee_name,
                u.display_name as created_by_name
            FROM {$this->table_deductions} d
            LEFT JOIN {$this->table_employees} e ON d.employee_id = e.id
            LEFT JOIN {$wpdb->users} u ON d.created_by = u.ID
            WHERE {$where_clause}
            ORDER BY d.created_at DESC
        ");

        // Calcular estatísticas
        $stats = array(
            'pendentes' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_deductions} WHERE approval_status = 'pendente'"),
            'aprovados' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_deductions} WHERE approval_status = 'aprovado' AND MONTH(created_at) = MONTH(NOW())"),
            'rejeitados' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_deductions} WHERE approval_status = 'rejeitado' AND MONTH(created_at) = MONTH(NOW())"),
            'total_pago' => $wpdb->get_var("SELECT SUM(payment_amount) FROM {$this->table_deductions} WHERE deduction_type = 'pagamento' AND approval_status = 'aprovado' AND MONTH(created_at) = MONTH(NOW())") ?? 0
        );

        wp_send_json_success(array(
            'deductions' => $deductions,
            'stats' => $stats
        ));
    }

    public function ajax_save_holiday() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;

        $holiday_id = !empty($_POST['holiday_id']) ? intval($_POST['holiday_id']) : null;

        $data = array(
            'holiday_date' => sanitize_text_field($_POST['holiday_date']),
            'description' => sanitize_text_field($_POST['description']),
            'holiday_type' => sanitize_text_field($_POST['holiday_type']),
            'multiplicador_adicional' => floatval($_POST['multiplicador_adicional']),
            'status' => sanitize_text_field($_POST['status'] ?? 'active')
        );

        if ($holiday_id) {
            $wpdb->update($this->table_holidays, $data, array('id' => $holiday_id));
            $message = 'Feriado atualizado com sucesso';
        } else {
            $data['created_by'] = get_current_user_id();
            $wpdb->insert($this->table_holidays, $data);
            $holiday_id = $wpdb->insert_id;
            $message = 'Feriado cadastrado com sucesso';
        }

        wp_send_json_success(array(
            'message' => $message,
            'holiday_id' => $holiday_id
        ));
    }

    public function ajax_delete_holiday() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;

        $holiday_id = intval($_POST['holiday_id']);

        $wpdb->delete($this->table_holidays, array('id' => $holiday_id));

        wp_send_json_success(array('message' => 'Feriado excluído com sucesso'));
    }

    public function ajax_get_holidays() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        global $wpdb;

        $holidays = $wpdb->get_results("
            SELECT * FROM {$this->table_holidays}
            ORDER BY holiday_date DESC
        ");

        wp_send_json_success(array('holidays' => $holidays));
    }

    public function ajax_get_payment_config() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);
        $config = $this->get_payment_config($employee_id);

        wp_send_json_success(array('config' => $config));
    }

    public function ajax_save_payment_config() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sem permissão'));
        }

        global $wpdb;

        $employee_id = intval($_POST['employee_id']);

        $data = array(
            'salario_base' => floatval($_POST['salario_base']),
            'valor_hora_base' => floatval($_POST['valor_hora_base']),
            'multiplicador_dia_util' => floatval($_POST['multiplicador_dia_util']),
            'multiplicador_fim_semana' => floatval($_POST['multiplicador_fim_semana']),
            'multiplicador_feriado' => floatval($_POST['multiplicador_feriado']),
            'calculation_method' => sanitize_text_field($_POST['calculation_method'])
        );

        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$this->table_payment_config} WHERE employee_id = %d
        ", $employee_id));

        if ($exists) {
            $wpdb->update($this->table_payment_config, $data, array('employee_id' => $employee_id));
        } else {
            $data['employee_id'] = $employee_id;
            $wpdb->insert($this->table_payment_config, $data);
        }

        wp_send_json_success(array('message' => 'Configuração salva com sucesso'));
    }

    /**
     * AJAX: Perdoar faltas de funcionário
     */
    public function ajax_forgive_absence() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            if (!$this->has_timebank_manage_permission()) {
                wp_send_json_error(array('message' => 'Você não tem permissão para perdoar faltas'));
            }
        }

        $result = $this->forgive_absence($_POST);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

// Inicializar
SISTUR_Timebank_Manager::get_instance();
