# SISTUR 2.0 - Guia de Atualização e Novas Funcionalidades

## 📌 Resumo das Mudanças

Esta atualização traz melhorias significativas no sistema de escalas e banco de horas do SISTUR:

### ✅ **Correções de Bugs**
1. **Vinculação de Escalas** - Corrigido bug crítico onde escalas selecionadas no cadastro de funcionários não eram salvas automaticamente

### 🚀 **Novas Funcionalidades**
1. **Sistema de Exceções** - Feriados, afastamentos, trocas de folga com aprovação
2. **Pattern Config Rico** - Suporte a escalas complexas (12x36, plantões irregulares, múltiplos turnos)
3. **Banco de Horas Multi-período** - Gestão de saldos com expiração e políticas de compensação
4. **Migração Automática** - Script para vincular funcionários legados a escalas

---

## 🗄️ **Novas Tabelas de Banco de Dados**

### 1. `wp_sistur_schedule_exceptions`
Gerencia exceções ao padrão de trabalho (feriados, afastamentos, folgas trocadas).

**Campos principais:**
- `exception_type`: holiday, sick_leave, vacation, day_off_trade, special_event, absence
- `custom_expected_minutes`: Minutos esperados customizados para o dia (0 = dispensado)
- `status`: pending, approved, rejected
- `traded_with_employee_id`: Para trocas de folga entre funcionários

**Exemplo de uso:**
```php
$exceptions = SISTUR_Schedule_Exceptions::get_instance();

// Criar feriado (Natal)
$exceptions->save_exception(array(
    'employee_id' => 123,
    'exception_type' => 'holiday',
    'date' => '2025-12-25',
    'custom_expected_minutes' => 0,
    'notes' => 'Natal'
));

// Criar troca de folga
$exceptions->save_exception(array(
    'employee_id' => 123,
    'exception_type' => 'day_off_trade',
    'date' => '2025-12-20',
    'custom_expected_minutes' => 480,
    'traded_with_employee_id' => 456,
    'status' => 'pending'
));
```

### 2. `wp_sistur_time_bank_periods`
Gerencia períodos de banco de horas com políticas de expiração.

**Campos principais:**
- `period_name`: Ex: "Banco 2025 - 1º Semestre"
- `balance_minutes`: Saldo acumulado (positivo = crédito, negativo = débito)
- `expiration_policy`: 6_months, 1_year, never
- `expires_at`: Data de expiração calculada
- `expiration_action`: lose, convert_to_payment, require_use

**Exemplo:**
```php
// Criar período de banco de horas
$wpdb->insert('wp_sistur_time_bank_periods', array(
    'employee_id' => 123,
    'period_name' => 'Banco 2025',
    'start_date' => '2025-01-01',
    'end_date' => '2025-12-31',
    'balance_minutes' => 0,
    'expiration_policy' => '1_year',
    'expires_at' => '2026-12-31',
    'status' => 'active'
));
```

### 3. `wp_sistur_time_bank_transactions`
Log de todas as transações de banco de horas (créditos, débitos, compensações).

**Campos principais:**
- `type`: accrual, deduction, compensation, expiration, adjustment, transfer
- `minutes`: Positivo = crédito, negativo = débito
- `source_type`: punch, manual, holiday, absence, trade, overtime
- `source_reference`: Referência ao registro origem (ex: "time_day_id:1234")

**Exemplo:**
```php
// Registrar compensação de banco de horas (folga concedida)
$wpdb->insert('wp_sistur_time_bank_transactions', array(
    'period_id' => 5,
    'employee_id' => 123,
    'transaction_date' => '2025-12-20',
    'type' => 'compensation',
    'minutes' => -480, // Débito de 8h
    'source_type' => 'manual',
    'notes' => 'Folga compensatória aprovada'
));
```

---

## 🔧 **Pattern Config Rico - Schema JSON**

Agora o campo `pattern_config` suporta configurações avançadas para escalas complexas.

### **Schema Simples (6x1, 5x2) - Mantido para compatibilidade**
```json
{
  "cycle_days": 7,
  "work_days": [1,2,3,4,5,6],
  "rest_days": [7]
}
```

### **Schema Rico (12x36, Plantões, Múltiplos Turnos)**
```json
{
  "cycle": {
    "length_days": 2,
    "sequence": [
      {
        "day": 1,
        "type": "work",
        "expected_minutes": 720,
        "shifts": [
          {
            "start": "07:00",
            "end": "19:00",
            "lunch_minutes": 60,
            "expected_minutes": 660
          }
        ]
      },
      {
        "day": 2,
        "type": "rest",
        "expected_minutes": 0
      }
    ]
  },
  "tolerances": {
    "late_entry_minutes": 15,
    "early_exit_minutes": 10,
    "lunch_variation_minutes": 15
  },
  "weekly_rules": {
    "min_weekly_minutes": 2400,
    "max_weekly_minutes": 2640
  }
}
```

