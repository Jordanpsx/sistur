# DOCUMENTAÇÃO COMPLETA: SISTEMA DE ABATIMENTO DE BANCO DE HORAS

## VISÃO GERAL

Sistema completo para gerenciar abatimento de banco de horas com duas modalidades:
1. **Compensação em Folga** (Time Tracking)
2. **Pagamento em Dinheiro** (Payments)

---

## 1. MODELAGEM DE BANCO DE DADOS

### 1.1 Tabela: `wp_sistur_holidays` (Cadastro de Feriados)

```sql
CREATE TABLE wp_sistur_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    holiday_type ENUM('nacional', 'estadual', 'municipal', 'ponto_facultativo') DEFAULT 'nacional',
    multiplicador_adicional DECIMAL(4,2) DEFAULT 1.00,
    -- Multiplicador para horas trabalhadas (ex: 1.00 = 100%, 1.50 = 150%)
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    UNIQUE KEY unique_holiday_date (holiday_date),
    INDEX idx_holiday_date (holiday_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Campos:**
- `holiday_date`: Data do feriado
- `description`: Nome do feriado (ex: "Natal", "Independência do Brasil")
- `holiday_type`: Tipo do feriado (nacional, estadual, etc.)
- `multiplicador_adicional`: Multiplicador para calcular valor da hora extra (100% = 1.00, 150% = 1.50)
- `status`: Se o feriado está ativo
- `created_by`: WordPress user ID que criou

---

### 1.2 Tabela: `wp_sistur_timebank_deductions` (Registro de Abatimentos)

```sql
CREATE TABLE wp_sistur_timebank_deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    deduction_type ENUM('folga', 'pagamento') NOT NULL,
    -- Tipo de abatimento: folga (compensação) ou pagamento (dinheiro)

    minutes_deducted INT NOT NULL,
    -- Quantidade de minutos abatidos do banco de horas

    balance_before_minutes INT NOT NULL,
    -- Saldo do banco de horas ANTES do abatimento (para auditoria)

    balance_after_minutes INT NOT NULL,
    -- Saldo do banco de horas APÓS o abatimento

    -- CAMPOS ESPECÍFICOS PARA FOLGA
    time_off_start_date DATE NULL,
    -- Data de início da folga
    time_off_end_date DATE NULL,
    -- Data de fim da folga (pode ser igual ao início para 1 dia)
    time_off_description TEXT NULL,
    -- Descrição/observação sobre a folga

    -- CAMPOS ESPECÍFICOS PARA PAGAMENTO
    payment_amount DECIMAL(10,2) NULL,
    -- Valor em reais a ser pago
    payment_record_id INT NULL,
    -- Referência ao registro de pagamento (wp_sistur_payment_records)

    -- DETALHAMENTO DO CÁLCULO (para pagamento)
    calculation_details JSON NULL,
    -- JSON com breakdown do cálculo:
    -- {
    --   "horas_dia_util": {"minutos": 120, "multiplicador": 1.5, "valor_hora": 25.00, "total": 75.00},
    --   "horas_fim_semana": {"minutos": 240, "multiplicador": 2.0, "valor_hora": 25.00, "total": 200.00},
    --   "horas_feriado": {"minutos": 60, "multiplicador": 2.5, "valor_hora": 25.00, "total": 62.50},
    --   "total_minutos": 420,
    --   "total_valor": 337.50
    -- }

    -- CAMPOS DE APROVAÇÃO
    approval_status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
    approved_by INT NULL,
    -- WordPress user ID que aprovou/rejeitou
    approved_at DATETIME NULL,
    approval_notes TEXT NULL,
    -- Observações sobre aprovação/rejeição

    -- CAMPOS DE CONTROLE
    is_partial BOOLEAN DEFAULT FALSE,
    -- Se foi abatimento parcial (TRUE) ou integral (FALSE)

    notes TEXT NULL,
    -- Observações gerais

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    -- WordPress user ID que criou o registro

    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- FOREIGN KEYS
    FOREIGN KEY (employee_id) REFERENCES wp_sistur_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_record_id) REFERENCES wp_sistur_payment_records(id) ON DELETE SET NULL,

    -- INDEXES
    INDEX idx_employee_id (employee_id),
    INDEX idx_deduction_type (deduction_type),
    INDEX idx_approval_status (approval_status),
    INDEX idx_time_off_dates (time_off_start_date, time_off_end_date),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Estrutura do JSON `calculation_details`:**
```json
{
  "horas_dia_util": {
    "minutos": 120,
    "multiplicador": 1.5,
    "valor_hora_base": 25.00,
    "valor_total": 75.00,
    "detalhes": [
      {"data": "2025-11-15", "minutos": 60, "tipo": "dia_util"},
      {"data": "2025-11-18", "minutos": 60, "tipo": "dia_util"}
    ]
  },
  "horas_fim_semana": {
    "minutos": 240,
    "multiplicador": 2.0,
    "valor_hora_base": 25.00,
    "valor_total": 200.00,
    "detalhes": [
      {"data": "2025-11-16", "minutos": 120, "tipo": "sabado"},
      {"data": "2025-11-17", "minutos": 120, "tipo": "domingo"}
    ]
  },
  "horas_feriado": {
    "minutos": 60,
    "multiplicador": 2.5,
    "valor_hora_base": 25.00,
    "valor_total": 62.50,
    "detalhes": [
      {"data": "2025-11-20", "minutos": 60, "tipo": "feriado", "feriado_id": 5}
    ]
  },
  "resumo": {
    "total_minutos": 420,
    "total_horas": 7.0,
    "valor_total": 337.50
  }
}
```

---

### 1.3 Tabela: `wp_sistur_employee_payment_config` (Configuração de Pagamento por Funcionário)

