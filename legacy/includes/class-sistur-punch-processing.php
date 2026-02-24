<?php
/**
 * Classe de processamento de ponto eletrônico
 * Implementa o algoritmo 1-2-3-4 e cálculo de banco de horas
 *
 * @package SISTUR
 * @version 1.4.0
 */

class SISTUR_Punch_Processing {

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
        // Garantir que a tabela de ledger existe (Lazy creation)
        $this->create_ledger_table_if_needed();

        // Registrar API REST endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Nota: Processamento em lote via WP-Cron foi removido
        // O sistema agora processa imediatamente ao registrar cada batida
    }

    /**
     * Registra as rotas da API REST
     */
    public function register_rest_routes() {
        // POST /wp-json/sistur/v1/punch - Registrar batida via QR
        register_rest_route('sistur/v1', '/punch', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_punch'),
            'permission_callback' => '__return_true' // Público (validação por token)
        ));

        // GET /wp-json/sistur/v1/timesheet/(?P<user_id>\d+)/(?P<date>[\d-]+)
        register_rest_route('sistur/v1', '/timesheet/(?P<user_id>\d+)/(?P<date>[\d-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_timesheet'),
            'permission_callback' => array($this, 'check_timesheet_permission')
        ));

        // GET /wp-json/sistur/v1/balance/(?P<user_id>\d+)
        register_rest_route('sistur/v1', '/balance/(?P<user_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_balance'),
            'permission_callback' => array($this, 'check_balance_permission')
        ));

        // GET /wp-json/sistur/v1/cron/process - Endpoint para cron externo
        register_rest_route('sistur/v1', '/cron/process', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_cron_process'),
            'permission_callback' => array($this, 'check_cron_permission')
        ));

        // ===== NOVOS ENDPOINTS BANCO DE HORAS =====

        // GET /wp-json/sistur/v1/time-bank/(?P<employee_id>\d+)/weekly
        register_rest_route('sistur/v1', '/time-bank/(?P<employee_id>\d+)/weekly', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_weekly_timebank'),
            'permission_callback' => array($this, 'check_timebank_permission')
        ));

        // GET /wp-json/sistur/v1/time-bank/(?P<employee_id>\d+)/monthly
        register_rest_route('sistur/v1', '/time-bank/(?P<employee_id>\d+)/monthly', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_monthly_timebank'),
            'permission_callback' => array($this, 'check_timebank_permission')
        ));

        // GET /wp-json/sistur/v1/time-bank/(?P<employee_id>\d+)/current
        register_rest_route('sistur/v1', '/time-bank/(?P<employee_id>\d+)/current', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_current_status'),
            'permission_callback' => array($this, 'check_timebank_permission')
        ));

        // GET /wp-json/sistur/v1/time-bank/(?P<employee_id>\d+)/prediction
        register_rest_route('sistur/v1', '/time-bank/(?P<employee_id>\d+)/prediction', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_prediction'),
            'permission_callback' => array($this, 'check_timebank_permission')
        ));

        // POST /wp-json/sistur/v1/reprocess - Reprocessar dia(s) específico(s)
        register_rest_route('sistur/v1', '/reprocess', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_reprocess_days'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        // ===== NOVOS ENDPOINTS LEDGER (PAGAMENTOS/BAIXAS) =====

        // POST /wp-json/sistur/v1/time-bank/payment - Registrar pagamento/baixa
        register_rest_route('sistur/v1', '/time-bank/payment', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_register_payment'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));

        // GET /wp-json/sistur/v1/time-bank/(?P<employee_id>\d+)/ledger - Ver extrato
        register_rest_route('sistur/v1', '/time-bank/(?P<employee_id>\d+)/ledger', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_ledger'),
            'permission_callback' => array($this, 'check_timebank_permission')
        ));
    }

    /**
     * API: Registrar batida de ponto via QR code
     * POST /wp-json/sistur/v1/punch
     * Body: {"token": "uuid-do-qr-code"}
     */
    public function api_punch($request) {
        global $wpdb;

        $token = $request->get_param('token');

        // Validar se token foi fornecido
        if (empty($token)) {
            $this->log_failed_token_attempt('empty_token', $request);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Token não fornecido.'
            ), 400);
        }

        // SECURITY: Validar formato UUID v4 antes de consultar o banco
        if (!$this->validate_token_format($token)) {
            $this->log_failed_token_attempt('invalid_format', $request, $token);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Formato de token inválido.'
            ), 400);
        }

        // Validar token e buscar funcionário
        $employee = $this->get_employee_by_token($token);

        if (!$employee) {
            $this->log_failed_token_attempt('token_not_found', $request, $token);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Token inválido.'
            ), 401);
        }

        // Verificar perfil de localização (INTERNO = kiosk, EXTERNO = mobile)
        if ($employee->perfil_localizacao === 'EXTERNO') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Este funcionário deve usar o aplicativo móvel para bater ponto.'
            ), 403);
        }

        // Registrar a batida
        $table = $wpdb->prefix . 'sistur_time_entries';
        $timestamp = current_time('mysql');
        $shift_date = current_time('Y-m-d');

        // BUG FIX #2: Verificar batidas duplicadas nos últimos 5 segundos
        $recent_punch = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE employee_id = %d
             AND punch_time >= DATE_SUB(%s, INTERVAL 5 SECOND)",
            $employee->id, $timestamp
        ));

        if ($recent_punch > 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Batida já registrada recentemente. Aguarde alguns segundos antes de bater ponto novamente.'
            ), 429);
        }

        $result = $wpdb->insert(
            $table,
            array(
                'employee_id' => $employee->id,
                'punch_type' => 'auto', // Sistema define automaticamente depois
                'punch_time' => $timestamp,
                'shift_date' => $shift_date,
                'source' => 'KIOSK',
                'processing_status' => 'PENDENTE'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Erro ao registrar batida.'
            ), 500);
        }

        // PROCESSAMENTO IMEDIATO: Processar o dia atual após registrar a batida
        // Isso garante que o banco de horas é atualizado em tempo real
        $this->process_employee_day($employee->id, $shift_date);

        // Buscar dados atualizados do dia para retornar ao frontend
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $day_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $employee->id, $shift_date
        ), ARRAY_A);

        // Buscar todas as batidas do dia
        $punches = $wpdb->get_results($wpdb->prepare(
            "SELECT punch_time, punch_type FROM $table
             WHERE employee_id = %d AND shift_date = %s
             ORDER BY punch_time ASC",
            $employee->id, $shift_date
        ), ARRAY_A);

        // Calcular horas extras baseado em shift pattern
        $expected_minutes = $this->get_expected_minutes($employee, $shift_date);
        $worked_minutes = $day_data ? intval($day_data['minutos_trabalhados']) : 0;
        $overtime_minutes = max(0, $worked_minutes - $expected_minutes);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Ponto registrado com sucesso!',
            'data' => array(
                'employee_name' => $employee->name,
                'punch_time' => date('H:i:s', strtotime($timestamp)),
                'entry_id' => $wpdb->insert_id,
                'day_summary' => array(
                    'worked_minutes' => $worked_minutes,
                    'worked_formatted' => $this->format_minutes($worked_minutes),
                    'expected_minutes' => $expected_minutes,
                    'expected_formatted' => $this->format_minutes($expected_minutes),
                    'balance_minutes' => $day_data ? intval($day_data['saldo_final_minutos']) : 0,
                    'balance_formatted' => $day_data ? $this->format_minutes(intval($day_data['saldo_final_minutos']), true) : '0h00',
                    'overtime_minutes' => $overtime_minutes,
                    'overtime_formatted' => $this->format_minutes($overtime_minutes),
                    'needs_review' => $day_data ? (bool)$day_data['needs_review'] : false
                ),
                'punches' => $punches
            )
        ), 201);
    }

    /**
     * API: Obter folha de ponto de um funcionário em uma data
     * GET /wp-json/sistur/v1/timesheet/{user_id}/{date}
     */
    public function api_get_timesheet($request) {
        global $wpdb;

        $user_id = intval($request['user_id']);
        $date = sanitize_text_field($request['date']);

        // Buscar dados do dia
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $day_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $user_id, $date
        ), ARRAY_A);

        // Buscar registros de ponto
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_entries WHERE employee_id = %d AND shift_date = %s ORDER BY punch_time ASC",
            $user_id, $date
        ), ARRAY_A);

        $response = array(
            'date' => $date,
            'user_id' => $user_id,
            'status_dia' => $day_data ? $day_data['status'] : 'present',
            'needs_review' => $day_data && $day_data['needs_review'] ? true : false,
            'saldo_dia_minutos' => $day_data ? intval($day_data['saldo_final_minutos']) : 0,
            'minutos_trabalhados' => $day_data ? intval($day_data['minutos_trabalhados']) : 0,
            'registros' => array_map(function($entry) {
                return array(
                    'id' => intval($entry['id']),
                    'punch_time' => $entry['punch_time'],
                    'punch_type' => $entry['punch_type'],
                    'source' => $entry['source']
                );
            }, $entries),
            'notes' => $day_data ? $day_data['notes'] : null,
            'supervisor_notes' => $day_data ? $day_data['supervisor_notes'] : null
        );

        return new WP_REST_Response($response, 200);
    }

    /**
     * API: Obter banco de horas total do funcionário
     * GET /wp-json/sistur/v1/balance/{user_id}
     */
    public function api_get_balance($request) {
        global $wpdb;

        $user_id = intval($request['user_id']);

        // NOVO: Processar registros pendentes dos últimos 90 dias antes de calcular
        // Isso garante que o saldo exibido está atualizado
        $start_date = date('Y-m-d', strtotime('-90 days'));
        $end_date = date('Y-m-d');
        $this->process_period_if_needed($user_id, $start_date, $end_date);

        $table = $wpdb->prefix . 'sistur_time_days';

        // 1. Saldo Bruto (Produzido)
        // Somar apenas dias com status = 'present' e que não precisam de revisão
        $total_produced = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos)
             FROM $table
             WHERE employee_id = %d
             AND status = 'present'
             AND needs_review = 0",
            $user_id
        ));

        // 2. Total Pago/Compensado (Baixas)
        $total_paid = $this->get_ledger_debits($user_id);

        // 3. Saldo Líquido Disponível
        $total_minutes = intval($total_produced) - intval($total_paid);

        $hours = floor(abs($total_minutes) / 60);
        $minutes = abs($total_minutes) % 60;
        $sign = $total_minutes < 0 ? '-' : '+';

        return new WP_REST_Response(array(
            'user_id' => $user_id,
            'saldo_produzido_minutos' => intval($total_produced),
            'saldo_pago_minutos' => intval($total_paid),
            'total_banco_horas_minutos' => $total_minutes, // Disponível
            'formatted' => sprintf('%s%02d:%02d', $sign, $hours, $minutes)
        ), 200);
    }

    /**
     * API: Endpoint para cron externo
     * GET /wp-json/sistur/v1/cron/process?key=SECRET_KEY
     */
    public function api_cron_process($request) {
        $this->process_yesterday_punches();

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Processamento iniciado com sucesso.'
        ), 200);
    }

    /**
     * API: Reprocessar dia(s) específico(s)
     * POST /wp-json/sistur/v1/reprocess
     * Body: {
     *   "employee_id": 123,          // opcional, se omitido processa todos
     *   "start_date": "2024-11-18",  // obrigatório
     *   "end_date": "2024-11-18"     // opcional, default = start_date
     * }
     */
    public function api_reprocess_days($request) {
        global $wpdb;

        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date') ?: $start_date;
        $employee_id = $request->get_param('employee_id');

        if (!$start_date) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Parâmetro start_date é obrigatório'
            ), 400);
        }

        // Validar formato de data
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Formato de data inválido. Use YYYY-MM-DD'
            ), 400);
        }

        $table = $wpdb->prefix . 'sistur_time_entries';
        $processed_count = 0;
        $errors = array();

        // Buscar funcionários e datas a processar
        if ($employee_id) {
            // Processar um funcionário específico
            $current = strtotime($start_date);
            $end = strtotime($end_date);

            while ($current <= $end) {
                $date = date('Y-m-d', $current);

                // Marcar batidas como PENDENTE para forçar reprocessamento
                $wpdb->update(
                    $table,
                    array('processing_status' => 'PENDENTE'),
                    array('employee_id' => $employee_id, 'shift_date' => $date),
                    array('%s'),
                    array('%d', '%s')
                );

                $result = $this->process_employee_day($employee_id, $date);
                if ($result) {
                    $processed_count++;
                } else {
                    $errors[] = "Erro ao processar employee $employee_id no dia $date";
                }

                $current = strtotime('+1 day', $current);
            }
        } else {
            // Processar todos os funcionários no intervalo
            $current = strtotime($start_date);
            $end = strtotime($end_date);

            while ($current <= $end) {
                $date = date('Y-m-d', $current);

                // Buscar todos funcionários com batidas nesse dia
                $employees = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT employee_id FROM $table WHERE shift_date = %s",
                    $date
                ));

                foreach ($employees as $emp_id) {
                    // Marcar batidas como PENDENTE
                    $wpdb->update(
                        $table,
                        array('processing_status' => 'PENDENTE'),
                        array('employee_id' => $emp_id, 'shift_date' => $date),
                        array('%s'),
                        array('%d', '%s')
                    );

                    $result = $this->process_employee_day($emp_id, $date);
                    if ($result) {
                        $processed_count++;
                    } else {
                        $errors[] = "Erro ao processar employee $emp_id no dia $date";
                    }
                }

                $current = strtotime('+1 day', $current);
            }
        }

        $response = array(
            'success' => true,
            'message' => "Reprocessamento concluído. $processed_count dia(s) processado(s).",
            'processed_count' => $processed_count
        );

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return new WP_REST_Response($response, 200);
    }


    /**
     * Processa o dia de um funcionário (ALGORITMO DE PARES FECHADOS)
     *
     * @param int $employee_id ID do funcionário
     * @param string $date Data no formato Y-m-d
     * @return bool Sucesso do processamento
     */
    public function process_employee_day($employee_id, $date) {
        global $wpdb;

        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $table_employees = $wpdb->prefix . 'sistur_employees';

        error_log("SISTUR DEBUG process_employee_day: INICIANDO employee_id=$employee_id, date=$date");

        // BUG FIX #1: Iniciar transação para evitar race conditions
        $wpdb->query('START TRANSACTION');

        try {
            // Buscar funcionário com lock
            $employee = $wpdb->get_row($wpdb->prepare(
                "SELECT e.*
                 FROM $table_employees e
                 WHERE e.id = %d",
                $employee_id
            ));

            if (!$employee) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Lock da linha do dia para evitar processamento simultâneo
            $wpdb->query($wpdb->prepare(
                "SELECT id FROM $table_days WHERE employee_id = %d AND shift_date = %s FOR UPDATE",
                $employee_id, $date
            ));

        // Carga horária esperada do funcionário baseado em shift pattern
        $expected_minutes = $this->get_expected_minutes($employee, $date);

        // SNAPSHOT HISTÓRICO (v2.2.0): Capturar ID da escala ativa para armazenar como snapshot
        $schedule_id_for_snapshot = null;
        if (class_exists('SISTUR_Shift_Patterns')) {
            $shift_patterns = SISTUR_Shift_Patterns::get_instance();
            $active_schedule = $shift_patterns->get_employee_active_schedule($employee_id, $date);
            if ($active_schedule && isset($active_schedule['id'])) {
                $schedule_id_for_snapshot = intval($active_schedule['id']);
            }
        }

        // Buscar batidas do dia, ordenadas por horário
        $punches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_entries
             WHERE employee_id = %d
             AND shift_date = %s
             ORDER BY punch_time ASC",
            $employee_id, $date
        ), ARRAY_A);

        $punch_count = count($punches);

        // ===== NOVA LÓGICA: CÁLCULO POR PARES FECHADOS =====

        // 1. Inicializar minutos trabalhados
        $minutos_trabalhados = 0;
        $needs_review = false;
        $debug_info = array();

            // BUG FIX #6: Validar ordem cronológica das batidas
            for ($i = 0; $i < $punch_count - 1; $i++) {
                $current_time = strtotime($punches[$i]['punch_time']);
                $next_time = strtotime($punches[$i + 1]['punch_time']);

                if ($current_time !== false && $next_time !== false) {
                    if ($current_time >= $next_time) {
                        $needs_review = true;
                        $debug_info[] = sprintf(
                            'ERRO: Batidas fora de ordem cronológica - Batida %d (%s) >= Batida %d (%s)',
                            $i + 1, $punches[$i]['punch_time'],
                            $i + 2, $punches[$i + 1]['punch_time']
                        );
                    }
                }
            }

        // 2. Percorrer batidas em PARES (0 e 1, 2 e 3, etc.)
        for ($i = 0; $i < $punch_count; $i += 2) {
            $entrada = isset($punches[$i]) ? $punches[$i] : null;
            $saida = isset($punches[$i + 1]) ? $punches[$i + 1] : null;

            // 3. REGRA DE OURO: Só calcular se AMBAS as batidas existirem
            if ($entrada && $saida) {
                $entrada_timestamp = strtotime($entrada['punch_time']);
                $saida_timestamp = strtotime($saida['punch_time']);

                // Validar timestamps
                if ($entrada_timestamp === false || $saida_timestamp === false) {
                    $debug_info[] = sprintf(
                        'ERRO: Timestamp inválido no par %d - Entrada: %s, Saída: %s',
                        ($i / 2) + 1,
                        $entrada['punch_time'],
                        $saida['punch_time']
                    );
                    $needs_review = true;
                    continue;
                }

                // Validar ordem (saída deve ser depois da entrada)
                if ($saida_timestamp <= $entrada_timestamp) {
                    $debug_info[] = sprintf(
                        'ERRO: Horário de saída antes ou igual à entrada no par %d - Entrada: %s, Saída: %s',
                        ($i / 2) + 1,
                        $entrada['punch_time'],
                        $saida['punch_time']
                    );
                    $needs_review = true;
                    continue;
                }

                // Calcular diferença em minutos
                $minutos_par = ($saida_timestamp - $entrada_timestamp) / 60;
                $minutos_trabalhados += $minutos_par;

                $debug_info[] = sprintf(
                    'Par %d calculado: %s a %s = %.2f minutos',
                    ($i / 2) + 1,
                    date('H:i', $entrada_timestamp),
                    date('H:i', $saida_timestamp),
                    $minutos_par
                );
            } elseif ($entrada && !$saida) {
                // REGRA DE OURO: Se existe entrada sem saída, NÃO calcular até agora
                // Apenas marcar para revisão
                $needs_review = true;
                $debug_info[] = sprintf(
                    'Par %d incompleto: entrada %s sem saída',
                    ($i / 2) + 1,
                    $entrada['punch_time']
                );
            }
        }

        $debug_info[] = sprintf('Total calculado: %.2f minutos', $minutos_trabalhados);

        // 4. APLICAR TOLERÂNCIA APENAS PARA ATRASOS (saldo negativo)
        $saldo_bruto = $minutos_trabalhados - $expected_minutes;
        $saldo_calculado_minutos = $this->apply_delay_tolerance($saldo_bruto);

        $debug_info[] = sprintf('Saldo bruto: %.2f minutos, Saldo após tolerância: %.2f minutos',
            $saldo_bruto, $saldo_calculado_minutos);

        // Buscar se já existe registro do dia
        $existing_day = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $employee_id, $date
        ), ARRAY_A);

        $manual_adjustment = $existing_day ? intval($existing_day['bank_minutes_adjustment']) : 0;
        $saldo_final_minutos = $saldo_calculado_minutos + $manual_adjustment;

        $day_data = array(
            'minutos_trabalhados' => round($minutos_trabalhados),
            'saldo_calculado_minutos' => round($saldo_calculado_minutos),
            'saldo_final_minutos' => round($saldo_final_minutos),
            'needs_review' => $needs_review ? 1 : 0,
            'status' => 'present',
            'updated_at' => current_time('mysql'),
            // SNAPSHOT HISTÓRICO (v2.2.0): Armazenar expectativa no momento do processamento
            // Isso garante que alterações futuras de escala NÃO afetem cálculos passados
            'expected_minutes_snapshot' => $expected_minutes,
            'schedule_id_snapshot' => $schedule_id_for_snapshot,
        );


        // Adicionar nota se marcado para revisão
        if ($needs_review) {
            $day_data['notes'] = sprintf(
                'Dia marcado para revisão: %d batida(s) registrada(s). Apenas pares fechados foram calculados.',
                $punch_count
            );
        }

        // Adicionar informações de debug às notes (útil para troubleshooting)
        if (!empty($debug_info) && defined('WP_DEBUG') && WP_DEBUG) {
            $existing_notes = isset($day_data['notes']) ? $day_data['notes'] : '';
            $debug_notes = "\n\n[DEBUG]\n" . implode("\n", $debug_info);
            $day_data['notes'] = $existing_notes . $debug_notes;
        }

        if ($existing_day) {
            // Atualizar - construir array de formato dinamicamente
            // minutos_trabalhados, saldo_calculado, saldo_final, needs_review, status, updated_at, expected_minutes_snapshot, schedule_id_snapshot
            $update_formats = array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d');
            if (isset($day_data['notes'])) {
                $update_formats[] = '%s'; // notes
            }

            $wpdb->update(
                $table_days,
                $day_data,
                array('id' => $existing_day['id']),
                $update_formats,
                array('%d')
            );
        } else {
            // Inserir - construir array de formato dinamicamente
            $day_data['employee_id'] = $employee_id;
            $day_data['shift_date'] = $date;

            // CORREÇÃO CRÍTICA: A ordem dos formatos DEVE corresponder à ordem das chaves em $day_data:
            // 1. minutos_trabalhados (%d)
            // 2. saldo_calculado_minutos (%d)
            // 3. saldo_final_minutos (%d)
            // 4. needs_review (%d)
            // 5. status (%s) <- ERA %d, ERRO!
            // 6. updated_at (%s)
            // 7. expected_minutes_snapshot (%d)
            // 8. schedule_id_snapshot (%d)
            // 9. employee_id (%d)
            // 10. shift_date (%s) <- ERA %d, ERRO!
            $insert_formats = array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s');
            if (isset($day_data['notes'])) {
                $insert_formats[] = '%s'; // notes
            }

            error_log("SISTUR DEBUG: Tentando INSERT em time_days para employee_id=$employee_id, date=$date");
            error_log("SISTUR DEBUG: day_data keys order: " . implode(', ', array_keys($day_data)));

            $insert_result = $wpdb->insert(
                $table_days,
                $day_data,
                $insert_formats
            );

            if ($insert_result === false) {
                error_log("SISTUR DEBUG ERRO: Falha no INSERT! wpdb->last_error: " . $wpdb->last_error);
            } else {
                error_log("SISTUR DEBUG: INSERT bem-sucedido, insert_id=" . $wpdb->insert_id);
            }
        }

        // Marcar batidas como processadas
        $wpdb->update(
            $table_entries,
            array('processing_status' => 'PROCESSADO'),
            array('employee_id' => $employee_id, 'shift_date' => $date),
            array('%s'),
            array('%d', '%s')
        );

        // Atualizar punch_type das batidas (baseado na posição)
        for ($i = 0; $i < $punch_count; $i++) {
            if (!isset($punches[$i]['id'])) continue;

            // REGRA DE INTEGRIDADE: Se foi criado por admin ou já tem um tipo definido, NÃO alterar automaticamente
            $current_type = isset($punches[$i]['punch_type']) ? $punches[$i]['punch_type'] : 'auto';
            $source = isset($punches[$i]['source']) ? $punches[$i]['source'] : '';
            
            // Se a fonte for admin, ou se o tipo já estiver definido corretamente (não é auto), pular reclassificação
            if ($source === 'admin' || ($current_type !== 'auto' && !empty($current_type))) {
                continue;
            }

            $punch_type = 'auto';

            // Tentar inferir o tipo baseado na posição
            if ($i == 0) {
                $punch_type = 'clock_in';
            } elseif ($i == 1 && $punch_count >= 4) {
                $punch_type = 'lunch_start';
            } elseif ($i == 2 && $punch_count >= 4) {
                $punch_type = 'lunch_end';
            } elseif ($i == $punch_count - 1 && $punch_count % 2 == 0) {
                $punch_type = 'clock_out';
            }

            // Só atualizar se tivermos uma inferência válida (diferente de auto)
            if ($punch_type !== 'auto') {
                $wpdb->update(
                    $table_entries,
                    array('punch_type' => $punch_type),
                    array('id' => $punches[$i]['id'])
                );
            }
        }

            // BUG FIX #1: Commit da transação
            $wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            // Em caso de erro, fazer rollback
            $wpdb->query('ROLLBACK');
            error_log('SISTUR: Erro no processamento do dia - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Processa todos os dias pendentes de um funcionário em um período
     * Útil para garantir dados atualizados antes de consultas
     *
     * @param int $employee_id ID do funcionário
     * @param string $start_date Data inicial (Y-m-d)
     * @param string $end_date Data final (Y-m-d)
     * @return int Número de dias processados
     */
    private function process_period_if_needed($employee_id, $start_date, $end_date) {
        global $wpdb;

        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $processed_count = 0;

        // Buscar datas com registros pendentes no período
        $pending_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT shift_date
             FROM $table_entries
             WHERE employee_id = %d
             AND shift_date BETWEEN %s AND %s
             AND processing_status = 'PENDENTE'
             ORDER BY shift_date ASC",
            $employee_id, $start_date, $end_date
        ));

        // Se não houver pendentes, verificar se há datas sem registro na tabela sistur_time_days
        if (empty($pending_dates)) {
            $table_days = $wpdb->prefix . 'sistur_time_days';

            // Buscar datas que têm registros mas não têm processamento
            $unprocessed_dates = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT te.shift_date
                 FROM $table_entries te
                 LEFT JOIN $table_days td ON te.employee_id = td.employee_id AND te.shift_date = td.shift_date
                 WHERE te.employee_id = %d
                 AND te.shift_date BETWEEN %s AND %s
                 AND td.shift_date IS NULL
                 ORDER BY te.shift_date ASC",
                $employee_id, $start_date, $end_date
            ));

            $pending_dates = $unprocessed_dates;
        }

        // Processar cada dia pendente
        foreach ($pending_dates as $date) {
            $result = $this->process_employee_day($employee_id, $date);
            if ($result) {
                $processed_count++;
            }
        }

        return $processed_count;
    }

    /**
     * Marca um dia para revisão (quando não tem 4 batidas)
     */
    private function mark_day_for_review($employee_id, $date, $punches) {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_time_days';

        $punch_count = count($punches);
        $notes = sprintf('Dia marcado para revisão: %d batida(s) registrada(s) ao invés de 4.', $punch_count);

        // Verificar se já existe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE employee_id = %d AND shift_date = %s",
            $employee_id, $date
        ));

        $day_data = array(
            'needs_review' => 1,
            'status' => 'present',
            'notes' => $notes,
            'updated_at' => current_time('mysql')
        );

        if ($existing) {
            $wpdb->update($table, $day_data, array('id' => $existing), array('%d', '%s', '%s', '%s'), array('%d'));
        } else {
            $day_data['employee_id'] = $employee_id;
            $day_data['shift_date'] = $date;
            $wpdb->insert($table, $day_data, array('%d', '%d', '%s', '%s', '%s', '%s'));
        }
    }

    /**
     * Aplica tolerância apenas para atrasos (saldo negativo)
     *
     * A tolerância funciona como "perdão" de pequenos atrasos.
     * Apenas débitos (saldo negativo) menores que a tolerância são perdoados.
     * Horas extras (saldo positivo) não são afetadas pela tolerância.
     *
     * @param float $balance_minutes Saldo bruto em minutos (trabalhado - esperado)
     * @return float Saldo ajustado após aplicar tolerância
     */
    private function apply_delay_tolerance($balance_minutes) {
        // Buscar tolerância de atraso configurada
        $tolerance_delay = intval($this->get_setting('tolerance_minutes_delay', 0));

        // Se não há tolerância configurada, retornar saldo original
        if ($tolerance_delay <= 0) {
            return $balance_minutes;
        }

        // Tolerância só se aplica a ATRASOS (saldo negativo)
        if ($balance_minutes < 0) {
            // Se o atraso é menor ou igual à tolerância, perdoar completamente
            if (abs($balance_minutes) <= $tolerance_delay) {
                return 0;
            }
            // Se o atraso é maior que a tolerância, descontar apenas o que excede a tolerância
            return $balance_minutes + $tolerance_delay;
        }

        // Saldo positivo (hora extra) não é afetado pela tolerância
        return $balance_minutes;
    }

    /**
     * Busca funcionário por token QR
     */
    private function get_employee_by_token($token) {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_employees';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE token_qr = %s AND status = 1",
            $token
        ));
    }

    /**
     * Buscar configuração do sistema
     */
    private function get_setting($key, $default = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'sistur_settings';
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM $table WHERE setting_key = %s",
            $key
        ));

        if ($value === null) {
            return $default;
        }

        // Converter tipos
        $type = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_type FROM $table WHERE setting_key = %s",
            $key
        ));

        switch ($type) {
            case 'integer':
                return intval($value);
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * API: Obter dados semanais do banco de horas
     * GET /wp-json/sistur/v1/time-bank/{employee_id}/weekly?week=2025-01-20
     */
    public function api_get_weekly_timebank($request) {
        global $wpdb;

        $employee_id = intval($request['employee_id']);
        $week_param = $request->get_param('week');

        // Se não informar semana, usar a semana atual
        $reference_date = $week_param ? $week_param : current_time('Y-m-d');

        // Obter início (segunda) e fim (sexta) da semana
        $week_start = date('Y-m-d', strtotime('monday this week', strtotime($reference_date)));
        $week_end = date('Y-m-d', strtotime('friday this week', strtotime($reference_date)));

        // NOVO: Processar dias pendentes antes de consultar
        // Isso garante que visualizações mostrem dados atualizados
        $this->process_period_if_needed($employee_id, $week_start, $week_end);

        // Buscar funcionário
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*
             FROM $table_employees e
             WHERE e.id = %d",
            $employee_id
        ));

        if (!$employee) {
            return new WP_REST_Response(array('error' => 'Funcionário não encontrado'), 404);
        }

        // Buscar dados dos dias da semana
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $table_entries = $wpdb->prefix . 'sistur_time_entries';

        $days = array();
        $total_worked_minutes = 0;
        $total_expected_minutes = 0;
        $total_deviation_minutes = 0;

        // Iterar de segunda a sexta
        $current = strtotime($week_start);
        $end = strtotime($week_end);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $day_name = date('l', $current); // Monday, Tuesday, etc
            $day_name_pt = $this->translate_day_name($day_name);

            // Buscar dados do dia
            $day_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
                $employee_id, $date
            ), ARRAY_A);

            // Buscar batidas do dia
            $punches = $wpdb->get_results($wpdb->prepare(
                "SELECT punch_type, punch_time FROM $table_entries
                 WHERE employee_id = %d AND shift_date = %s
                 ORDER BY punch_time ASC",
                $employee_id, $date
            ), ARRAY_A);

            // Calcular carga horária esperada baseado em shift pattern PARA ESTA DATA
            $expected_daily_minutes = $this->get_expected_minutes($employee, $date);

            $worked_minutes = $day_data ? intval($day_data['minutos_trabalhados']) : 0;
            $deviation_minutes = $day_data ? intval($day_data['saldo_final_minutos']) : 0;
            $status = $day_data ? $day_data['status'] : 'present';

            // Formatar batidas
            $formatted_punches = array(
                'clock_in' => null,
                'lunch_start' => null,
                'lunch_end' => null,
                'clock_out' => null
            );

            foreach ($punches as $punch) {
                $time = date('H:i', strtotime($punch['punch_time']));
                $formatted_punches[$punch['punch_type']] = $time;
            }

            $days[] = array(
                'date' => $date,
                'day_name' => $day_name_pt,
                'day_abbr' => substr($day_name_pt, 0, 3),
                'status' => $status,
                'punches' => $formatted_punches,
                'worked_minutes' => $worked_minutes,
                'worked_formatted' => $this->format_minutes($worked_minutes),
                'expected_minutes' => $expected_daily_minutes,
                'expected_formatted' => $this->format_minutes($expected_daily_minutes),
                'deviation_minutes' => $deviation_minutes,
                'deviation_formatted' => $this->format_minutes($deviation_minutes, true),
                'needs_review' => $day_data ? (bool)$day_data['needs_review'] : false,
                'notes' => $day_data ? $day_data['notes'] : '',
                'supervisor_notes' => $day_data ? $day_data['supervisor_notes'] : ''
            );

            // Somar trabalhado e desvio APENAS para dias com registro
            if ($day_data && $status === 'present') {
                $total_worked_minutes += $worked_minutes;
                $total_deviation_minutes += $deviation_minutes;
                $total_expected_minutes += $expected_daily_minutes;
            } elseif (!$day_data && $expected_daily_minutes > 0) {
                // Dia sem registro mas com expectativa de trabalho = falta
                // Só conta se a data já passou
                $today = current_time('Y-m-d');
                if ($date < $today) {
                    $total_expected_minutes += $expected_daily_minutes;
                    $total_deviation_minutes -= $expected_daily_minutes; // Falta = -8h
                }
            }

            $current = strtotime('+1 day', $current);
        }

        // ============================================================
        // NOVA LÓGICA: VERIFICAÇÃO SEMANAL PARA ESCALAS FLEXÍVEIS
        // ============================================================
        $is_flexible_schedule = false;
        $weekly_expected_minutes = 0;
        $flexible_deficit_minutes = 0;
        
        // Verificar se funcionário tem escala de horas flexíveis
        if (class_exists('SISTUR_Shift_Patterns')) {
            $shift_patterns = SISTUR_Shift_Patterns::get_instance();
            $schedule = $shift_patterns->get_employee_active_schedule($employee_id, $week_end);
            
            if ($schedule && $schedule['pattern_type'] === 'flexible_hours') {
                $is_flexible_schedule = true;
                $weekly_expected_minutes = intval($schedule['weekly_hours_minutes']);
                
                // Calcular déficit semanal: trabalhou menos que o esperado?
                if ($total_worked_minutes < $weekly_expected_minutes) {
                    $flexible_deficit_minutes = $total_worked_minutes - $weekly_expected_minutes; // Será negativo
                    
                    // Registrar déficit no banco de horas (se semana já passou)
                    $today = current_time('Y-m-d');
                    if ($week_end < $today) {
                        $this->register_flexible_weekly_deficit(
                            $employee_id, 
                            $week_start, 
                            $week_end, 
                            $flexible_deficit_minutes,
                            $total_worked_minutes,
                            $weekly_expected_minutes
                        );
                    }
                }
            }
        }
        // ============================================================
        // FIM: VERIFICAÇÃO SEMANAL PARA ESCALAS FLEXÍVEIS
        // ============================================================

        // Buscar saldo total acumulado (Produzido)
        $total_produced = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(saldo_final_minutos) FROM $table_days
             WHERE employee_id = %d AND status = 'present' AND needs_review = 0",
            $employee_id
        ));

        // Buscar total pago/compensado
        $total_paid = $this->get_ledger_debits($employee_id);

        // Saldo Disponível (Visão Geral RH)
        $total_bank = intval($total_produced) - intval($total_paid);

        // Para escalas flexíveis, calcular expectativa semanal correta
        // Para outras escalas, usar o total esperado calculado no loop
        $expected_weekly_for_response = $is_flexible_schedule 
            ? $weekly_expected_minutes 
            : $total_expected_minutes;

        $response = array(
            'employee_id' => $employee_id,
            'employee_name' => $employee->name,
            'week_start' => $week_start,
            'week_end' => $week_end,
            'days' => $days,
            'is_flexible_schedule' => $is_flexible_schedule,
            'summary' => array(
                'total_worked_minutes' => $total_worked_minutes,
                'total_worked_formatted' => $this->format_minutes($total_worked_minutes),
                'total_expected_minutes' => $expected_weekly_for_response,
                'total_expected_formatted' => $this->format_minutes($expected_weekly_for_response),
                // VISÃO SEMANAL (Reset visual)
                'week_deviation_minutes' => $is_flexible_schedule ? $flexible_deficit_minutes : $total_deviation_minutes,
                'week_deviation_formatted' => $this->format_minutes($is_flexible_schedule ? $flexible_deficit_minutes : $total_deviation_minutes, true),
                // VISÃO GERAL (Acumulado Real)
                'accumulated_bank_minutes' => intval($total_bank),
                'accumulated_bank_formatted' => $this->format_minutes(intval($total_bank), true),
                'total_produced_minutes' => intval($total_produced),
                'total_paid_minutes' => intval($total_paid),
                // Campos adicionais para escalas flexíveis
                'flexible_deficit_minutes' => $flexible_deficit_minutes,
                'flexible_deficit_formatted' => $this->format_minutes($flexible_deficit_minutes, true)
            )
        );

        return new WP_REST_Response($response, 200);
    }

    /**
     * API: Obter dados mensais do banco de horas
     * GET /wp-json/sistur/v1/time-bank/{employee_id}/monthly?month=2025-01
     */
    public function api_get_monthly_timebank($request) {
        global $wpdb;

        $employee_id = intval($request['employee_id']);
        $month_param = $request->get_param('month');

        // Se não informar mês, usar o mês atual
        $reference_month = $month_param ? $month_param : current_time('Y-m');

        $month_start = $reference_month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));

        // NOVO: Processar dias pendentes antes de consultar
        // Isso garante que visualizações mostrem dados atualizados
        $this->process_period_if_needed($employee_id, $month_start, $month_end);

        // Buscar todos os dias do mês
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $days_data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_days
             WHERE employee_id = %d
             AND shift_date BETWEEN %s AND %s
             ORDER BY shift_date ASC",
            $employee_id, $month_start, $month_end
        ), ARRAY_A);

        $total_worked = 0;
        $total_deviation = 0;
        $days_present = 0;
        $days_absent = 0;
        $days_review = 0;

        $formatted_days = array();

        foreach ($days_data as $day) {
            $worked = intval($day['minutos_trabalhados']);
            $deviation = intval($day['saldo_final_minutos']);

            if ($day['status'] === 'present') {
                $total_worked += $worked;
                $total_deviation += $deviation;
                $days_present++;
            } else {
                $days_absent++;
            }

            if ($day['needs_review']) {
                $days_review++;
            }

            $formatted_days[] = array(
                'date' => $day['shift_date'],
                'worked_minutes' => $worked,
                'worked_formatted' => $this->format_minutes($worked),
                'deviation_minutes' => $deviation,
                'deviation_formatted' => $this->format_minutes($deviation, true),
                'status' => $day['status'],
                'needs_review' => (bool)$day['needs_review']
            );
        }

        $response = array(
            'employee_id' => $employee_id,
            'month' => $reference_month,
            'month_name' => date('F Y', strtotime($month_start)),
            'days' => $formatted_days,
            'summary' => array(
                'total_worked_minutes' => $total_worked,
                'total_worked_formatted' => $this->format_minutes($total_worked),
                'total_deviation_minutes' => $total_deviation,
                'total_deviation_formatted' => $this->format_minutes($total_deviation, true),
                'days_present' => $days_present,
                'days_absent' => $days_absent,
                'days_review' => $days_review
            )
        );

        return new WP_REST_Response($response, 200);
    }

    /**
     * API: Obter status atual (tempo real do dia de hoje)
     * GET /wp-json/sistur/v1/time-bank/{employee_id}/current
     */
    public function api_get_current_status($request) {
        global $wpdb;

        $employee_id = intval($request['employee_id']);
        $today = current_time('Y-m-d');

        // Buscar batidas de hoje
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $punches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_entries
             WHERE employee_id = %d AND shift_date = %s
             ORDER BY punch_time ASC",
            $employee_id, $today
        ), ARRAY_A);

        $punch_count = count($punches);

        // ===== NOVA LÓGICA: CALCULAR APENAS PARES FECHADOS =====
        $worked_minutes = 0;
        $working_now = false;

        // Percorrer batidas em PARES
        for ($i = 0; $i < $punch_count; $i += 2) {
            $entrada = isset($punches[$i]) ? $punches[$i] : null;
            $saida = isset($punches[$i + 1]) ? $punches[$i + 1] : null;

            // Só calcular se AMBAS existirem (par fechado)
            if ($entrada && $saida) {
                $entrada_timestamp = strtotime($entrada['punch_time']);
                $saida_timestamp = strtotime($saida['punch_time']);
                $worked_minutes += ($saida_timestamp - $entrada_timestamp) / 60;
            } elseif ($entrada && !$saida) {
                // REGRA DE OURO: Existe entrada sem saída
                // Se for HOJE, pode marcar que está trabalhando agora
                // Mas NÃO soma ao total de minutos trabalhados
                if ($today === current_time('Y-m-d')) {
                    $working_now = true;
                }
            }
        }

        // Buscar funcionário para saber carga esperada
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*
             FROM $table_employees e
             WHERE e.id = %d",
            $employee_id
        ));

        // Calcular carga horária esperada do funcionário baseado em shift pattern
        $expected_minutes = $this->get_expected_minutes($employee, $today);
        $deviation = $worked_minutes - $expected_minutes;

        // Pegar o horário da última batida para o contador em tempo real
        $last_punch_time = null;
        if (!empty($punches)) {
            $last_punch_time = end($punches)['punch_time'];
        }

        $response = array(
            'employee_id' => $employee_id,
            'date' => $today,
            'punch_count' => $punch_count,
            'worked_minutes' => round($worked_minutes),
            'worked_formatted' => $this->format_minutes(round($worked_minutes)),
            'expected_minutes' => $expected_minutes,
            'expected_formatted' => $this->format_minutes($expected_minutes),
            'deviation_minutes' => round($deviation),
            'deviation_formatted' => $this->format_minutes(round($deviation), true),
            'working_now' => $working_now, // Flag indicando se está trabalhando agora (entrada sem saída)
            'is_working' => in_array($punch_count, [1, 3]), // Está dentro do expediente
            'last_punch_time' => $last_punch_time // Para contador em tempo real no frontend
        );

        return new WP_REST_Response($response, 200);
    }

    /**
     * API: Obter previsão se sair agora
     * GET /wp-json/sistur/v1/time-bank/{employee_id}/prediction
     */
    public function api_get_prediction($request) {
        global $wpdb;

        $employee_id = intval($request['employee_id']);
        $today = current_time('Y-m-d');
        $now = current_time('timestamp');

        // Buscar batidas de hoje
        $table_entries = $wpdb->prefix . 'sistur_time_entries';
        $punches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_entries
             WHERE employee_id = %d AND shift_date = %s
             ORDER BY punch_time ASC",
            $employee_id, $today
        ), ARRAY_A);

        $punch_count = count($punches);

        // Buscar funcionário
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*
             FROM $table_employees e
             WHERE e.id = %d",
            $employee_id
        ));

        // Calcular carga horária esperada do funcionário baseado em shift pattern
        $expected_minutes = $this->get_expected_minutes($employee, $today);

        // ===== CALCULAR MINUTOS TRABALHADOS (APENAS PARES FECHADOS) =====
        $worked_minutes = 0;

        for ($i = 0; $i < $punch_count; $i += 2) {
            $entrada = isset($punches[$i]) ? $punches[$i] : null;
            $saida = isset($punches[$i + 1]) ? $punches[$i + 1] : null;

            if ($entrada && $saida) {
                $entrada_timestamp = strtotime($entrada['punch_time']);
                $saida_timestamp = strtotime($saida['punch_time']);
                $worked_minutes += ($saida_timestamp - $entrada_timestamp) / 60;
            }
        }

        // Cenários de previsão
        $predictions = array();

        if ($punch_count == 3) {
            // Voltou do almoço, calcular tempo para completar carga esperada
            $punch_1 = strtotime($punches[0]['punch_time']);
            $punch_2 = strtotime($punches[1]['punch_time']);
            $punch_3 = strtotime($punches[2]['punch_time']);

            $worked_morning = ($punch_2 - $punch_1) / 60;
            $remaining_needed = $expected_minutes - $worked_morning;

            $ideal_clock_out = $punch_3 + ($remaining_needed * 60);

            $predictions['if_leave_now'] = array(
                'total_worked' => round($worked_morning),
                'deviation' => round($worked_morning - $expected_minutes),
                'deviation_formatted' => $this->format_minutes(round($worked_morning - $expected_minutes), true)
            );

            $predictions['to_complete_expected'] = array(
                'clock_out_time' => date('H:i', $ideal_clock_out),
                'additional_minutes_needed' => round($remaining_needed)
            );
        } elseif ($punch_count == 1) {
            // Apenas entrou, calcular quando deve sair
            $punch_1 = strtotime($punches[0]['punch_time']);
            $lunch_duration = 60; // 1h padrão

            // Supondo que vai almoçar às 12h e voltar às 13h
            $predictions['suggestion'] = array(
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'clock_out' => date('H:i', $punch_1 + (($expected_minutes + $lunch_duration) * 60))
            );
        } elseif ($punch_count >= 2) {
            // Tem pares fechados, calcular quanto falta
            $remaining_needed = $expected_minutes - $worked_minutes;

            $predictions['if_leave_now'] = array(
                'total_worked' => round($worked_minutes),
                'deviation' => round($worked_minutes - $expected_minutes),
                'deviation_formatted' => $this->format_minutes(round($worked_minutes - $expected_minutes), true)
            );

            if ($remaining_needed > 0 && $punch_count % 2 == 1) {
                // Tem uma entrada sem saída, calcular quando sair
                $last_entry = strtotime($punches[$punch_count - 1]['punch_time']);
                $ideal_clock_out = $last_entry + ($remaining_needed * 60);

                $predictions['to_complete_expected'] = array(
                    'clock_out_time' => date('H:i', $ideal_clock_out),
                    'additional_minutes_needed' => round($remaining_needed)
                );
            }
        }

        $response = array(
            'employee_id' => $employee_id,
            'punch_count' => $punch_count,
            'worked_minutes' => round($worked_minutes),
            'predictions' => $predictions
        );

        return new WP_REST_Response($response, 200);
    }

    /**
     * Helper: Formatar minutos em horas (ex: 90 -> "1h30" ou "+1h30" com sinal)
     */
    public function format_minutes($minutes, $include_sign = false) {
        $is_negative = $minutes < 0;
        $abs_minutes = abs($minutes);

        $hours = floor($abs_minutes / 60);
        $mins = $abs_minutes % 60;

        $formatted = '';

        if ($hours > 0) {
            $formatted .= $hours . 'h';
        }

        if ($mins > 0 || $hours == 0) {
            if ($hours > 0) {
                $formatted .= sprintf('%02d', $mins);
            } else {
                $formatted .= $mins . 'min';
            }
        }

        if ($include_sign) {
            $sign = $is_negative ? '-' : '+';
            return $sign . $formatted;
        }

        return $formatted;
    }

    /**
     * Registra déficit semanal para escalas de horas flexíveis
     * 
     * Quando um funcionário com escala flexível trabalha menos que o esperado
     * na semana, o déficit é registrado no ledger do banco de horas.
     * 
     * @param int $employee_id ID do funcionário
     * @param string $week_start Data de início da semana (Y-m-d)
     * @param string $week_end Data de fim da semana (Y-m-d)
     * @param int $deficit_minutes Déficit em minutos (valor negativo)
     * @param int $worked_minutes Total trabalhado na semana
     * @param int $expected_minutes Total esperado na semana
     * @return bool
     */
    private function register_flexible_weekly_deficit($employee_id, $week_start, $week_end, $deficit_minutes, $worked_minutes, $expected_minutes) {
        global $wpdb;
        
        // Verificar se já existe registro de déficit para esta semana
        $table_ledger = $wpdb->prefix . 'sistur_timebank_ledger';
        
        // Verificar se a tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_ledger'") != $table_ledger) {
            // Se não existe tabela de ledger, registrar no sistur_time_days como ajuste
            return $this->register_deficit_in_time_days($employee_id, $week_end, $deficit_minutes);
        }
        
        // Checar se já foi registrado (evita duplicatas)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_ledger 
             WHERE employee_id = %d 
             AND transaction_type = 'flexible_weekly_deficit'
             AND reference_date = %s",
            $employee_id,
            $week_end
        ));
        
        if ($existing) {
            // Já existe registro para esta semana
            return true;
        }
        
        // Registrar déficit no ledger
        $result = $wpdb->insert(
            $table_ledger,
            array(
                'employee_id' => $employee_id,
                'transaction_type' => 'flexible_weekly_deficit',
                'minutes' => $deficit_minutes, // Valor negativo
                'reference_date' => $week_end,
                'description' => sprintf(
                    'Déficit semanal (escala flexível): %s a %s. Trabalhado: %s, Esperado: %s, Déficit: %s',
                    date('d/m', strtotime($week_start)),
                    date('d/m', strtotime($week_end)),
                    $this->format_minutes($worked_minutes),
                    $this->format_minutes($expected_minutes),
                    $this->format_minutes(abs($deficit_minutes))
                ),
                'created_at' => current_time('mysql'),
                'created_by' => 'system'
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        return $result !== false;
    }
    
    /**
     * Registra déficit no sistur_time_days (fallback se ledger não existir)
     */
    private function register_deficit_in_time_days($employee_id, $week_end, $deficit_minutes) {
        global $wpdb;
        
        $table_days = $wpdb->prefix . 'sistur_time_days';
        
        // Verificar se já existe registro para a sexta-feira da semana
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
            $employee_id, $week_end
        ), ARRAY_A);
        
        if ($existing) {
            // Atualizar o registro existente adicionando o déficit ao saldo
            $new_balance = intval($existing['saldo_final_minutos']) + $deficit_minutes;
            
            $wpdb->update(
                $table_days,
                array(
                    'saldo_final_minutos' => $new_balance,
                    'notes' => $existing['notes'] . "\n[SISTEMA] Déficit semanal flexível aplicado: " . $this->format_minutes($deficit_minutes, true),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing['id']),
                array('%d', '%s', '%s'),
                array('%d')
            );
        } else {
            // Criar novo registro
            $wpdb->insert(
                $table_days,
                array(
                    'employee_id' => $employee_id,
                    'shift_date' => $week_end,
                    'minutos_trabalhados' => 0,
                    'saldo_calculado_minutos' => $deficit_minutes,
                    'saldo_final_minutos' => $deficit_minutes,
                    'status' => 'present',
                    'needs_review' => 0,
                    'notes' => '[SISTEMA] Déficit semanal flexível: ' . $this->format_minutes($deficit_minutes, true),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s')
            );
        }
        
        return true;
    }

    /**
     * Helper: Calcular carga horária esperada do funcionário baseado em shift patterns
     *
     * @param object|null $employee Objeto do funcionário com dados do contrato
     * @param string|null $date Data para calcular as horas esperadas (formato Y-m-d)
     * @return int Minutos esperados de trabalho diário
     */
    private function get_expected_minutes($employee, $date = null) {
        // ============================================================
        // SISTEMA BASEADO EXCLUSIVAMENTE EM ESCALAS
        // Todos os funcionários DEVEM ter uma escala vinculada
        // Não há mais fallbacks para time_expected_minutes ou contract_type
        // ============================================================

        if ($date === null || !class_exists('SISTUR_Shift_Patterns')) {
            error_log("SISTUR AVISO: Processamento de ponto sem data ou classe de escalas indisponível");
            return 480; // Fallback mínimo apenas para evitar erro fatal
        }

        $shift_patterns = SISTUR_Shift_Patterns::get_instance();
        $expected_data = $shift_patterns->get_expected_hours_for_date($employee->id, $date);

        // Se retornou dados válidos
        if ($expected_data && !isset($expected_data['warning'])) {
            return intval($expected_data['expected_minutes']);
        }

        // ERRO CRÍTICO: Funcionário sem escala vinculada
        // Isso não deveria acontecer, pois a escala é obrigatória no cadastro
        error_log("SISTUR ERRO CRÍTICO: Funcionário ID {$employee->id} ({$employee->name}) não tem escala vinculada!");
        error_log("SISTUR: Execute a migração em /wp-admin/admin.php?page=sistur-migrate-shifts");

        // Retornar 480 minutos (8h) como último recurso para não travar o sistema
        // MAS marcar o dia para revisão
        return 480;
    }

    /**
     * Helper: Traduzir nome do dia para português
     */
    private function translate_day_name($day_name) {
        $days = array(
            'Monday' => 'Segunda-feira',
            'Tuesday' => 'Terça-feira',
            'Wednesday' => 'Quarta-feira',
            'Thursday' => 'Quinta-feira',
            'Friday' => 'Sexta-feira',
            'Saturday' => 'Sábado',
            'Sunday' => 'Domingo'
        );

        return isset($days[$day_name]) ? $days[$day_name] : $day_name;
    }

    /**
     * Permissão: Verificar se pode acessar timesheet
     */
    public function check_timesheet_permission($request) {
        // TODO: Implementar verificação de permissão
        // Por enquanto, permite todos autenticados
        return is_user_logged_in();
    }

    /**
     * Permissão: Verificar se pode acessar banco de horas
     */
    public function check_timebank_permission($request) {
        $requested_employee_id = intval($request['employee_id']);

        // Admin pode ver todos
        if (current_user_can('manage_options')) {
            return true;
        }

        // Funcionário pode ver apenas o próprio banco de horas
        if (function_exists('sistur_get_current_employee')) {
            $current_employee = sistur_get_current_employee();
            if ($current_employee && isset($current_employee['id']) && $current_employee['id'] == $requested_employee_id) {
                return true;
            }
        }

        // Negar acesso se não for admin nem o próprio funcionário
        return false;
    }

    /**
     * Permissão: Verificar se pode acessar saldo
     */
    public function check_balance_permission($request) {
        $requested_user_id = intval($request['user_id']);

        // Admin pode ver todos
        if (current_user_can('manage_options')) {
            return true;
        }

        // Funcionário pode ver apenas o próprio saldo
        if (function_exists('sistur_get_current_employee')) {
            $current_employee = sistur_get_current_employee();
            if ($current_employee && isset($current_employee['id']) && $current_employee['id'] == $requested_user_id) {
                return true;
            }
        }

        // Negar acesso se não for admin nem o próprio funcionário
        return false;
    }

    /**
     * Permissão: Verificar secret key do cron
     */
    public function check_cron_permission($request) {
        $key = $request->get_param('key');
        $secret = $this->get_setting('cron_secret_key');

        if (empty($secret)) {
            return true; // Se não tem secret configurado, permite (desenvolvimento)
        }

        return $key === $secret;
    }

    /**
     * Validar formato do token UUID v4
     *
     * @param string $token Token a ser validado
     * @return bool True se o formato é válido, false caso contrário
     */
    private function validate_token_format($token) {
        // Validar que é uma string
        if (!is_string($token)) {
            return false;
        }

        // Validar comprimento (UUID tem 36 caracteres com hífens)
        if (strlen($token) !== 36) {
            return false;
        }

        // Padrão UUID v4: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        // Onde y é um dos valores: 8, 9, a, b
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $token) === 1;
    }

    /**
     * Registrar tentativa falha de validação de token
     *
     * @param string $reason Motivo da falha
     * @param WP_REST_Request $request Objeto da requisição
     * @param string $token Token fornecido (opcional)
     */
    private function log_failed_token_attempt($reason, $request, $token = null) {
        global $wpdb;

        // Obter IP do cliente
        $ip_address = $this->get_client_ip();

        // Obter user agent
        $user_agent = $request->get_header('user_agent');
        if (empty($user_agent)) {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
        }

        // Preparar dados do log
        $log_data = array(
            'timestamp' => current_time('mysql'),
            'ip_address' => $ip_address,
            'user_agent' => substr($user_agent, 0, 255), // Limitar tamanho
            'reason' => $reason,
            'token_preview' => $token ? substr($token, 0, 8) . '...' : 'N/A' // Apenas preview para segurança
        );

        // Log no error_log do WordPress
        error_log(sprintf(
            'SISTUR Security: Failed token attempt - Reason: %s, IP: %s, Token: %s',
            $reason,
            $ip_address,
            $log_data['token_preview']
        ));

        // Opcional: Salvar em tabela de auditoria se existir
        $audit_table = $wpdb->prefix . 'sistur_security_log';

        // Verificar se a tabela existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'") === $audit_table;

        if ($table_exists) {
            $wpdb->insert(
                $audit_table,
                array(
                    'event_type' => 'failed_token_validation',
                    'ip_address' => $log_data['ip_address'],
                    'user_agent' => $log_data['user_agent'],
                    'details' => json_encode(array(
                        'reason' => $reason,
                        'token_preview' => $log_data['token_preview']
                    )),
                    'created_at' => $log_data['timestamp']
                ),
                array('%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Cria tabela de movimentações do banco de horas se não existir
     */
    private function create_ledger_table_if_needed() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sistur_banco_horas_movimentacoes';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                employee_id mediumint(9) NOT NULL,
                tipo_movimentacao enum('PAGAMENTO_DINHEIRO', 'FOLGA_COMPENSATORIA', 'AJUSTE_MANUAL') NOT NULL,
                minutos_debitados int NOT NULL DEFAULT 0,
                data_referencia date NOT NULL,
                observacoes text DEFAULT NULL,
                criado_por bigint(20) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY employee_id (employee_id),
                KEY data_referencia (data_referencia)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Helper: Obter total de débitos (pagamentos/folgas) do Ledger
     */
    private function get_ledger_debits($employee_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_banco_horas_movimentacoes';

        // Verificar se tabela existe (caso init não tenha rodado)
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return 0;
        }

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(minutos_debitados) FROM $table WHERE employee_id = %d",
            $employee_id
        ));

        return intval($total);
    }

    /**
     * API: Registrar Pagamento/Baixa no Banco de Horas
     * POST /wp-json/sistur/v1/time-bank/payment
     */
    public function api_register_payment($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_banco_horas_movimentacoes';

        $employee_id = intval($request->get_param('employee_id'));
        $tipo = $request->get_param('tipo'); // PAGAMENTO_DINHEIRO, FOLGA_COMPENSATORIA, AJUSTE_MANUAL
        $minutos = intval($request->get_param('minutos'));
        $data = $request->get_param('data') ? $request->get_param('data') : current_time('Y-m-d');
        $obs = $request->get_param('observacoes');

        if ($employee_id <= 0 || $minutos <= 0 || empty($tipo)) {
            return new WP_REST_Response(array('message' => 'Dados inválidos'), 400);
        }

        $result = $wpdb->insert(
            $table,
            array(
                'employee_id' => $employee_id,
                'tipo_movimentacao' => $tipo,
                'minutos_debitados' => $minutos,
                'data_referencia' => $data,
                'observacoes' => $obs,
                'criado_por' => get_current_user_id()
            ),
            array('%d', '%s', '%d', '%s', '%s', '%d')
        );

        if ($result) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Baixa registrada com sucesso!',
                'id' => $wpdb->insert_id
            ), 201);
        }

        return new WP_REST_Response(array('message' => 'Erro ao salvar'), 500);
    }

    /**
     * API: Obter Extrato do Ledger
     * GET /wp-json/sistur/v1/time-bank/{employee_id}/ledger
     */
    public function api_get_ledger($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_banco_horas_movimentacoes';
        $employee_id = intval($request['employee_id']);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d ORDER BY data_referencia DESC, created_at DESC",
            $employee_id
        ), ARRAY_A);

        // Formatar
        foreach ($results as &$row) {
            $row['minutos_formatados'] = $this->format_minutes($row['minutos_debitados']);
        }

        return new WP_REST_Response(array('ledger' => $results), 200);
    }

    /**
     * Obter endereço IP do cliente (com suporte a proxies)
     *
     * @return string Endereço IP do cliente
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Proxy/Load Balancer
            'HTTP_X_REAL_IP',        // Nginx proxy
            'REMOTE_ADDR'            // IP direto
        );

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                // Se for lista de IPs (X-Forwarded-For), pegar o primeiro
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validar formato IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * ========================================================================
     * REGENERAÇÃO HISTÓRICA (v2.2.0)
     * ========================================================================
     * 
     * Regenera as expectativas históricas para um intervalo de datas.
     * 
     * RESTRIÇÃO CRÍTICA DE SEGURANÇA:
     * Este método NUNCA toca nos registros brutos de batida (time_entries).
     * Os registros de batida são IMUTÁVEIS durante este processo.
     * Apenas expected_minutes_snapshot e saldo_final_minutos são recalculados.
     * 
     * @param int $employee_id ID do funcionário
     * @param string $start_date Data inicial (Y-m-d)
     * @param string $end_date Data final (Y-m-d)
     * @return array Resultados com dias regenerados e erros
     */
    public function regenerateHistory($employee_id, $start_date, $end_date) {
        global $wpdb;
        
        $table_days = $wpdb->prefix . 'sistur_time_days';
        
        if (!class_exists('SISTUR_Shift_Patterns')) {
            return array(
                'success' => false,
                'errors' => array('Classe SISTUR_Shift_Patterns não disponível'),
            );
        }
        
        $shift_patterns = SISTUR_Shift_Patterns::get_instance();
        
        $results = array(
            'success' => true,
            'days_processed' => 0,
            'days_updated' => 0,
            'days_skipped' => 0,
            'errors' => array(),
            'details' => array(),
        );
        
        // Gerar intervalo de datas
        try {
            $period = new DatePeriod(
                new DateTime($start_date),
                new DateInterval('P1D'),
                (new DateTime($end_date))->modify('+1 day')
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'errors' => array('Intervalo de datas inválido: ' . $e->getMessage()),
            );
        }
        
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($period as $date_obj) {
                $date = $date_obj->format('Y-m-d');
                $results['days_processed']++;
                
                // 1. Buscar registro existente do dia (se houver)
                $existing_day = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_days WHERE employee_id = %d AND shift_date = %s",
                    $employee_id, $date
                ));
                
                // Se não existe registro para este dia, pular
                if (!$existing_day) {
                    $results['days_skipped']++;
                    continue;
                }
                
                // 2. Obter escala ATUAL para esta data (escala nova/corrigida)
                $expected_data = $shift_patterns->get_expected_hours_for_date($employee_id, $date);
                $new_expected_minutes = intval($expected_data['expected_minutes']);
                $schedule = $shift_patterns->get_employee_active_schedule($employee_id, $date);
                $new_schedule_id = $schedule ? intval($schedule['id']) : null;
                
                // 3. Obter minutos trabalhados IMUTÁVEIS (do registro existente - NUNCA modificado)
                $worked_minutes = intval($existing_day->minutos_trabalhados);
                
                // 4. Recalcular saldo baseado em NOVA expectativa e minutos trabalhados EXISTENTES
                $new_saldo_bruto = $worked_minutes - $new_expected_minutes;
                $new_saldo_calculado = $this->apply_delay_tolerance($new_saldo_bruto);
                $manual_adjustment = intval($existing_day->bank_minutes_adjustment);
                $new_saldo_final = $new_saldo_calculado + $manual_adjustment;
                
                // 5. Atualizar APENAS os campos de snapshot e saldo derivados
                $old_expected = isset($existing_day->expected_minutes_snapshot) 
                    ? intval($existing_day->expected_minutes_snapshot) 
                    : null;
                
                $update_result = $wpdb->update(
                    $table_days,
                    array(
                        'expected_minutes_snapshot' => $new_expected_minutes,
                        'schedule_id_snapshot' => $new_schedule_id,
                        'saldo_calculado_minutos' => $new_saldo_calculado,
                        'saldo_final_minutos' => $new_saldo_final,
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => $existing_day->id),
                    array('%d', '%d', '%d', '%d', '%s'),
                    array('%d')
                );
                
                if ($update_result !== false) {
                    $results['days_updated']++;
                } else {
                    $results['errors'][] = "Erro ao atualizar dia $date";
                }
                
                $results['details'][] = array(
                    'date' => $date,
                    'old_expected' => $old_expected,
                    'new_expected' => $new_expected_minutes,
                    'worked_minutes' => $worked_minutes,
                    'old_balance' => intval($existing_day->saldo_final_minutos),
                    'new_balance' => $new_saldo_final,
                    'schedule_id' => $new_schedule_id,
                );
            }
            
            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $results['success'] = false;
            $results['errors'][] = 'Erro durante regeneração: ' . $e->getMessage();
        }
        
        // Log para auditoria
        error_log(sprintf(
            "SISTUR: regenerateHistory executado - Employee ID: %d, Período: %s a %s, Dias atualizados: %d/%d",
            $employee_id, $start_date, $end_date, $results['days_updated'], $results['days_processed']
        ));
        
        return $results;
    }
}