### **Exemplo: Plantão Irregular (24h-48h-24h-72h)**
```json
{
  "cycle": {
    "length_days": 7,
    "sequence": [
      {"day": 1, "type": "work", "expected_minutes": 1440},
      {"day": 2, "type": "rest", "expected_minutes": 0},
      {"day": 3, "type": "rest", "expected_minutes": 0},
      {"day": 4, "type": "work", "expected_minutes": 1440},
      {"day": 5, "type": "rest", "expected_minutes": 0},
      {"day": 6, "type": "rest", "expected_minutes": 0},
      {"day": 7, "type": "rest", "expected_minutes": 0}
    ]
  }
}
```

---

## 📊 **Fluxo de Cálculo de Horas Esperadas (NOVO)**

### **Prioridade de Cálculo:**

```
1. EXCEÇÕES (SISTUR_Schedule_Exceptions)
   ↓ Se não há exceção...
2. ESCALA VINCULADA (SISTUR_Shift_Patterns)
   ├─ Pattern config rico (cycle.sequence)
   └─ Pattern config simples (cycle_days)
   ↓ Se não há escala...
3. FALLBACK (time_expected_minutes do funcionário)
   ↓ Se não configurado...
4. DEFAULT CLT (480 minutos = 8h)
```

### **Exemplo Prático:**

```php
$shift_patterns = SISTUR_Shift_Patterns::get_instance();
$expected = $shift_patterns->get_expected_hours_for_date(123, '2025-12-25');

// Se 25/12 é feriado cadastrado:
// $expected = array(
//     'expected_minutes' => 0,
//     'is_exception' => true,
//     'exception_type' => 'holiday',
//     'exception_notes' => 'Natal'
// )

// Se é dia normal em escala 12x36 (dia de trabalho):
// $expected = array(
//     'expected_minutes' => 720,
//     'lunch_minutes' => 60,
//     'is_work_day' => true,
//     'pattern_type' => 'cycle_based'
// )
```

---

## 🔄 **Migração de Dados Legados**

### **Executar Migração Automática:**

1. Acesse: `/wp-admin/admin.php?page=sistur-migrate-shifts`
2. Clique em "Executar Migração"

**O que a migração faz:**
- ✅ Vincula funcionários sem escala baseado em `time_expected_minutes`:
  - 720 min → Escala 12x36
  - 480 min → Escala 6x1 (8h/dia)
  - 360 min → Escala 5x2 (6h/dia)
  - 0 min → Horas Flexíveis
- ✅ Cria período de banco de horas ativo para cada funcionário
- ✅ Migra padrão 12x36 para `pattern_config` rico

### **Migração Manual (via PHP):**

```php
require_once SISTUR_PLUGIN_DIR . 'includes/migrations/migrate-shift-patterns.php';
$results = sistur_migrate_shift_patterns();

echo "Escalas criadas: " . $results['schedules_created'];
```

---

## 🛠️ **Mudanças para Desenvolvedores**

### **1. SISTUR_Employees::ajax_save_employee() - MODIFICADO**

Agora captura automaticamente `shift_pattern_id` e cria vinculação:

```php
// ANTES (bug):
// shift_pattern_id era ignorado, não salvava vinculação

// DEPOIS (corrigido):
$shift_pattern_id = isset($_POST['shift_pattern_id']) ? intval($_POST['shift_pattern_id']) : null;

if ($shift_pattern_id !== null) {
    $shift_patterns->save_employee_schedule(array(
        'employee_id' => $employee_id,
        'shift_pattern_id' => $shift_pattern_id,
        'start_date' => $hire_date ?: current_time('Y-m-d'),
        'is_active' => 1
    ));
}
```

### **2. SISTUR_Shift_Patterns::get_expected_hours_for_date() - EXPANDIDO**

Agora verifica exceções PRIMEIRO:

```php
// Pseudo-código:
function get_expected_hours_for_date($employee_id, $date) {
    // 1. Verificar exceções (feriado, atestado, etc.)
    $exception = SISTUR_Schedule_Exceptions::get_exception_for_date($employee_id, $date);
    if ($exception) {
        return array('expected_minutes' => $exception->custom_expected_minutes);
    }

    // 2. Verificar escala configurada
    $schedule = $this->get_employee_active_schedule($employee_id, $date);

    // 3. Calcular baseado em pattern_config (rico ou simples)
    return $this->calculate_expected_from_pattern($date, $schedule);
}
```