```sql
CREATE TABLE wp_sistur_employee_payment_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,

    -- VALORES BASE
    salario_base DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Salário mensal base do funcionário

    valor_hora_base DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- Valor da hora normal (calculado ou definido manualmente)
    -- Geralmente: salario_base / (carga_horaria_mensal / 60)

    -- MULTIPLICADORES POR TIPO DE DIA
    multiplicador_dia_util DECIMAL(4,2) DEFAULT 1.50,
    -- Multiplicador para horas extras em dias úteis (padrão CLT: 50%)

    multiplicador_fim_semana DECIMAL(4,2) DEFAULT 2.00,
    -- Multiplicador para horas extras em finais de semana (padrão: 100%)

    multiplicador_feriado DECIMAL(4,2) DEFAULT 2.50,
    -- Multiplicador para horas extras em feriados (pode herdar do feriado específico)

    -- CONTROLE
    calculation_method ENUM('automatic', 'manual') DEFAULT 'automatic',
    -- automatic: calcula valor_hora_base automaticamente
    -- manual: usa valor_hora_base definido manualmente

    last_calculated_at DATETIME NULL,
    -- Data da última atualização automática do valor_hora_base

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- FOREIGN KEY
    FOREIGN KEY (employee_id) REFERENCES wp_sistur_employees(id) ON DELETE CASCADE,

    -- UNIQUE KEY
    UNIQUE KEY unique_employee (employee_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 1.4 Alterações em Tabelas Existentes

#### 1.4.1 Adicionar campos em `wp_sistur_payment_records`

```sql
ALTER TABLE wp_sistur_payment_records
ADD COLUMN timebank_deduction_id INT NULL AFTER notes,
ADD COLUMN is_timebank_payment BOOLEAN DEFAULT FALSE AFTER timebank_deduction_id,
ADD FOREIGN KEY (timebank_deduction_id) REFERENCES wp_sistur_timebank_deductions(id) ON DELETE SET NULL;
```

**Campos adicionados:**
- `timebank_deduction_id`: Referência ao registro de abatimento (se for pagamento via banco de horas)
- `is_timebank_payment`: Flag para identificar pagamentos originados de banco de horas

---

### 1.5 Novas Permissões

```sql
-- Adicionar novas permissões específicas
INSERT INTO wp_sistur_permissions (name, module, description) VALUES
('dar_folga', 'time_tracking', 'Permitir registrar abatimento de banco de horas como folga/compensação'),
('pagar_horas_extra', 'payments', 'Permitir pagar horas extras do banco de horas em dinheiro'),
('aprovar_abatimento_banco', 'time_tracking', 'Aprovar ou rejeitar abatimentos de banco de horas'),
('gerenciar_feriados', 'settings', 'Gerenciar cadastro de feriados e adicionais');

-- Atribuir permissões aos papéis padrão
-- Gerente de RH: todas as permissões
INSERT INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r, wp_sistur_permissions p
WHERE r.name = 'Gerente de RH'
AND p.name IN ('dar_folga', 'pagar_horas_extra', 'aprovar_abatimento_banco', 'gerenciar_feriados');

-- Supervisor: apenas dar folga e aprovar
INSERT INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r, wp_sistur_permissions p
WHERE r.name = 'Supervisor'
AND p.name IN ('dar_folga', 'aprovar_abatimento_banco');
```

---

## 2. ESTRUTURA DAS FUNÇÕES PHP

### 2.1 Classe Principal: `SISTUR_Timebank_Manager`

```php
<?php
/**
 * Classe para gerenciar abatimentos de banco de horas
 *
 * @since 1.5.0
 */
class SISTUR_Timebank_Manager {

    private static $instance = null;
    private $permissions;
    private $table_deductions;
    private $table_holidays;
    private $table_payment_config;

