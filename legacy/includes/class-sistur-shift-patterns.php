<?php
/**
 * Classe para gerenciar padrões de escala e escalas de funcionários
 *
 * @package SISTUR
 */

class SISTUR_Shift_Patterns {

    /**
     * ========================================================================
     * SCALE MODE CONSTANTS (v2.2.0)
     * Define os 3 tipos de contrato estritamente suportados
     * ========================================================================
     */
    
    /** CLT Padrão: Dias fixos da semana, horários fixos de entrada/saída. Ausência gera débito. */
    const TYPE_FIXED_DAYS = 'TYPE_FIXED_DAYS';
    
    /** CLT Horista: Horas flexíveis ou carga variável. Balanço por horas acumuladas vs meta mensal. */
    const TYPE_HOURLY = 'TYPE_HOURLY';
    
    /** Diarista: Pagamento/controle por dia trabalhado. Foco em presença. */
    const TYPE_DAILY = 'TYPE_DAILY';

    /**
     * Instância singleton
     */
    private static $instance = null;

    /**
     * Obtém a instância singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Construtor
     */
    public function __construct() {
        // Registrar AJAX handlers
        add_action('wp_ajax_sistur_get_shift_patterns', array($this, 'ajax_get_shift_patterns'));
        add_action('wp_ajax_sistur_get_shift_pattern', array($this, 'ajax_get_shift_pattern'));
        add_action('wp_ajax_sistur_save_shift_pattern', array($this, 'ajax_save_shift_pattern'));
        add_action('wp_ajax_sistur_delete_shift_pattern', array($this, 'ajax_delete_shift_pattern'));
        add_action('wp_ajax_sistur_get_employees_by_pattern', array($this, 'ajax_get_employees_by_pattern'));

        add_action('wp_ajax_sistur_get_employee_schedule', array($this, 'ajax_get_employee_schedule'));
        add_action('wp_ajax_sistur_save_employee_schedule', array($this, 'ajax_save_employee_schedule'));
        add_action('wp_ajax_sistur_delete_employee_schedule', array($this, 'ajax_delete_employee_schedule'));
        add_action('wp_ajax_sistur_get_employee_active_schedule', array($this, 'ajax_get_employee_active_schedule'));
    }

    /**
     * Retorna os tipos de modo de escala disponíveis com descrições
     * 
     * @return array Modos de escala com labels e configurações
     */
    public static function get_scale_modes() {
        return array(
            self::TYPE_FIXED_DAYS => array(
                'label' => 'CLT Padrão (Dias Fixos)',
                'description' => 'Dias e horários fixos. Ausência gera débito no banco.',
                'calculates_absence_debit' => true,
                'requires_punch' => true,
            ),
            self::TYPE_HOURLY => array(
                'label' => 'CLT Horista',
                'description' => 'Horas flexíveis ou carga variável. Balanço por meta mensal.',
                'calculates_absence_debit' => false,
                'requires_punch' => true,
            ),
            self::TYPE_DAILY => array(
                'label' => 'Diarista',
                'description' => 'Pagamento/controle por dia trabalhado. Foco em presença.',
                'calculates_absence_debit' => false,
                'requires_punch' => false,
            ),
        );
    }

    /**
     * Converte janela de tempo (início/fim H:i) para total de minutos esperados
     * 
     * @param string $start_time Horário de início formato H:i (ex: "08:00")
     * @param string $end_time Horário de fim formato H:i (ex: "17:00")
     * @param int $lunch_minutes Duração do almoço em minutos
     * @return int Total de minutos de trabalho esperados
     */
    public function calculate_minutes_from_time_window($start_time, $end_time, $lunch_minutes = 0) {
        $start = DateTime::createFromFormat('H:i', $start_time);
        $end = DateTime::createFromFormat('H:i', $end_time);
        
        if (!$start || !$end) {
            // Fallback para 8 horas se horários inválidos
            return 480;
        }
        
        $diff_minutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        
        // Trata turnos noturnos (fim antes do início)
        if ($diff_minutes < 0) {
            $diff_minutes += 1440; // Adiciona 24 horas
        }
        
        return max(0, intval($diff_minutes - $lunch_minutes));
    }

