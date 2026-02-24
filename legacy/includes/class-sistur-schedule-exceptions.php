<?php
/**
 * Classe de gerenciamento de exceções de escalas
 *
 * Gerencia feriados, afastamentos, trocas de folgas e eventos especiais
 * que afetam o cálculo de horas esperadas e banco de horas.
 *
 * @package SISTUR
 * @since 2.0.0
 */

class SISTUR_Schedule_Exceptions {

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
     * Inicializa os hooks do WordPress
     */
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_sistur_get_exceptions', array($this, 'ajax_get_exceptions'));
        add_action('wp_ajax_sistur_save_exception', array($this, 'ajax_save_exception'));
        add_action('wp_ajax_sistur_delete_exception', array($this, 'ajax_delete_exception'));
        add_action('wp_ajax_sistur_approve_exception', array($this, 'ajax_approve_exception'));
        add_action('wp_ajax_sistur_get_employee_exceptions', array($this, 'ajax_get_employee_exceptions'));
        add_action('wp_ajax_sistur_justify_absence', array($this, 'ajax_justify_absence'));
        add_action('wp_ajax_sistur_unjustify_absence', array($this, 'ajax_unjustify_absence'));
    }

    /**
     * Verifica se existe exceção para um funcionário em uma data
     *
     * @param int $employee_id ID do funcionário
     * @param string $date Data no formato Y-m-d
     * @return object|null Exceção encontrada ou null
     */
    public function get_exception_for_date($employee_id, $date) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $exception = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
             WHERE employee_id = %d
             AND date = %s
             AND status = 'approved'
             ORDER BY created_at DESC
             LIMIT 1",
            $employee_id,
            $date
        ));

        return $exception;
    }

    /**
     * Retorna todas as exceções de um funcionário
     *
     * @param int $employee_id ID do funcionário
     * @param array $args Argumentos opcionais (start_date, end_date, status, exception_type)
     * @return array Lista de exceções
     */
    public function get_employee_exceptions($employee_id, $args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $defaults = array(
            'start_date' => null,
            'end_date' => null,
            'status' => null,
            'exception_type' => null
        );

        $args = wp_parse_args($args, $defaults);

        $where = array("employee_id = %d");
        $values = array($employee_id);

        if ($args['start_date']) {
            $where[] = "date >= %s";
            $values[] = $args['start_date'];
        }

        if ($args['end_date']) {
            $where[] = "date <= %s";
            $values[] = $args['end_date'];
        }

        if ($args['status']) {
            $where[] = "status = %s";
            $values[] = $args['status'];
        }

        if ($args['exception_type']) {
            $where[] = "exception_type = %s";
            $values[] = $args['exception_type'];
        }

        $where_clause = implode(' AND ', $where);

        $exceptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY date DESC",
            $values
        ));

        return $exceptions;
    }

    /**
     * Cria ou atualiza uma exceção
     *
     * @param array $data Dados da exceção
     * @return int|bool ID da exceção ou false em caso de erro
     */
    public function save_exception($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $defaults = array(
            'employee_id' => 0,
            'exception_type' => 'holiday',
            'date' => current_time('Y-m-d'),
            'custom_expected_minutes' => null,
            'traded_with_employee_id' => null,
            'traded_date' => null,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'notes' => '',
            'is_justified' => 0
        );

        $data = wp_parse_args($data, $defaults);

        // Validações
        if (empty($data['employee_id']) || $data['employee_id'] <= 0) {
            return false;
        }

        if (empty($data['date'])) {
            return false;
        }

        // Se for holiday ou sick_leave, auto-aprovar e zerar expectativa
        if (in_array($data['exception_type'], array('holiday', 'sick_leave'))) {
            $data['status'] = 'approved';
            $data['custom_expected_minutes'] = 0;
            $data['approved_by'] = get_current_user_id();
            $data['approved_at'] = current_time('mysql');
        }

        $exception_data = array(
            'employee_id' => intval($data['employee_id']),
            'exception_type' => $data['exception_type'],
            'date' => $data['date'],
            'custom_expected_minutes' => $data['custom_expected_minutes'],
            'traded_with_employee_id' => $data['traded_with_employee_id'],
            'traded_date' => $data['traded_date'],
            'status' => $data['status'],
            'approved_by' => $data['approved_by'],
            'approved_at' => $data['approved_at'],
            'notes' => $data['notes'],
            'is_justified' => intval($data['is_justified'])
        );

        $format = array('%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d');

        if (isset($data['id']) && $data['id'] > 0) {
            // Atualizar
            $result = $wpdb->update(
                $table,
                $exception_data,
                array('id' => $data['id']),
                $format,
                array('%d')
            );

            return $result !== false ? $data['id'] : false;
        } else {
            // Inserir
            $result = $wpdb->insert($table, $exception_data, $format);

            return $result !== false ? $wpdb->insert_id : false;
        }
    }

    /**
     * Aprova uma exceção
     *
     * @param int $exception_id ID da exceção
     * @return bool Sucesso
     */
    public function approve_exception($exception_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $result = $wpdb->update(
            $table,
            array(
                'status' => 'approved',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql')
            ),
            array('id' => $exception_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Rejeita uma exceção
     *
     * @param int $exception_id ID da exceção
     * @return bool Sucesso
     */
    public function reject_exception($exception_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $result = $wpdb->update(
            $table,
            array(
                'status' => 'rejected',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql')
            ),
            array('id' => $exception_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Deleta uma exceção
     *
     * @param int $exception_id ID da exceção
     * @return bool Sucesso
     */
    public function delete_exception($exception_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        $result = $wpdb->delete(
            $table,
            array('id' => $exception_id),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Justifica uma falta (ausência)
     * Quando uma falta é justificada, o banco de horas "perdoa" a ausência,
     * ou seja, não desconta as horas esperadas do dia.
     *
     * @param int $employee_id ID do funcionário
     * @param string $date Data da falta (formato Y-m-d)
     * @param string $justification Motivo da justificativa
     * @return int|bool ID da exceção criada ou false em caso de erro
     */
    public function justify_absence($employee_id, $date, $justification = '') {
        global $wpdb;

        // Validações
        if (empty($employee_id) || $employee_id <= 0) {
            return false;
        }

        if (empty($date)) {
            return false;
        }

        // Verificar se já existe uma exceção para esta data
        $existing_exception = $this->get_exception_for_date($employee_id, $date);

        if ($existing_exception) {
            // Atualizar exceção existente para justificada
            $table = $wpdb->prefix . 'sistur_schedule_exceptions';
            $result = $wpdb->update(
                $table,
                array(
                    'is_justified' => 1,
                    'custom_expected_minutes' => 0, // Perdoa a ausência (não desconta do banco)
                    'notes' => $justification,
                    'status' => 'approved',
                    'approved_by' => get_current_user_id(),
                    'approved_at' => current_time('mysql')
                ),
                array('id' => $existing_exception->id),
                array('%d', '%d', '%s', '%s', '%d', '%s'),
                array('%d')
            );

            return $result !== false ? $existing_exception->id : false;
        } else {
            // Criar nova exceção justificada
            return $this->save_exception(array(
                'employee_id' => $employee_id,
                'exception_type' => 'absence',
                'date' => $date,
                'custom_expected_minutes' => 0, // Perdoa a ausência (não desconta do banco)
                'status' => 'approved',
                'is_justified' => 1,
                'notes' => $justification,
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql')
            ));
        }
    }

    /**
     * Remove a justificativa de uma falta
     * Quando a justificativa é removida, o banco de horas volta a descontar as horas esperadas.
     *
     * @param int $employee_id ID do funcionário
     * @param string $date Data da falta (formato Y-m-d)
     * @return bool Sucesso
     */
    public function unjustify_absence($employee_id, $date) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_schedule_exceptions';

        // Buscar a exceção
        $exception = $this->get_exception_for_date($employee_id, $date);

        if (!$exception || $exception->exception_type !== 'absence') {
            return false;
        }

        // Obter horas esperadas para o dia (da escala do funcionário)
        $expected_minutes = 0;
        if (class_exists('SISTUR_Shift_Patterns')) {
            $shift_patterns = SISTUR_Shift_Patterns::get_instance();

            // Temporariamente remover a exceção para obter as horas esperadas do dia
            $wpdb->update(
                $table,
                array('status' => 'pending'),
                array('id' => $exception->id),
                array('%s'),
                array('%d')
            );

            $table_employees = $wpdb->prefix . 'sistur_employees';
            $employee = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_employees WHERE id = %d",
                $employee_id
            ));

            if ($employee) {
                $expected_data = $shift_patterns->get_expected_hours_for_date($employee_id, $date);
                $expected_minutes = isset($expected_data['expected_minutes']) ? intval($expected_data['expected_minutes']) : 480;
            }
        }

        // Atualizar exceção para não justificada
        $result = $wpdb->update(
            $table,
            array(
                'is_justified' => 0,
                'custom_expected_minutes' => $expected_minutes, // Volta a descontar do banco
                'status' => 'approved'
            ),
            array('id' => $exception->id),
            array('%d', '%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Cria feriados em massa para o ano
     *
     * @param int $year Ano
     * @param array $employee_ids IDs dos funcionários (null = todos)
     * @return int Número de exceções criadas
     */
    public function create_national_holidays($year, $employee_ids = null) {
        // Feriados nacionais fixos
        $fixed_holidays = array(
            "$year-01-01" => "Ano Novo",
            "$year-04-21" => "Tiradentes",
            "$year-05-01" => "Dia do Trabalho",
            "$year-09-07" => "Independência do Brasil",
            "$year-10-12" => "Nossa Senhora Aparecida",
            "$year-11-02" => "Finados",
            "$year-11-15" => "Proclamação da República",
            "$year-12-25" => "Natal"
        );

        // Feriados móveis (Carnaval, Sexta-feira Santa, Corpus Christi)
        // Cálculo simplificado - pode ser expandido
        $easter = date('Y-m-d', easter_date($year));
        $easter_ts = strtotime($easter);

        $mobile_holidays = array(
            date('Y-m-d', strtotime('-47 days', $easter_ts)) => "Carnaval",
            date('Y-m-d', strtotime('-2 days', $easter_ts)) => "Sexta-feira Santa",
            date('Y-m-d', strtotime('+60 days', $easter_ts)) => "Corpus Christi"
        );

        $all_holidays = array_merge($fixed_holidays, $mobile_holidays);

        // Se não especificou funcionários, aplicar a todos
        if ($employee_ids === null) {
            global $wpdb;
            $table_employees = $wpdb->prefix . 'sistur_employees';
            $employee_ids = $wpdb->get_col("SELECT id FROM $table_employees WHERE status = 1");
        }

        $count = 0;

        foreach ($employee_ids as $employee_id) {
            foreach ($all_holidays as $date => $name) {
                $result = $this->save_exception(array(
                    'employee_id' => $employee_id,
                    'exception_type' => 'holiday',
                    'date' => $date,
                    'custom_expected_minutes' => 0,
                    'status' => 'approved',
                    'notes' => $name
                ));

                if ($result) {
                    $count++;
                }
            }
        }

        return $count;
    }

    // ========== AJAX HANDLERS ==========

    /**
     * AJAX: Obter exceções
     */
    public function ajax_get_exceptions() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID de funcionário inválido.'));
        }

        $exceptions = $this->get_employee_exceptions($employee_id, array(
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status' => $status
        ));

        wp_send_json_success(array('exceptions' => $exceptions));
    }

    /**
     * AJAX: Salvar exceção
     */
    public function ajax_save_exception() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $data = array(
            'id' => isset($_POST['id']) ? intval($_POST['id']) : 0,
            'employee_id' => isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0,
            'exception_type' => isset($_POST['exception_type']) ? sanitize_text_field($_POST['exception_type']) : 'holiday',
            'date' => isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '',
            'custom_expected_minutes' => isset($_POST['custom_expected_minutes']) && $_POST['custom_expected_minutes'] !== '' ? intval($_POST['custom_expected_minutes']) : null,
            'traded_with_employee_id' => isset($_POST['traded_with_employee_id']) && $_POST['traded_with_employee_id'] !== '' ? intval($_POST['traded_with_employee_id']) : null,
            'traded_date' => isset($_POST['traded_date']) && $_POST['traded_date'] !== '' ? sanitize_text_field($_POST['traded_date']) : null,
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : ''
        );

        $exception_id = $this->save_exception($data);

        if ($exception_id) {
            wp_send_json_success(array(
                'message' => 'Exceção salva com sucesso!',
                'exception_id' => $exception_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Erro ao salvar exceção.'));
        }
    }

    /**
     * AJAX: Deletar exceção
     */
    public function ajax_delete_exception() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $exception_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if ($exception_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        $result = $this->delete_exception($exception_id);

        if ($result) {
            wp_send_json_success(array('message' => 'Exceção deletada com sucesso!'));
        } else {
            wp_send_json_error(array('message' => 'Erro ao deletar exceção.'));
        }
    }

    /**
     * AJAX: Aprovar/rejeitar exceção
     */
    public function ajax_approve_exception() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $exception_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'approve';

        if ($exception_id <= 0) {
            wp_send_json_error(array('message' => 'ID inválido.'));
        }

        if ($action === 'reject') {
            $result = $this->reject_exception($exception_id);
            $message = 'Exceção rejeitada com sucesso!';
        } else {
            $result = $this->approve_exception($exception_id);
            $message = 'Exceção aprovada com sucesso!';
        }

        if ($result) {
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array('message' => 'Erro ao processar exceção.'));
        }
    }

    /**
     * AJAX: Obter exceções de um funcionário (alternativo)
     */
    public function ajax_get_employee_exceptions() {
        $this->ajax_get_exceptions();
    }

    /**
     * AJAX: Justificar falta
     */
    public function ajax_justify_absence() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $justification = isset($_POST['justification']) ? sanitize_textarea_field($_POST['justification']) : '';

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID de funcionário inválido.'));
        }

        if (empty($date)) {
            wp_send_json_error(array('message' => 'Data inválida.'));
        }

        $result = $this->justify_absence($employee_id, $date, $justification);

        if ($result) {
            // Reprocessar o dia para atualizar o banco de horas
            if (class_exists('SISTUR_Punch_Processing')) {
                $processor = SISTUR_Punch_Processing::get_instance();
                $processor->process_employee_day($employee_id, $date);
            }

            wp_send_json_success(array(
                'message' => 'Falta justificada com sucesso!',
                'exception_id' => $result
            ));
        } else {
            wp_send_json_error(array('message' => 'Erro ao justificar falta.'));
        }
    }

    /**
     * AJAX: Remover justificativa de falta
     */
    public function ajax_unjustify_absence() {
        check_ajax_referer('sistur_employees_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'ID de funcionário inválido.'));
        }

        if (empty($date)) {
            wp_send_json_error(array('message' => 'Data inválida.'));
        }

        $result = $this->unjustify_absence($employee_id, $date);

        if ($result) {
            // Reprocessar o dia para atualizar o banco de horas
            if (class_exists('SISTUR_Punch_Processing')) {
                $processor = SISTUR_Punch_Processing::get_instance();
                $processor->process_employee_day($employee_id, $date);
            }

            wp_send_json_success(array('message' => 'Justificativa removida com sucesso!'));
        } else {
            wp_send_json_error(array('message' => 'Erro ao remover justificativa.'));
        }
    }
}