    private function __construct() {
        global $wpdb;
        $this->permissions = SISTUR_Permissions::get_instance();
        $this->table_deductions = $wpdb->prefix . 'sistur_timebank_deductions';
        $this->table_holidays = $wpdb->prefix . 'sistur_holidays';
        $this->table_payment_config = $wpdb->prefix . 'sistur_employee_payment_config';

        $this->init_hooks();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks() {
        // AJAX Handlers
        add_action('wp_ajax_sistur_get_employee_timebank_balance', array($this, 'ajax_get_balance'));
        add_action('wp_ajax_sistur_deduct_timebank_folga', array($this, 'ajax_deduct_folga'));
        add_action('wp_ajax_sistur_deduct_timebank_payment', array($this, 'ajax_deduct_payment'));
        add_action('wp_ajax_sistur_approve_deduction', array($this, 'ajax_approve_deduction'));
        add_action('wp_ajax_sistur_calculate_payment_preview', array($this, 'ajax_calculate_payment_preview'));

        // Feriados
        add_action('wp_ajax_sistur_save_holiday', array($this, 'ajax_save_holiday'));
        add_action('wp_ajax_sistur_delete_holiday', array($this, 'ajax_delete_holiday'));

        // Configuração de pagamento
        add_action('wp_ajax_sistur_get_payment_config', array($this, 'ajax_get_payment_config'));
        add_action('wp_ajax_sistur_save_payment_config', array($this, 'ajax_save_payment_config'));

        // Scripts e estilos
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enfileira scripts e estilos
     */
    public function enqueue_scripts($hook) {
        // Apenas nas páginas relevantes
        if (!in_array($hook, ['sistur_page_sistur-time-tracking', 'sistur_page_sistur-employees'])) {
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
            'nonce' => wp_create_nonce('sistur_timebank_nonce')
        ));
    }

    /**
     * Obtém saldo atual do banco de horas do funcionário
     *
     * @param int $employee_id ID do funcionário
     * @return array ['total_minutos' => int, 'total_horas' => float, 'formatted' => string]
     */
    public function get_employee_balance($employee_id) {
        global $wpdb;

        $table_days = $wpdb->prefix . 'sistur_time_days';

        // Soma de todos os saldos finais dos dias
        $result = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(saldo_final_minutos) as total_minutos
            FROM {$table_days}
            WHERE employee_id = %d
        ", $employee_id));

        $total_minutos = (int) ($result->total_minutos ?? 0);
        $total_horas = $total_minutos / 60;

        // Formatar para exibição
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

    /**
     * Valida se o funcionário tem saldo suficiente
     */
    public function validate_balance($employee_id, $minutes_to_deduct) {
        $balance = $this->get_employee_balance($employee_id);

        if ($balance['total_minutos'] < $minutes_to_deduct) {
            return array(
                'valid' => false,
                'message' => 'Saldo insuficiente no banco de horas',
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

        // Validar dados obrigatórios
        $required = ['employee_id', 'minutes_deducted', 'time_off_start_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return array('success' => false, 'message' => "Campo obrigatório: {$field}");
            }
        }

        // Validar saldo
        $validation = $this->validate_balance($data['employee_id'], $data['minutes_deducted']);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => $validation['message']);
        }

        $balance = $this->get_employee_balance($data['employee_id']);

        // Preparar dados
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

        // Inserir no banco
        $result = $wpdb->insert($this->table_deductions, $insert_data);

        if ($result === false) {
            return array('success' => false, 'message' => 'Erro ao inserir registro: ' . $wpdb->last_error);
        }

        $deduction_id = $wpdb->insert_id;

        // Registrar auditoria
        $this->log_audit('timebank_deduction_created', $deduction_id, $data['employee_id']);

        return array(
            'success' => true,
            'deduction_id' => $deduction_id,
            'message' => 'Abatimento por folga registrado com sucesso. Aguardando aprovação.'
        );
    }

    /**
     * Calcula valor a pagar baseado em minutos do banco de horas
     * Considera tipo de dia (útil, fim de semana, feriado) e multiplicadores
     *
     * @param int $employee_id ID do funcionário
     * @param int $minutes_to_pay Minutos a pagar
     * @param array $date_range ['start' => 'Y-m-d', 'end' => 'Y-m-d'] (opcional)
     * @return array Cálculo detalhado
     */
    public function calculate_payment_amount($employee_id, $minutes_to_pay, $date_range = null) {
        global $wpdb;

        // Obter configuração de pagamento do funcionário
        $config = $this->get_payment_config($employee_id);

        if (!$config || $config['valor_hora_base'] <= 0) {
            return array(
                'success' => false,
                'message' => 'Configuração de pagamento não encontrada para este funcionário'
            );
        }

        // Se não especificar período, usar últimos 30 dias
        if (!$date_range) {
            $date_range = array(
                'start' => date('Y-m-d', strtotime('-30 days')),
                'end' => date('Y-m-d')
            );
        }

        // Buscar dias trabalhados no período com saldo positivo
        $table_days = $wpdb->prefix . 'sistur_time_days';
        $days = $wpdb->get_results($wpdb->prepare("
            SELECT
                shift_date,
                saldo_final_minutos,
                status
            FROM {$table_days}
            WHERE employee_id = %d
            AND shift_date BETWEEN %s AND %s
            AND saldo_final_minutos > 0
            ORDER BY shift_date DESC
        ", $employee_id, $date_range['start'], $date_range['end']));

        // Obter feriados do período
        $holidays = $wpdb->get_results($wpdb->prepare("
            SELECT holiday_date, multiplicador_adicional
            FROM {$this->table_holidays}
            WHERE holiday_date BETWEEN %s AND %s
            AND status = 'active'
        ", $date_range['start'], $date_range['end']), OBJECT_K);

        // Categorizar minutos por tipo de dia
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

            // Determinar tipo do dia
            $day_of_week = date('N', strtotime($date)); // 1=Monday, 7=Sunday
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

        // Calcular valores
        $valor_hora_base = $config['valor_hora_base'];
        $total_valor = 0;

        // Dias úteis
        if ($breakdown['horas_dia_util']['minutos'] > 0) {
            $horas = $breakdown['horas_dia_util']['minutos'] / 60;
            $valor = $horas * $valor_hora_base * $config['multiplicador_dia_util'];
            $breakdown['horas_dia_util']['multiplicador'] = $config['multiplicador_dia_util'];
            $breakdown['horas_dia_util']['valor_hora_base'] = $valor_hora_base;
            $breakdown['horas_dia_util']['valor_total'] = round($valor, 2);
            $total_valor += $valor;
        }

        // Fins de semana
        if ($breakdown['horas_fim_semana']['minutos'] > 0) {
            $horas = $breakdown['horas_fim_semana']['minutos'] / 60;
            $valor = $horas * $valor_hora_base * $config['multiplicador_fim_semana'];
            $breakdown['horas_fim_semana']['multiplicador'] = $config['multiplicador_fim_semana'];
            $breakdown['horas_fim_semana']['valor_hora_base'] = $valor_hora_base;
            $breakdown['horas_fim_semana']['valor_total'] = round($valor, 2);
            $total_valor += $valor;
        }

        // Feriados
        if ($breakdown['horas_feriado']['minutos'] > 0) {
            $horas = $breakdown['horas_feriado']['minutos'] / 60;
            // Usar multiplicador médio dos feriados (pode ser refinado)
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
                'minutos_nao_pagos' => $minutes_remaining // Se não havia saldo suficiente no período
            )
        );
    }

    /**
     * Registra abatimento por pagamento (dinheiro)
     */
    public function deduct_timebank_payment($data) {
        global $wpdb;

        // Validar dados obrigatórios
        $required = ['employee_id', 'minutes_deducted'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return array('success' => false, 'message' => "Campo obrigatório: {$field}");
            }
        }

        // Validar saldo
        $validation = $this->validate_balance($data['employee_id'], $data['minutes_deducted']);
        if (!$validation['valid']) {
            return array('success' => false, 'message' => $validation['message']);
        }

        // Calcular valor a pagar
        $calculation = $this->calculate_payment_amount(
            $data['employee_id'],
            $data['minutes_deducted'],
            $data['date_range'] ?? null
        );

        if (!$calculation['success']) {
            return $calculation;
        }

        $balance = $this->get_employee_balance($data['employee_id']);

        // Iniciar transação
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Inserir registro de pagamento
            $payment_data = array(
                'employee_id' => (int) $data['employee_id'],
                'payment_type' => 'monthly', // ou outro tipo conforme necessário
                'amount' => $calculation['resumo']['valor_total'],
                'payment_date' => date('Y-m-d'),
                'period_start' => $data['date_range']['start'] ?? date('Y-m-d', strtotime('-30 days')),
                'period_end' => $data['date_range']['end'] ?? date('Y-m-d'),
                'notes' => 'Pagamento de horas extras do banco de horas',
                'created_by' => get_current_user_id(),
                'is_timebank_payment' => 1
            );

            $table_payments = $wpdb->prefix . 'sistur_payment_records';
            $wpdb->insert($table_payments, $payment_data);
            $payment_record_id = $wpdb->insert_id;

            // 2. Inserir registro de abatimento
            $deduction_data = array(
                'employee_id' => (int) $data['employee_id'],
                'deduction_type' => 'pagamento',
                'minutes_deducted' => $calculation['resumo']['total_minutos'],
                'balance_before_minutes' => $balance['total_minutos'],
                'balance_after_minutes' => $balance['total_minutos'] - $calculation['resumo']['total_minutos'],
                'payment_amount' => $calculation['resumo']['valor_total'],
                'payment_record_id' => $payment_record_id,
                'calculation_details' => json_encode($calculation),
                'is_partial' => !empty($data['is_partial']),
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'approval_status' => 'pendente',
                'created_by' => get_current_user_id()
            );

            $wpdb->insert($this->table_deductions, $deduction_data);
            $deduction_id = $wpdb->insert_id;

            // 3. Atualizar referência no pagamento
            $wpdb->update(
                $table_payments,
                array('timebank_deduction_id' => $deduction_id),
                array('id' => $payment_record_id)
            );

            // Commit
            $wpdb->query('COMMIT');

            // Auditoria
            $this->log_audit('timebank_payment_created', $deduction_id, $data['employee_id']);

            return array(
                'success' => true,
                'deduction_id' => $deduction_id,
                'payment_record_id' => $payment_record_id,
                'amount' => $calculation['resumo']['valor_total'],
                'message' => 'Pagamento de horas extras registrado com sucesso. Aguardando aprovação.'
            );

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return array('success' => false, 'message' => 'Erro ao processar: ' . $e->getMessage());
        }
    }

    /**
     * Aprova ou rejeita um abatimento
     */
    public function approve_deduction($deduction_id, $status, $notes = '') {
        global $wpdb;

        if (!in_array($status, ['aprovado', 'rejeitado'])) {
            return array('success' => false, 'message' => 'Status inválido');
        }

        // Buscar registro
        $deduction = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->table_deductions} WHERE id = %d
        ", $deduction_id));

        if (!$deduction) {
            return array('success' => false, 'message' => 'Registro não encontrado');
        }

        if ($deduction->approval_status !== 'pendente') {
            return array('success' => false, 'message' => 'Este registro já foi processado');
        }

        // Se for aprovação, fazer o abatimento efetivo
        if ($status === 'aprovado') {
            // Aqui você pode criar um registro negativo em time_days ou usar outro método
            // para efetivamente abater os minutos do saldo
            // Por exemplo, criar um dia especial com status='bank_used' e saldo negativo

            $table_days = $wpdb->prefix . 'sistur_time_days';
            $wpdb->insert($table_days, array(
                'employee_id' => $deduction->employee_id,
                'shift_date' => date('Y-m-d'),
                'status' => 'bank_used',
                'minutos_trabalhados' => 0,
                'saldo_calculado_minutos' => -$deduction->minutes_deducted,
                'saldo_final_minutos' => -$deduction->minutes_deducted,
                'bank_minutes_adjustment' => -$deduction->minutes_deducted,
                'needs_review' => 0
            ));
        }

        // Atualizar status
        $wpdb->update(
            $this->table_deductions,
            array(
                'approval_status' => $status,
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql'),
                'approval_notes' => sanitize_textarea_field($notes)
            ),
            array('id' => $deduction_id)
        );

        // Auditoria
        $this->log_audit('timebank_deduction_' . $status, $deduction_id, $deduction->employee_id);

        return array(
            'success' => true,
            'message' => $status === 'aprovado' ? 'Abatimento aprovado com sucesso' : 'Abatimento rejeitado'
        );
    }

    /**
     * Obtém configuração de pagamento do funcionário
     */
    public function get_payment_config($employee_id) {
        global $wpdb;

        $config = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->table_payment_config}
            WHERE employee_id = %d
        ", $employee_id), ARRAY_A);

        // Se não existir, criar com valores padrão
        if (!$config) {
            $config = $this->create_default_payment_config($employee_id);
        }

        return $config;
    }