### **3. Nova Classe: SISTUR_Schedule_Exceptions**

**Métodos principais:**
- `get_exception_for_date($employee_id, $date)` - Obter exceção ativa para data
- `save_exception($data)` - Criar/atualizar exceção
- `approve_exception($exception_id)` - Aprovar exceção pendente
- `create_national_holidays($year, $employee_ids)` - Criar feriados em massa

**AJAX Endpoints:**
- `sistur_get_exceptions` - Listar exceções
- `sistur_save_exception` - Salvar exceção
- `sistur_approve_exception` - Aprovar/rejeitar

---

## 📝 **Exemplos de Uso**

### **Criar Exceção de Feriado**

```php
$exceptions = SISTUR_Schedule_Exceptions::get_instance();

// Criar feriados nacionais para todos os funcionários de 2025
$count = $exceptions->create_national_holidays(2025);
echo "Feriados criados: $count";
```

### **Criar Escala 12x36 com Pattern Config Rico**

```php
$shift_patterns = SISTUR_Shift_Patterns::get_instance();

$pattern_12x36 = array(
    'name' => 'Escala 12x36 (Bombeiros)',
    'pattern_type' => 'fixed_days',
    'work_days_count' => 1,
    'rest_days_count' => 1,
    'daily_hours_minutes' => 720,
    'pattern_config' => json_encode(array(
        'cycle' => array(
            'length_days' => 2,
            'sequence' => array(
                array(
                    'day' => 1,
                    'type' => 'work',
                    'expected_minutes' => 720,
                    'shifts' => array(
                        array(
                            'start' => '07:00',
                            'end' => '19:00',
                            'lunch_minutes' => 60,
                            'expected_minutes' => 660
                        )
                    )
                ),
                array('day' => 2, 'type' => 'rest', 'expected_minutes' => 0)
            )
        )
    ))
);

$pattern_id = $shift_patterns->save_shift_pattern($pattern_12x36);
```

### **Compensar Banco de Horas (Folga)**

```php
global $wpdb;

// 1. Criar exceção para o dia de folga compensatória
$exceptions->save_exception(array(
    'employee_id' => 123,
    'exception_type' => 'day_off_trade',
    'date' => '2025-12-20',
    'custom_expected_minutes' => 0,
    'status' => 'approved',
    'notes' => 'Folga compensatória - banco de horas'
));

// 2. Registrar transação de débito no banco de horas
$wpdb->insert('wp_sistur_time_bank_transactions', array(
    'period_id' => 5,
    'employee_id' => 123,
    'transaction_date' => '2025-12-20',
    'type' => 'compensation',
    'minutes' => -480,
    'source_type' => 'manual',
    'notes' => 'Folga compensatória aprovada'
));

// 3. Atualizar saldo do período
$wpdb->query("
    UPDATE wp_sistur_time_bank_periods
    SET balance_minutes = balance_minutes - 480
    WHERE id = 5
");
```

---

## ⚠️ **Breaking Changes**

### **Nenhuma Breaking Change Crítica**
- ✅ Sistema mantém compatibilidade com escalas antigas
- ✅ Funcionários sem escala continuam usando fallback (`time_expected_minutes`)
- ✅ Pattern config simples ainda funciona

### **Mudanças de Comportamento:**
1. **Exceções têm prioridade** sobre escalas configuradas
2. **Pattern config rico** é usado quando disponível (fallback para simples)
3. **Vinculação automática** ao salvar funcionário (antes era manual)

---

## 🎯 **Próximos Passos Recomendados**

1. **Execute a migração** - `/wp-admin/admin.php?page=sistur-migrate-shifts`
2. **Configure feriados** - Use `create_national_holidays(2025)` via PHP ou crie manualmente
3. **Revise escalas 12x36** - Migre para `pattern_config` rico se necessário
4. **Teste cálculos** - Verifique se expectativas estão corretas após migração
5. **Configure banco de horas** - Defina políticas de expiração por empresa

---

## 📞 **Suporte**

- **Documentação completa:** Ver análise detalhada no início deste commit
- **Logs de erro:** Verifique `wp-content/debug.log` para erros de migração
- **Rollback:** Em caso de problemas, restaure backup do banco de dados

---

## 🎉 **Conclusão**

Esta atualização prepara o SISTUR para cenários reais de gestão de ponto:
- ✅ Escalas complexas (12x36, plantões)
- ✅ Feriados e exceções gerenciadas
- ✅ Banco de horas com expiração
- ✅ Trocas de folga com aprovação

**Versão:** 2.0.0
**Data:** 2025-12-02
**Compatibilidade:** WordPress 5.8+, PHP 7.4+