    /**
     * Obtém todos os padrões de escala
     */
    public function get_all_shift_patterns($status = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_shift_patterns';

        $where = '';
        if ($status !== null) {
            $where = $wpdb->prepare(' WHERE status = %d', $status);
        }

        return $wpdb->get_results(
            "SELECT * FROM $table{$where} ORDER BY name ASC",
            ARRAY_A
        );
    }

    /**
     * Obtém um padrão de escala por ID
     */
    public function get_shift_pattern($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_shift_patterns';

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );
    }

    /**
     * Salva um padrão de escala (criar ou atualizar)
     */
    public function save_shift_pattern($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_shift_patterns';

        $pattern_data = array(
            'name' => sanitize_text_field($data['name']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'pattern_type' => isset($data['pattern_type']) ? sanitize_text_field($data['pattern_type']) : 'fixed_days',
            'scale_mode' => isset($data['scale_mode']) ? sanitize_text_field($data['scale_mode']) : self::TYPE_FIXED_DAYS,
            'work_days_count' => isset($data['work_days_count']) ? intval($data['work_days_count']) : 0,
            'rest_days_count' => isset($data['rest_days_count']) ? intval($data['rest_days_count']) : 0,
            'weekly_hours_minutes' => isset($data['weekly_hours_minutes']) ? intval($data['weekly_hours_minutes']) : 0,
            'daily_hours_minutes' => isset($data['daily_hours_minutes']) ? intval($data['daily_hours_minutes']) : 0,
            'lunch_break_minutes' => isset($data['lunch_break_minutes']) ? intval($data['lunch_break_minutes']) : 0,
            'pattern_config' => isset($data['pattern_config']) ? $data['pattern_config'] : null,
            'status' => isset($data['status']) ? intval($data['status']) : 1
        );

        if (isset($data['id']) && !empty($data['id'])) {
            // Atualizar
            $wpdb->update(
                $table,
                $pattern_data,
                array('id' => intval($data['id'])),
                array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d'),
                array('%d')
            );
            return intval($data['id']);
        } else {
            // Criar novo
            $wpdb->insert(
                $table,
                $pattern_data,
                array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d')
            );
            return $wpdb->insert_id;
        }
    }

    /**
     * Exclui um padrão de escala
     */
    public function delete_shift_pattern($id) {
        global $wpdb;
        $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';
        $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';
        $table_employees = $wpdb->prefix . 'sistur_employees';

        // Verificar se o padrão está em uso POR FUNCIONÁRIOS ATIVOS QUE EXISTEM
        // Usar INNER JOIN para ignorar schedules de funcionários deletados (fantasmas)
        $in_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.employee_id) 
             FROM $table_schedules s
             INNER JOIN $table_employees e ON s.employee_id = e.id
             WHERE s.shift_pattern_id = %d 
             AND s.is_active = 1
             AND e.status = 1",
            $id
        ));

        if ($in_use > 0) {
            return new WP_Error('in_use', 'Este padrão de escala está sendo usado por funcionários e não pode ser excluído.');
        }

        return $wpdb->delete($table_patterns, array('id' => $id), array('%d'));
    }

    /**
     * Obtém a escala ativa de um funcionário em uma data específica
     */
    public function get_employee_active_schedule($employee_id, $date = null) {
        global $wpdb;
        $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';
        $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';

        if ($date === null) {
            $date = current_time('Y-m-d');
        }

        $query = "SELECT s.*, p.name as pattern_name, p.description as pattern_description,
                         p.pattern_type, p.work_days_count, p.rest_days_count,
                         p.weekly_hours_minutes, p.daily_hours_minutes, p.lunch_break_minutes,
                         p.pattern_config
                  FROM $table_schedules s
                  INNER JOIN $table_patterns p ON s.shift_pattern_id = p.id
                  WHERE s.employee_id = %d
                  AND s.is_active = 1
                  AND s.start_date <= %s
                  AND (s.end_date IS NULL OR s.end_date >= %s)
                  ORDER BY s.start_date DESC
                  LIMIT 1";

        return $wpdb->get_row(
            $wpdb->prepare($query, $employee_id, $date, $date),
            ARRAY_A
        );
    }

    /**
     * Obtém todas as escalas de um funcionário
     */
    public function get_employee_schedules($employee_id) {
        global $wpdb;
        $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';
        $table_patterns = $wpdb->prefix . 'sistur_shift_patterns';

        $query = "SELECT s.*, p.name as pattern_name, p.description as pattern_description
                  FROM $table_schedules s
                  INNER JOIN $table_patterns p ON s.shift_pattern_id = p.id
                  WHERE s.employee_id = %d
                  ORDER BY s.start_date DESC";

        return $wpdb->get_results(
            $wpdb->prepare($query, $employee_id),
            ARRAY_A
        );
    }

    /**
     * Salva uma escala de funcionário
     */
    public function save_employee_schedule($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employee_schedules';

        $schedule_data = array(
            'employee_id' => intval($data['employee_id']),
            'shift_pattern_id' => intval($data['shift_pattern_id']),
            'start_date' => sanitize_text_field($data['start_date']),
            'end_date' => !empty($data['end_date']) ? sanitize_text_field($data['end_date']) : null,
            'shift_start_time' => !empty($data['shift_start_time']) ? sanitize_text_field($data['shift_start_time']) : null,
            'shift_end_time' => !empty($data['shift_end_time']) ? sanitize_text_field($data['shift_end_time']) : null,
            'lunch_duration_minutes' => isset($data['lunch_duration_minutes']) && $data['lunch_duration_minutes'] !== '' ? intval($data['lunch_duration_minutes']) : null,
            // active_days agora pode ser um JSON string (configuração completa) ou int (compatibilidade)
            'active_days' => isset($data['active_days']) ? $data['active_days'] : null,
            'is_active' => isset($data['is_active']) ? intval($data['is_active']) : 1,
            'notes' => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : null
        );

        // Sanitize active_days se for JSON
        if ($schedule_data['active_days'] && !is_numeric($schedule_data['active_days'])) {
             // Validar se é JSON válido, senão limpar
             json_decode($schedule_data['active_days']);
             if (json_last_error() !== JSON_ERROR_NONE) {
                 error_log('SISTUR DEBUG: active_days JSON inválido, limpando');
                 $schedule_data['active_days'] = null;
             } else {
                 error_log('SISTUR DEBUG: active_days JSON válido: ' . $schedule_data['active_days']);
             }
        } else if ($schedule_data['active_days']) {
            error_log('SISTUR DEBUG: active_days é numérico, convertendo para int: ' . $schedule_data['active_days']);
            $schedule_data['active_days'] = intval($schedule_data['active_days']);
        } else {
            error_log('SISTUR DEBUG: active_days está vazio/null');
        }

        // Determinar formato para active_days dinamicamente
        $active_days_format = is_string($schedule_data['active_days']) ? '%s' : '%d';
        error_log('SISTUR DEBUG: Formato determinado para active_days: ' . $active_days_format);
        error_log('SISTUR DEBUG: Valor final de active_days a ser salvo: ' . var_export($schedule_data['active_days'], true));
        
        // Formato dos campos: employee_id, shift_pattern_id, start_date, end_date, shift_start_time, shift_end_time, lunch_duration_minutes, active_days, is_active, notes
        $format = array('%d', '%d', '%s', '%s', '%s', '%s', '%d', $active_days_format, '%d', '%s');

        if (isset($data['id']) && !empty($data['id'])) {
            // Atualizar
            $wpdb->update(
                $table,
                $schedule_data,
                array('id' => intval($data['id'])),
                $format,
                array('%d')
            );
            return intval($data['id']);
        } else {
            // Desativar escalas antigas do funcionário se a nova escala for ativa
            if ($schedule_data['is_active'] == 1) {
                $wpdb->update(
                    $table,
                    array('is_active' => 0),
                    array('employee_id' => $schedule_data['employee_id']),
                    array('%d'),
                    array('%d')
                );
            }

            // Criar nova
            $wpdb->insert(
                $table,
                $schedule_data,
                $format
            );
            return $wpdb->insert_id;
        }
    }

    /**
     * Exclui uma escala de funcionário
     */
    public function delete_employee_schedule($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sistur_employee_schedules';

        return $wpdb->delete($table, array('id' => $id), array('%d'));
    }

    /**
     * Calcula as horas esperadas para um funcionário em uma data específica
     * baseado no padrão de escala ativo
     */
    public function get_expected_hours_for_date($employee_id, $date) {
        global $wpdb;
        
        // DEBUG: Iniciar log de cálculo
        error_log("=== SISTUR DEBUG: get_expected_hours_for_date ===");
        error_log("SISTUR DEBUG: employee_id=$employee_id, date=$date");
        
        // PRIORIDADE 0: Verificar se é FERIADO (global para todos os funcionários)
        $holidays_table = $wpdb->prefix . 'sistur_holidays';
        $holiday = $wpdb->get_row($wpdb->prepare(
            "SELECT id, description, holiday_type FROM $holidays_table WHERE holiday_date = %s AND status = 'active'",
            $date
        ));
        
        if ($holiday) {
            error_log("SISTUR DEBUG: É FERIADO: {$holiday->description}");
            // Feriado encontrado - expectativa é 0 (qualquer trabalho = hora extra)
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_holiday' => true,
                'is_rest_day' => true,
                'holiday_name' => $holiday->description,
                'holiday_type' => $holiday->holiday_type
            );
        }

        // PRIORIDADE 1: Verificar se existe exceção para este dia (afastamentos, etc.)
        if (class_exists('SISTUR_Schedule_Exceptions')) {
            $exceptions = SISTUR_Schedule_Exceptions::get_instance();
            $exception = $exceptions->get_exception_for_date($employee_id, $date);

            if ($exception) {
                error_log("SISTUR DEBUG: EXCEÇÃO encontrada: {$exception->exception_type}, expected_minutes={$exception->custom_expected_minutes}");
                // Exceção encontrada - usar expectativa customizada
                return array(
                    'expected_minutes' => intval($exception->custom_expected_minutes),
                    'lunch_minutes' => 0,
                    'is_exception' => true,
                    'exception_type' => $exception->exception_type,
                    'exception_notes' => $exception->notes
                );
            }
        }

        // PRIORIDADE 2: Verificar escala de trabalho configurada
        $schedule = $this->get_employee_active_schedule($employee_id, $date);

        if (!$schedule) {
            error_log("SISTUR DEBUG: SEM ESCALA! Usando fallback 480min (8h)");
            // Se não tem escala ativa, retornar padrão CLT
            // IMPORTANTE: Funcionário deve ter uma escala configurada para cálculo correto
            return array(
                'expected_minutes' => 480,
                'lunch_minutes' => 60,
                'warning' => 'Funcionário sem escala de trabalho configurada'
            );
        }

        // DEBUG: Detalhes da escala
        error_log("SISTUR DEBUG: Escala encontrada:");
        error_log("  - pattern_name: {$schedule['pattern_name']}");
        error_log("  - pattern_type: {$schedule['pattern_type']}");
        error_log("  - work_days_count: {$schedule['work_days_count']}");
        error_log("  - rest_days_count: {$schedule['rest_days_count']}");
        error_log("  - daily_hours_minutes: {$schedule['daily_hours_minutes']}");
        error_log("  - weekly_hours_minutes: {$schedule['weekly_hours_minutes']}");
        error_log("  - start_date: {$schedule['start_date']}");
        error_log("  - pattern_config: {$schedule['pattern_config']}");

        // Decodificar pattern_config DO PADRÃO para suporte a configurações avançadas
    $pattern_config = json_decode($schedule['pattern_config'], true);
    
    // OVERRIDE: Priorizar configuração específica do FUNCIONÁRIO (active_days)
    // Se o funcionário tem configuração manual de dias, usamos isso como um pattern WEEKLY
    if (isset($schedule['active_days']) && !empty($schedule['active_days'])) {
        $employee_days_config = json_decode($schedule['active_days'], true);
        if ($employee_days_config && is_array($employee_days_config)) {
            error_log("SISTUR DEBUG: Usando configuração manual do funcionário (active_days)");
            $pattern_config = array(
                'pattern_type' => 'weekly',
                'weekly' => $employee_days_config
            );
            // Também podemos forçar o tipo para evitar que caia em lógica de flexível se quiser
            // Mas flexível tem prioridade acima checknado $schedule['pattern_type'] diretamente
        }
    }

    if ($pattern_config) {
        error_log("SISTUR DEBUG: pattern_config efetivo:String length: " . strlen(print_r($pattern_config, true)));
    } else {
        error_log("SISTUR DEBUG: pattern_config é NULL ou inválido");
    }

    // Verificar se é um padrão flexível (SOMENTE SE NÃO TIVER OVERRIDE DO FUNCIONÁRIO)
    // Se o funcionário definiu dias específicos, assumimos que ele quer usar essa definição fixa,
    // mesmo que a escala original fosse "flexível".
    // PORÉM, se a escala for "Horista" (ScaleMode=HOURLY), geralmente não tem dias fixos.
    // Vamos assumir que se active_days existe, ele manda.
    
    $has_employee_override = (isset($schedule['active_days']) && !empty($schedule['active_days']));
    
    if ($schedule['pattern_type'] === 'flexible_hours' && !$has_employee_override) {
        error_log("SISTUR DEBUG: É escala FLEXÍVEL, retornando expected_minutes=0");
            // Para escalas flexíveis, a expectativa diária é zero ou a média semanal dividida por 7
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => intval($schedule['lunch_break_minutes']),
                'is_flexible' => true,
                'weekly_hours_required' => intval($schedule['weekly_hours_minutes'])
            );
        }

        // Para escalas fixas, calcular dia de trabalho baseado em pattern_config
        $expected_data = $this->calculate_expected_from_pattern($date, $schedule['start_date'], $pattern_config, $schedule);
        
        error_log("SISTUR DEBUG: Resultado final: expected_minutes={$expected_data['expected_minutes']}, is_work_day=" . (isset($expected_data['is_work_day']) ? ($expected_data['is_work_day'] ? 'true' : 'false') : 'N/A'));
        error_log("=== FIM DEBUG get_expected_hours_for_date ===");

        return $expected_data;
    }

    /**
     * Calcula expectativa de horas baseado no pattern_config
     * Suporta padrões simples e complexos (12x36, plantões, múltiplos turnos)
     */
    private function calculate_expected_from_pattern($date, $start_date, $pattern_config, $schedule) {
        error_log("SISTUR DEBUG: calculate_expected_from_pattern - date=$date, start_date=$start_date");
        
        // PRIORIDADE 1: Pattern semanal (weekly) - horas diferentes por dia da semana
        // Exemplo: Segunda a Sexta 8h, Sábado 4h, Domingo folga = 44h semanais
        if (isset($pattern_config['pattern_type']) && $pattern_config['pattern_type'] === 'weekly') {
            error_log("SISTUR DEBUG: Usando pattern WEEKLY");
            return $this->calculate_from_weekly_pattern($date, $pattern_config, $schedule);
        }

        // PRIORIDADE 2: Pattern de ciclo complexo (cycle.sequence)
        // Exemplo: 12x36, plantões irregulares
        if (isset($pattern_config['cycle']) && isset($pattern_config['cycle']['sequence'])) {
            error_log("SISTUR DEBUG: Usando pattern CYCLE SEQUENCE");
            return $this->calculate_from_cycle_sequence($date, $start_date, $pattern_config, $schedule);
        }

        // PRIORIDADE 3: Pattern tradicional (6x1, 5x2)
        // Baseado em work_days_count e rest_days_count
        error_log("SISTUR DEBUG: Usando pattern TRADICIONAL (work_days/rest_days)");
        error_log("SISTUR DEBUG: work_days_count={$schedule['work_days_count']}, rest_days_count={$schedule['rest_days_count']}");
        
        $is_work_day = $this->is_work_day($date, $start_date, $pattern_config, $schedule);
        error_log("SISTUR DEBUG: is_work_day=" . ($is_work_day ? 'true' : 'false'));

        if ($is_work_day) {
            error_log("SISTUR DEBUG: É dia de trabalho, expected_minutes={$schedule['daily_hours_minutes']}");
            return array(
                'expected_minutes' => intval($schedule['daily_hours_minutes']),
                'lunch_minutes' => intval($schedule['lunch_break_minutes']),
                'is_work_day' => true
            );
        } else {
            error_log("SISTUR DEBUG: É dia de FOLGA, expected_minutes=0");
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_work_day' => false,
                'is_rest_day' => true
            );
        }
    }

    /**
     * Calcula expectativa baseado em padrão semanal
     * Permite horas diferentes por dia da semana (ex: 5x8h + 1x4h = 44h)
     *
     * @param string $date Data no formato Y-m-d
     * @param array $pattern_config Configuração do padrão semanal
     * @param array $schedule Dados da escala
     * @return array Expectativa de horas para o dia
     */
    private function calculate_from_weekly_pattern($date, $pattern_config, $schedule) {
        // Obter dia da semana em inglês (Monday, Tuesday, etc.)
        $day_of_week = date('l', strtotime($date));

        // Buscar configuração do dia da semana
        if (!isset($pattern_config['weekly']) || !isset($pattern_config['weekly'][$day_of_week])) {
            // Dia não configurado, assumir folga
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_work_day' => false,
                'is_rest_day' => true
            );
        }

        $day_config = $pattern_config['weekly'][$day_of_week];

        // Verificar se é dia de folga
        if ($day_config['type'] === 'rest') {
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_work_day' => false,
                'is_rest_day' => true
            );
        }

        // Dia de trabalho - retornar expectativa configurada
        return array(
            'expected_minutes' => intval($day_config['expected_minutes']),
            'lunch_minutes' => isset($day_config['lunch_minutes']) ? intval($day_config['lunch_minutes']) : intval($schedule['lunch_break_minutes']),
            'is_work_day' => true,
            'pattern_type' => 'weekly',
            'day_of_week' => $day_of_week
        );
    }

    /**
     * Calcula expectativa baseado em sequência de ciclo complexa
     * Suporta 12x36, plantões irregulares, múltiplos turnos por dia
     */
    private function calculate_from_cycle_sequence($date, $start_date, $pattern_config, $schedule) {
        $date_obj = new DateTime($date);
        $start_obj = new DateTime($start_date);
        $days_diff = $date_obj->diff($start_obj)->days;

        $cycle_length = intval($pattern_config['cycle']['length_days']);
        $sequence = $pattern_config['cycle']['sequence'];

        if ($cycle_length == 0) {
            return array('expected_minutes' => 0, 'lunch_minutes' => 0);
        }

        // Posição no ciclo (0-indexed)
        $position_in_cycle = $days_diff % $cycle_length;

        // Encontrar configuração do dia no ciclo
        $day_config = null;
        foreach ($sequence as $seq) {
            if (isset($seq['day']) && ($seq['day'] - 1) == $position_in_cycle) {
                $day_config = $seq;
                break;
            }
        }

        if (!$day_config) {
            // Dia não configurado no ciclo, assumir folga
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_rest_day' => true
            );
        }

        // Verificar tipo do dia
        if ($day_config['type'] === 'rest') {
            return array(
                'expected_minutes' => 0,
                'lunch_minutes' => 0,
                'is_rest_day' => true
            );
        }

        // Dia de trabalho - calcular total de minutos esperados
        $total_expected = 0;
        $total_lunch = 0;

        if (isset($day_config['shifts']) && is_array($day_config['shifts'])) {
            foreach ($day_config['shifts'] as $shift) {
                if (isset($shift['expected_minutes'])) {
                    $total_expected += intval($shift['expected_minutes']);
                }
                if (isset($shift['lunch_minutes'])) {
                    $total_lunch += intval($shift['lunch_minutes']);
                }
            }
        } elseif (isset($day_config['expected_minutes'])) {
            // Configuração simplificada (apenas expected_minutes no dia)
            $total_expected = intval($day_config['expected_minutes']);
        }

        return array(
            'expected_minutes' => $total_expected,
            'lunch_minutes' => $total_lunch,
            'is_work_day' => true,
            'pattern_type' => 'cycle_based'
        );
    }

    /**
     * Verifica se uma data é dia de trabalho baseado no padrão de escala
     */
    private function is_work_day($date, $start_date, $pattern_config, $schedule) {
        $date_obj = new DateTime($date);
        $start_obj = new DateTime($start_date);
        $days_diff = $date_obj->diff($start_obj)->days;

        $work_days = intval($schedule['work_days_count']);
        $rest_days = intval($schedule['rest_days_count']);
        $cycle_length = $work_days + $rest_days;

        error_log("SISTUR DEBUG: is_work_day calculation:");
        error_log("  - date: $date");
        error_log("  - start_date: $start_date");
        error_log("  - days_diff: $days_diff");
        error_log("  - work_days: $work_days, rest_days: $rest_days");
        error_log("  - cycle_length: $cycle_length");

        if ($cycle_length == 0) {
            error_log("  - cycle_length=0, retornando true (escala flexível)");
            return true; // Escala flexível
        }

        $position_in_cycle = $days_diff % $cycle_length;
        $is_work = $position_in_cycle < $work_days;
        
        error_log("  - position_in_cycle: $position_in_cycle");
        error_log("  - position < work_days? $position_in_cycle < $work_days = " . ($is_work ? 'true (trabalho)' : 'false (folga)'));

        return $is_work;
    }

    // ========== AJAX Handlers ==========

    /**
     * Verifica nonce aceitando múltiplos tipos para compatibilidade
     */
    private function verify_nonce() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['nonce']) ? $_GET['nonce'] : '');
        $nonce_valid = wp_verify_nonce($nonce, 'sistur_nonce') 
                || wp_verify_nonce($nonce, 'sistur_employees_nonce')
                || wp_verify_nonce($nonce, 'sistur_time_tracking_nonce');

        if (!$nonce_valid) {
            wp_send_json_error('Falha na verificação de segurança');
            exit;
        }
    }

    /**
     * AJAX: Obter todos os padrões de escala
     */
    public function ajax_get_shift_patterns() {
        $this->verify_nonce();

        $status = isset($_POST['status']) ? intval($_POST['status']) : null;
        $patterns = $this->get_all_shift_patterns($status);

        wp_send_json_success($patterns);
    }

    /**
     * AJAX: Obter um padrão de escala específico
     */
    public function ajax_get_shift_pattern() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID não fornecido');
        }

        $pattern = $this->get_shift_pattern(intval($_POST['id']));

        if ($pattern) {
            wp_send_json_success($pattern);
        } else {
            wp_send_json_error('Padrão de escala não encontrado');
        }
    }

    /**
     * AJAX: Salvar padrão de escala
     */
    public function ajax_save_shift_pattern() {
        $this->verify_nonce();

        if (!isset($_POST['name']) || empty($_POST['name'])) {
            wp_send_json_error('Nome é obrigatório');
        }

        $result = $this->save_shift_pattern($_POST);

        if ($result) {
            wp_send_json_success(array('id' => $result));
        } else {
            global $wpdb;
            $error = $wpdb->last_error ? $wpdb->last_error : 'Erro ao salvar padrão de escala';
            error_log("SISTUR: Erro ao salvar padrão de escala: " . $error);
            error_log("SISTUR: Dados recebidos: " . print_r($_POST, true));
            wp_send_json_error($error);
        }
    }

    /**
     * AJAX: Excluir padrão de escala
     */
    public function ajax_delete_shift_pattern() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID não fornecido');
        }

        $result = $this->delete_shift_pattern(intval($_POST['id']));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } elseif ($result) {
            wp_send_json_success('Padrão de escala excluído com sucesso');
        } else {
            wp_send_json_error('Erro ao excluir padrão de escala');
        }
    }

    /**
     * AJAX: Obter escala de funcionário
     */
    public function ajax_get_employee_schedule() {
        $this->verify_nonce();

        if (!isset($_POST['employee_id'])) {
            wp_send_json_error('ID do funcionário não fornecido');
        }

        $schedules = $this->get_employee_schedules(intval($_POST['employee_id']));
        wp_send_json_success($schedules);
    }

    /**
     * AJAX: Obter escala ativa de funcionário
     */
    public function ajax_get_employee_active_schedule() {
        $this->verify_nonce();

        if (!isset($_POST['employee_id'])) {
            wp_send_json_error('ID do funcionário não fornecido');
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : null;
        $schedule = $this->get_employee_active_schedule(intval($_POST['employee_id']), $date);

        if ($schedule) {
            wp_send_json_success($schedule);
        } else {
            wp_send_json_success(null);
        }
    }

    /**
     * AJAX: Salvar escala de funcionário
     */
    public function ajax_save_employee_schedule() {
        $this->verify_nonce();

        if (!isset($_POST['employee_id']) || !isset($_POST['shift_pattern_id']) || !isset($_POST['start_date'])) {
            wp_send_json_error('Dados obrigatórios não fornecidos');
        }

        $result = $this->save_employee_schedule($_POST);

        if ($result) {
            wp_send_json_success(array('id' => $result));
        } else {
            wp_send_json_error('Erro ao salvar escala do funcionário');
        }
    }

    /**
     * AJAX: Excluir escala de funcionário
     */
    public function ajax_delete_employee_schedule() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID não fornecido');
        }

        $result = $this->delete_employee_schedule(intval($_POST['id']));

        if ($result) {
            wp_send_json_success('Escala excluída com sucesso');
        } else {
            wp_send_json_error('Erro ao excluir escala');
        }
    }
    /**
     * AJAX: Buscar funcionários vinculados a um padrão de escala
     */
    public function ajax_get_employees_by_pattern() {
        $this->verify_nonce();

        if (!isset($_POST['id'])) {
            wp_send_json_error('ID é obrigatório');
        }

        $employees = $this->get_employees_by_shift_pattern(intval($_POST['id']));
        
        wp_send_json_success($employees);
    }

    /**
     * Obtém funcionários vinculados a um padrão de escala
     */
    public function get_employees_by_shift_pattern($pattern_id) {
        global $wpdb;
        $table_schedules = $wpdb->prefix . 'sistur_employee_schedules';
        $table_employees = $wpdb->prefix . 'sistur_employees';

        // Buscar funcionários com este padrão de escala ATIVO (tabela employees ou users?)
        // SISTUR usa sistur_employees (Custom Table)
        // Filtrar apenas funcionários ATIVOS (status = 1) para evitar fantasmas
        $query = "SELECT DISTINCT e.id, e.name, e.position 
                  FROM $table_employees e
                  INNER JOIN $table_schedules s ON e.id = s.employee_id
                  WHERE s.shift_pattern_id = %d 
                  AND s.is_active = 1
                  AND e.status = 1
                  ORDER BY e.name ASC";

        return $wpdb->get_results($wpdb->prepare($query, $pattern_id), ARRAY_A);
    }
}

// Inicializar a classe
SISTUR_Shift_Patterns::get_instance();