    /**
     * Cria configuração padrão de pagamento
     */
    private function create_default_payment_config($employee_id) {
        global $wpdb;

        // Buscar dados do funcionário
        $table_employees = $wpdb->prefix . 'sistur_employees';
        $employee = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$table_employees} WHERE id = %d
        ", $employee_id));

        if (!$employee) {
            return null;
        }

        // Calcular valor hora base
        // Assumindo 220 horas mensais (padrão CLT)
        $salario_base = 0; // Você precisará ter este campo na tabela employees
        $valor_hora_base = $salario_base > 0 ? $salario_base / 220 : 0;

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

        // Verificar permissão
        if (!$this->permissions->can($employee_id, 'time_tracking', 'dar_folga')) {
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

        // Verificar permissão
        if (!$this->permissions->can($employee_id, 'payments', 'pagar_horas_extra')) {
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

        $deduction_id = intval($_POST['deduction_id']);
        $status = sanitize_text_field($_POST['status']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        // Verificar permissão (deve ter permissão de aprovar)
        // Aqui você pode adicionar lógica adicional de permissão

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

    public function ajax_save_holiday() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        // Verificar permissão
        // (implemente verificação de permissão 'gerenciar_feriados')

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
            // Atualizar
            $wpdb->update($this->table_holidays, $data, array('id' => $holiday_id));
            $message = 'Feriado atualizado com sucesso';
        } else {
            // Inserir
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

        global $wpdb;

        $holiday_id = intval($_POST['holiday_id']);

        $wpdb->delete($this->table_holidays, array('id' => $holiday_id));

        wp_send_json_success(array('message' => 'Feriado excluído com sucesso'));
    }

    public function ajax_get_payment_config() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

        $employee_id = intval($_POST['employee_id']);
        $config = $this->get_payment_config($employee_id);

        wp_send_json_success(array('config' => $config));
    }

    public function ajax_save_payment_config() {
        check_ajax_referer('sistur_timebank_nonce', 'nonce');

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

        // Verificar se já existe
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
}

// Inicializar
SISTUR_Timebank_Manager::get_instance();
```

---

## 3. FLUXO DE VALIDAÇÃO E PERMISSÕES

### 3.1 Fluxo: Abatimento por Folga

```
1. USUÁRIO abre modal "Abater Banco de Horas"
   └─> Seleciona funcionário

2. SISTEMA consulta saldo via AJAX
   └─> GET balance (wp_sistur_time_days)
   └─> Exibe saldo disponível

3. USUÁRIO preenche dados:
   - Tipo: Folga
   - Quantidade de minutos (integral ou parcial)
   - Data início da folga
   - Data fim da folga (opcional)
   - Observações

4. SISTEMA valida:
   ✓ Verificar permissão: dar_folga
   ✓ Verificar saldo suficiente
   ✓ Validar datas (início ≤ fim)

5. SISTEMA registra:
   └─> INSERT wp_sistur_timebank_deductions
       - status: 'pendente'
       - deduction_type: 'folga'
   └─> Não abate imediatamente

6. APROVAÇÃO:
   └─> Usuário com permissão 'aprovar_abatimento_banco'
   └─> Revisa e aprova/rejeita

7. Se APROVADO:
   └─> INSERT wp_sistur_time_days
       - status: 'bank_used'
       - saldo_final_minutos: -[minutos_abatidos]
   └─> Atualiza status deduction: 'aprovado'

8. Se REJEITADO:
   └─> Apenas atualiza status: 'rejeitado'
   └─> Saldo não é alterado
```

### 3.2 Fluxo: Abatimento por Pagamento

```
1. USUÁRIO abre modal "Pagar Horas Extras"
   └─> Seleciona funcionário

2. SISTEMA consulta:
   └─> GET balance
   └─> GET payment_config (multiplicadores, valor hora)
   └─> Exibe configuração

3. USUÁRIO preenche:
   - Tipo: Pagamento
   - Quantidade de minutos (integral ou parcial)
   - Período de referência (opcional)

4. SISTEMA calcula preview:
   └─> AJAX calculate_payment_preview
   └─> Busca dias trabalhados no período
   └─> Classifica por tipo (útil/fim semana/feriado)
   └─> Aplica multiplicadores
   └─> Retorna breakdown detalhado

5. USUÁRIO confirma valores exibidos

6. SISTEMA valida:
   ✓ Verificar permissão: pagar_horas_extra
   ✓ Verificar saldo suficiente
   ✓ Verificar payment_config válida

7. SISTEMA registra (TRANSAÇÃO):
   └─> INSERT wp_sistur_payment_records
       - amount: [valor calculado]
       - is_timebank_payment: TRUE
   └─> INSERT wp_sistur_timebank_deductions
       - deduction_type: 'pagamento'
       - payment_record_id: [id do payment]
       - calculation_details: [JSON breakdown]
       - status: 'pendente'

8. APROVAÇÃO:
   └─> Aprovador revisa cálculo
   └─> Aprova/Rejeita

9. Se APROVADO:
   └─> Abate saldo (INSERT time_days negativo)
   └─> Atualiza status: 'aprovado'
   └─> Pagamento pode ser processado

10. Se REJEITADO:
    └─> Status: 'rejeitado'
    └─> Pagamento não é efetuado
```

### 3.3 Verificação de Permissões

```php
// Exemplo de verificação
$permissions = SISTUR_Permissions::get_instance();

// Para dar folga
if (!$permissions->can($employee_id, 'time_tracking', 'dar_folga')) {
    wp_send_json_error(['message' => 'Sem permissão para dar folga']);
}

// Para pagar horas extras
if (!$permissions->can($employee_id, 'payments', 'pagar_horas_extra')) {
    wp_send_json_error(['message' => 'Sem permissão para pagar horas extras']);
}

// Para aprovar abatimentos
if (!$permissions->can($employee_id, 'time_tracking', 'aprovar_abatimento_banco')) {
    wp_send_json_error(['message' => 'Sem permissão para aprovar abatimentos']);
}

// Para gerenciar feriados
if (!$permissions->can($employee_id, 'settings', 'gerenciar_feriados')) {
    wp_send_json_error(['message' => 'Sem permissão para gerenciar feriados']);
}
```

---

## 4. ESTRUTURA DOS MODAIS

### 4.1 Modal: Abater Banco de Horas (Time Tracking)

```html
<div id="modal-abater-banco-horas" style="display: none;">
    <div class="modal-content">
        <h2>Abater Banco de Horas</h2>

        <form id="form-abater-banco-horas">
            <!-- Seleção de Funcionário -->
            <div class="form-group">
                <label>Funcionário *</label>
                <select name="employee_id" id="employee-select" required>
                    <option value="">Selecione...</option>
                    <!-- Carregar via PHP/AJAX -->
                </select>
            </div>

            <!-- Exibir Saldo Atual -->
            <div id="balance-display" style="display: none;">
                <p><strong>Saldo Atual:</strong> <span id="balance-text"></span></p>
                <p><strong>Total em Minutos:</strong> <span id="balance-minutes"></span></p>
            </div>

            <!-- Tipo de Abatimento -->
            <div class="form-group">
                <label>Tipo de Compensação *</label>
                <select name="deduction_type" id="deduction-type" required>
                    <option value="">Selecione...</option>
                    <option value="folga">Compensação em Folga</option>
                    <option value="pagamento">Pagamento em Dinheiro</option>
                </select>
            </div>

            <!-- Quantidade -->
            <div class="form-group">
                <label>Quantidade de Horas *</label>
                <div>
                    <input type="radio" name="deduction_mode" value="integral" checked> Integral (Todo o saldo)
                    <input type="radio" name="deduction_mode" value="parcial"> Parcial
                </div>
            </div>

            <div id="partial-amount" style="display: none;">
                <label>Minutos a Abater *</label>
                <input type="number" name="minutes_deducted" min="1" id="minutes-input">
            </div>

            <!-- CAMPOS ESPECÍFICOS PARA FOLGA -->
            <div id="folga-fields" style="display: none;">
                <div class="form-group">
                    <label>Data de Início da Folga *</label>
                    <input type="date" name="time_off_start_date" id="time-off-start">
                </div>

                <div class="form-group">
                    <label>Data de Fim da Folga</label>
                    <input type="date" name="time_off_end_date" id="time-off-end">
                    <small>Deixe em branco se for apenas 1 dia</small>
                </div>

                <div class="form-group">
                    <label>Descrição da Folga</label>
                    <textarea name="time_off_description" rows="3"></textarea>
                </div>
            </div>

            <!-- CAMPOS ESPECÍFICOS PARA PAGAMENTO -->
            <div id="pagamento-fields" style="display: none;">
                <div class="form-group">
                    <label>Período de Referência (opcional)</label>
                    <input type="date" name="start_date" id="payment-start-date">
                    <span> até </span>
                    <input type="date" name="end_date" id="payment-end-date">
                    <small>Deixe em branco para usar últimos 30 dias</small>
                </div>

                <button type="button" id="btn-calculate-preview" class="button">
                    Calcular Prévia
                </button>

                <div id="payment-preview" style="display: none; border: 1px solid #ccc; padding: 15px; margin: 10px 0;">
                    <h4>Prévia do Pagamento</h4>
                    <table>
                        <tr>
                            <td>Horas em Dias Úteis:</td>
                            <td id="preview-util-hours"></td>
                            <td id="preview-util-value"></td>
                        </tr>
                        <tr>
                            <td>Horas em Fins de Semana:</td>
                            <td id="preview-weekend-hours"></td>
                            <td id="preview-weekend-value"></td>
                        </tr>
                        <tr>
                            <td>Horas em Feriados:</td>
                            <td id="preview-holiday-hours"></td>
                            <td id="preview-holiday-value"></td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td>TOTAL:</td>
                            <td id="preview-total-hours"></td>
                            <td id="preview-total-value"></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Observações Gerais -->
            <div class="form-group">
                <label>Observações</label>
                <textarea name="notes" rows="3"></textarea>
            </div>

            <input type="hidden" name="is_partial" id="is-partial" value="0">

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" id="btn-cancel" class="button">Cancelar</button>
                <button type="submit" class="button button-primary">Registrar Abatimento</button>
            </div>
        </form>
    </div>
</div>
```

### 4.2 Modal: Aprovação de Abatimentos

```html
<div id="modal-aprovar-abatimento" style="display: none;">
    <div class="modal-content">
        <h2>Aprovar Abatimento</h2>

        <div id="deduction-details">
            <!-- Carregar via AJAX -->
            <p><strong>Funcionário:</strong> <span id="detail-employee"></span></p>
            <p><strong>Tipo:</strong> <span id="detail-type"></span></p>
            <p><strong>Minutos:</strong> <span id="detail-minutes"></span></p>
            <p><strong>Saldo Antes:</strong> <span id="detail-balance-before"></span></p>
            <p><strong>Saldo Após:</strong> <span id="detail-balance-after"></span></p>

            <div id="detail-folga" style="display: none;">
                <p><strong>Data Folga:</strong> <span id="detail-folga-dates"></span></p>
            </div>

            <div id="detail-pagamento" style="display: none;">
                <p><strong>Valor a Pagar:</strong> <span id="detail-payment-amount"></span></p>
            </div>
        </div>

        <form id="form-aprovar-abatimento">
            <input type="hidden" name="deduction_id" id="deduction-id">

            <div class="form-group">
                <label>Decisão *</label>
                <select name="status" required>
                    <option value="">Selecione...</option>
                    <option value="aprovado">Aprovar</option>
                    <option value="rejeitado">Rejeitar</option>
                </select>
            </div>

            <div class="form-group">
                <label>Observações</label>
                <textarea name="notes" rows="3"></textarea>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="button" id="btn-cancel-approval">Cancelar</button>
                <button type="submit" class="button button-primary">Confirmar</button>
            </div>
        </form>
    </div>
</div>
```

### 4.3 Modal: Gerenciar Feriados

```html
<div id="modal-gerenciar-feriados" style="display: none;">
    <div class="modal-content" style="max-width: 800px;">
        <h2>Gerenciar Feriados</h2>

        <button type="button" id="btn-add-holiday" class="button button-primary">
            + Adicionar Feriado
        </button>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Tipo</th>
                    <th>Adicional</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="holidays-list">
                <!-- Carregar via AJAX -->
            </tbody>
        </table>

        <div style="text-align: right; margin-top: 20px;">
            <button type="button" id="btn-close-holidays" class="button">Fechar</button>
        </div>
    </div>
</div>

<!-- Modal para Adicionar/Editar Feriado -->
<div id="modal-holiday-form" style="display: none;">
    <div class="modal-content">
        <h2 id="holiday-form-title">Adicionar Feriado</h2>

        <form id="form-holiday">
            <input type="hidden" name="holiday_id" id="holiday-id">

            <div class="form-group">
                <label>Data *</label>
                <input type="date" name="holiday_date" required>
            </div>

            <div class="form-group">
                <label>Descrição *</label>
                <input type="text" name="description" required placeholder="Ex: Natal, Independência">
            </div>

            <div class="form-group">
                <label>Tipo *</label>
                <select name="holiday_type" required>
                    <option value="nacional">Nacional</option>
                    <option value="estadual">Estadual</option>
                    <option value="municipal">Municipal</option>
                    <option value="ponto_facultativo">Ponto Facultativo</option>
                </select>
            </div>

            <div class="form-group">
                <label>Multiplicador Adicional *</label>
                <input type="number" name="multiplicador_adicional" step="0.01" min="1.00" value="1.00" required>
                <small>1.00 = 100%, 1.50 = 150%, 2.00 = 200%</small>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                </select>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="button" id="btn-cancel-holiday">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
```

### 4.4 Modal: Configuração de Pagamento do Funcionário

```html
<div id="modal-payment-config" style="display: none;">
    <div class="modal-content">
        <h2>Configuração de Pagamento</h2>
        <p><strong>Funcionário:</strong> <span id="config-employee-name"></span></p>

        <form id="form-payment-config">
            <input type="hidden" name="employee_id" id="config-employee-id">

            <div class="form-group">
                <label>Salário Base (Mensal) *</label>
                <input type="number" name="salario_base" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label>Método de Cálculo</label>
                <select name="calculation_method" id="calculation-method">
                    <option value="automatic">Automático (calcula valor/hora)</option>
                    <option value="manual">Manual (definir valor/hora)</option>
                </select>
            </div>

            <div class="form-group" id="manual-valor-hora" style="display: none;">
                <label>Valor da Hora Base *</label>
                <input type="number" name="valor_hora_base" step="0.01" min="0">
                <small>Será calculado automaticamente se método = Automático</small>
            </div>

            <h3>Multiplicadores de Hora Extra</h3>

            <div class="form-group">
                <label>Dias Úteis *</label>
                <input type="number" name="multiplicador_dia_util" step="0.01" min="1.00" value="1.50" required>
                <small>Padrão CLT: 1.50 (50% adicional)</small>
            </div>

            <div class="form-group">
                <label>Fins de Semana *</label>
                <input type="number" name="multiplicador_fim_semana" step="0.01" min="1.00" value="2.00" required>
                <small>Padrão: 2.00 (100% adicional)</small>
            </div>

            <div class="form-group">
                <label>Feriados *</label>
                <input type="number" name="multiplicador_feriado" step="0.01" min="1.00" value="2.50" required>
                <small>Padrão: 2.50 (150% adicional)</small>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="button" id="btn-cancel-config">Cancelar</button>
                <button type="submit" class="button button-primary">Salvar Configuração</button>
            </div>
        </form>
    </div>
</div>
```

---

## 5. JAVASCRIPT (timebank-manager.js)

```javascript
jQuery(document).ready(function($) {
    'use strict';

    const nonce = sisturTimebankNonce.nonce;
    let currentEmployeeId = null;
    let currentBalance = null;

    // ==================== MODAL ABATER BANCO DE HORAS ====================

    // Quando seleciona funcionário, carregar saldo
    $('#employee-select').on('change', function() {
        currentEmployeeId = $(this).val();

        if (!currentEmployeeId) {
            $('#balance-display').hide();
            return;
        }

        // Buscar saldo via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_get_employee_timebank_balance',
                nonce: nonce,
                employee_id: currentEmployeeId
            },
            success: function(response) {
                if (response.success) {
                    currentBalance = response.data.balance;
                    $('#balance-text').text(currentBalance.formatted);
                    $('#balance-minutes').text(currentBalance.total_minutos + ' minutos');
                    $('#balance-display').show();
                }
            }
        });
    });

    // Quando muda tipo de abatimento, mostrar campos relevantes
    $('#deduction-type').on('change', function() {
        const type = $(this).val();

        $('#folga-fields').hide();
        $('#pagamento-fields').hide();

        if (type === 'folga') {
            $('#folga-fields').show();
        } else if (type === 'pagamento') {
            $('#pagamento-fields').show();
        }
    });

    // Quando muda modo (integral/parcial)
    $('input[name="deduction_mode"]').on('change', function() {
        const mode = $(this).val();

        if (mode === 'parcial') {
            $('#partial-amount').show();
            $('#is-partial').val('1');
        } else {
            $('#partial-amount').hide();
            $('#is-partial').val('0');
            // Preencher com saldo total
            if (currentBalance) {
                $('#minutes-input').val(currentBalance.total_minutos);
            }
        }
    });

    // Botão Calcular Prévia (pagamento)
    $('#btn-calculate-preview').on('click', function() {
        const minutesToPay = $('input[name="deduction_mode"]:checked').val() === 'integral'
            ? currentBalance.total_minutos
            : parseInt($('#minutes-input').val());

        if (!minutesToPay || minutesToPay <= 0) {
            alert('Informe a quantidade de minutos');
            return;
        }

        const startDate = $('#payment-start-date').val();
        const endDate = $('#payment-end-date').val();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sistur_calculate_payment_preview',
                nonce: nonce,
                employee_id: currentEmployeeId,
                minutes_to_pay: minutesToPay,
                start_date: startDate,
                end_date: endDate
            },
            success: function(response) {
                if (response.success) {
                    const calc = response.data;
                    const breakdown = calc.breakdown;

                    // Preencher prévia
                    $('#preview-util-hours').text((breakdown.horas_dia_util.minutos / 60).toFixed(2) + 'h');
                    $('#preview-util-value').text('R$ ' + (breakdown.horas_dia_util.valor_total || 0).toFixed(2));

                    $('#preview-weekend-hours').text((breakdown.horas_fim_semana.minutos / 60).toFixed(2) + 'h');
                    $('#preview-weekend-value').text('R$ ' + (breakdown.horas_fim_semana.valor_total || 0).toFixed(2));

                    $('#preview-holiday-hours').text((breakdown.horas_feriado.minutos / 60).toFixed(2) + 'h');
                    $('#preview-holiday-value').text('R$ ' + (breakdown.horas_feriado.valor_total || 0).toFixed(2));

                    $('#preview-total-hours').text(calc.resumo.total_horas.toFixed(2) + 'h');
                    $('#preview-total-value').text('R$ ' + calc.resumo.valor_total.toFixed(2));

                    $('#payment-preview').show();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Submit form
    $('#form-abater-banco-horas').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serializeArray();
        const deductionType = $('#deduction-type').val();

        // Se integral, usar saldo total
        if ($('input[name="deduction_mode"]:checked').val() === 'integral') {
            formData.push({name: 'minutes_deducted', value: currentBalance.total_minutos});
        }

        const actionName = deductionType === 'folga'
            ? 'sistur_deduct_timebank_folga'
            : 'sistur_deduct_timebank_payment';

        formData.push({name: 'action', value: actionName});
        formData.push({name: 'nonce', value: nonce});

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $.param(formData),
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#modal-abater-banco-horas').hide();
                    location.reload(); // ou atualizar lista
                } else {
                    alert('Erro: ' + response.data.message);
                }
            },
            error: function() {
                alert('Erro ao processar solicitação');
            }
        });
    });

    // Cancelar
    $('#btn-cancel').on('click', function() {
        $('#modal-abater-banco-horas').hide();
    });

    // ==================== MODAL APROVAR ABATIMENTO ====================

    // (Implementar lógica similar para carregar detalhes e aprovar/rejeitar)

    // ==================== MODAL FERIADOS ====================

    // (Implementar lógica de CRUD de feriados)

    // ==================== MODAL CONFIGURAÇÃO DE PAGAMENTO ====================

    $('#calculation-method').on('change', function() {
        if ($(this).val() === 'manual') {
            $('#manual-valor-hora').show();
        } else {
            $('#manual-valor-hora').hide();
        }
    });

    // Submit configuração
    $('#form-payment-config').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serializeArray();
        formData.push({name: 'action', value: 'sistur_save_payment_config'});
        formData.push({name: 'nonce', value: nonce});

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $.param(formData),
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#modal-payment-config').hide();
                } else {
                    alert('Erro: ' + response.data.message);
                }
            }
        });
    });
});
```

---

## 6. EXEMPLO DE USO COMPLETO

### Cenário 1: Dar Folga

1. **Gerente de RH** acessa Time Tracking
2. Clica em "Abater Banco de Horas" no funcionário João
3. Sistema mostra:
   - Saldo atual: +15h 30min (930 minutos)
4. Gerente preenche:
   - Tipo: Folga
   - Modo: Parcial
   - Minutos: 480 (8 horas = 1 dia)
   - Data início: 2025-12-01
   - Data fim: 2025-12-01
   - Obs: "Folga compensatória"
5. Clica em "Registrar"
6. Sistema:
   - Valida permissão `dar_folga` ✓
   - Valida saldo suficiente (930 >= 480) ✓
   - Insere em `timebank_deductions` com status `pendente`
7. **Supervisor** aprova:
   - Acessa lista de pendências
   - Revisa dados
   - Aprova
8. Sistema:
   - Cria registro em `time_days` com -480 minutos
   - Novo saldo: 930 - 480 = 450 minutos (7h 30min)

---

### Cenário 2: Pagar Horas Extras

1. **Gerente de RH** acessa Payments
2. Clica em "Pagar Horas Extras" no funcionário Maria
3. Sistema mostra:
   - Saldo: +20h (1200 minutos)
   - Configuração:
     - Valor hora base: R$ 25,00
     - Dias úteis: 1.5x (R$ 37,50/h)
     - Fins de semana: 2.0x (R$ 50,00/h)
     - Feriados: 2.5x (R$ 62,50/h)
4. Gerente preenche:
   - Tipo: Pagamento
   - Modo: Integral (1200 minutos)
   - Período: 01/11/2025 a 30/11/2025
5. Clica em "Calcular Prévia"
6. Sistema analisa:
   - Busca dias com saldo positivo no período
   - Classifica por tipo:
     - 600 min (10h) em dias úteis = 10h × R$ 37,50 = R$ 375,00
     - 480 min (8h) em fins de semana = 8h × R$ 50,00 = R$ 400,00
     - 120 min (2h) em feriados = 2h × R$ 62,50 = R$ 125,00
   - **TOTAL: 20h = R$ 900,00**
7. Gerente confirma
8. Sistema:
   - Valida permissão `pagar_horas_extra` ✓
   - Cria `payment_record` com R$ 900,00
   - Cria `timebank_deduction` com JSON detalhado
   - Status: `pendente`
9. **Aprovador** revisa e aprova
10. Sistema:
    - Abate 1200 minutos do saldo
    - Novo saldo: 0 minutos
    - Pagamento autorizado para processamento

---

## 7. SCRIPTS SQL DE CRIAÇÃO

### 7.1 Script Completo de Criação de Tabelas

```sql
-- 1. Tabela de Feriados
CREATE TABLE IF NOT EXISTS wp_sistur_holidays (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de Abatimentos de Banco de Horas
CREATE TABLE IF NOT EXISTS wp_sistur_timebank_deductions (
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
    FOREIGN KEY (employee_id) REFERENCES wp_sistur_employees(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_record_id) REFERENCES wp_sistur_payment_records(id) ON DELETE SET NULL,
    INDEX idx_employee_id (employee_id),
    INDEX idx_deduction_type (deduction_type),
    INDEX idx_approval_status (approval_status),
    INDEX idx_time_off_dates (time_off_start_date, time_off_end_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabela de Configuração de Pagamento por Funcionário
CREATE TABLE IF NOT EXISTS wp_sistur_employee_payment_config (
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
    FOREIGN KEY (employee_id) REFERENCES wp_sistur_employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Alterar tabela de pagamentos existente
ALTER TABLE wp_sistur_payment_records
ADD COLUMN IF NOT EXISTS timebank_deduction_id INT NULL AFTER notes,
ADD COLUMN IF NOT EXISTS is_timebank_payment BOOLEAN DEFAULT FALSE AFTER timebank_deduction_id;

-- Adicionar foreign key se ainda não existir
-- (MySQL não permite IF NOT EXISTS em foreign keys, então usar procedimento condicional)
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_NAME = 'fk_payment_timebank'
    AND TABLE_NAME = 'wp_sistur_payment_records');

SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE wp_sistur_payment_records ADD CONSTRAINT fk_payment_timebank FOREIGN KEY (timebank_deduction_id) REFERENCES wp_sistur_timebank_deductions(id) ON DELETE SET NULL',
    'SELECT "Foreign key already exists"'
);

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Inserir novas permissões
INSERT IGNORE INTO wp_sistur_permissions (name, module, description) VALUES
('dar_folga', 'time_tracking', 'Permitir registrar abatimento de banco de horas como folga/compensação'),
('pagar_horas_extra', 'payments', 'Permitir pagar horas extras do banco de horas em dinheiro'),
('aprovar_abatimento_banco', 'time_tracking', 'Aprovar ou rejeitar abatimentos de banco de horas'),
('gerenciar_feriados', 'settings', 'Gerenciar cadastro de feriados e adicionais');

-- 6. Atribuir permissões ao papel "Gerente de RH"
INSERT IGNORE INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r, wp_sistur_permissions p
WHERE r.name = 'Gerente de RH'
AND p.name IN ('dar_folga', 'pagar_horas_extra', 'aprovar_abatimento_banco', 'gerenciar_feriados');

-- 7. Atribuir permissões ao papel "Supervisor"
INSERT IGNORE INTO wp_sistur_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM wp_sistur_roles r, wp_sistur_permissions p
WHERE r.name = 'Supervisor'
AND p.name IN ('dar_folga', 'aprovar_abatimento_banco');

-- 8. Inserir alguns feriados nacionais do Brasil (2025)
INSERT IGNORE INTO wp_sistur_holidays (holiday_date, description, holiday_type, multiplicador_adicional) VALUES
('2025-01-01', 'Confraternização Universal', 'nacional', 2.00),
('2025-02-13', 'Carnaval', 'ponto_facultativo', 1.50),
('2025-04-18', 'Sexta-feira Santa', 'nacional', 2.00),
('2025-04-21', 'Tiradentes', 'nacional', 2.00),
('2025-05-01', 'Dia do Trabalho', 'nacional', 2.00),
('2025-06-19', 'Corpus Christi', 'ponto_facultativo', 1.50),
('2025-09-07', 'Independência do Brasil', 'nacional', 2.00),
('2025-10-12', 'Nossa Senhora Aparecida', 'nacional', 2.00),
('2025-11-02', 'Finados', 'nacional', 2.00),
('2025-11-15', 'Proclamação da República', 'nacional', 2.00),
('2025-11-20', 'Consciência Negra', 'nacional', 2.00),
('2025-12-25', 'Natal', 'nacional', 2.00);
```

---

## 8. CHECKLIST DE IMPLEMENTAÇÃO

### Fase 1: Banco de Dados
- [ ] Criar tabela `wp_sistur_holidays`
- [ ] Criar tabela `wp_sistur_timebank_deductions`
- [ ] Criar tabela `wp_sistur_employee_payment_config`
- [ ] Alterar `wp_sistur_payment_records` (adicionar colunas)
- [ ] Inserir novas permissões
- [ ] Atribuir permissões aos papéis
- [ ] Inserir feriados padrão

### Fase 2: Backend PHP
- [ ] Criar arquivo `includes/class-sistur-timebank-manager.php`
- [ ] Implementar todas as funções principais
- [ ] Implementar todos os AJAX handlers
- [ ] Adicionar `require_once` no `sistur.php` principal
- [ ] Testar endpoints AJAX via Postman/curl

### Fase 3: Frontend
- [ ] Criar `assets/js/timebank-manager.js`
- [ ] Implementar modal "Abater Banco de Horas"
- [ ] Implementar modal "Aprovar Abatimento"
- [ ] Implementar modal "Gerenciar Feriados"
- [ ] Implementar modal "Configuração de Pagamento"
- [ ] Adicionar botões nas páginas Time Tracking e Payments
- [ ] Adicionar lista de abatimentos pendentes

### Fase 4: Views Admin
- [ ] Criar view `admin/views/time-tracking/timebank-deductions.php`
- [ ] Adicionar tabela de abatimentos pendentes
- [ ] Adicionar filtros (por funcionário, tipo, status)
- [ ] Adicionar ações em massa (aprovar/rejeitar)

### Fase 5: Testes
- [ ] Testar abatimento por folga (integral e parcial)
- [ ] Testar abatimento por pagamento (integral e parcial)
- [ ] Testar cálculo de valores (dias úteis, fins de semana, feriados)
- [ ] Testar aprovação/rejeição
- [ ] Testar permissões (usuários sem permissão não devem acessar)
- [ ] Testar consistência do saldo após abatimento
- [ ] Testar auditoria (logs estão sendo gerados?)

### Fase 6: Documentação
- [ ] Atualizar manual do usuário
- [ ] Criar guia de configuração inicial
- [ ] Documentar fluxo de aprovação
- [ ] Criar FAQ

---

## 9. CONSIDERAÇÕES FINAIS

### 9.1 Pontos Importantes

1. **Transações**: Use transações MySQL ao registrar pagamentos para garantir consistência
2. **Auditoria**: Todos os abatimentos são auditados (quem criou, quando, valores antes/depois)
3. **Aprovação**: Sistema de aprovação obrigatório para evitar erros
4. **Flexibilidade**: Multiplicadores configuráveis por funcionário
5. **Histórico**: JSON detalhado do cálculo em `calculation_details`

### 9.2 Melhorias Futuras

1. **Relatórios**: Gerar relatórios de abatimentos por período
2. **Notificações**: Email/push quando houver abatimento pendente
3. **Integração Contábil**: Exportar para sistemas de folha de pagamento
4. **Regras Automáticas**: Configurar aprovação automática para valores pequenos
5. **App Mobile**: Permitir funcionários solicitarem folgas pelo app

### 9.3 Performance

- Indexação adequada nas tabelas (`employee_id`, `approval_status`, datas)
- Cache de saldos (considerar criar coluna `current_balance` em `employees`)
- Paginação nas listas de abatimentos

---

**FIM DA DOCUMENTAÇÃO**
