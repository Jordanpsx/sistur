# Guia: Escalas Semanais (Weekly Patterns)

## 🎯 O Problema Resolvido

**ANTES:** Escalas fixas não contemplavam horas diferentes por dia da semana.
- ❌ 6x1 = 6 dias de 8h (todos os dias iguais)
- ❌ Não havia como ter "5 dias de 8h + 1 dia de 4h"
- ❌ Banco de horas calculava errado para escalas irregulares

**DEPOIS:** Pattern weekly permite horas diferentes por dia da semana.
- ✅ Segunda a Sexta 8h + Sábado 4h = 44h semanais
- ✅ Cada dia pode ter expectativa diferente
- ✅ Banco de horas dinâmico e correto

---

## 📊 Como Funciona

### **Schema JSON do Pattern Weekly**

```json
{
  "pattern_type": "weekly",
  "weekly": {
    "Monday": {
      "type": "work",
      "expected_minutes": 480,
      "lunch_minutes": 60
    },
    "Tuesday": {
      "type": "work",
      "expected_minutes": 480,
      "lunch_minutes": 60
    },
    "Wednesday": {
      "type": "work",
      "expected_minutes": 480,
      "lunch_minutes": 60
    },
    "Thursday": {
      "type": "work",
      "expected_minutes": 480,
      "lunch_minutes": 60
    },
    "Friday": {
      "type": "work",
      "expected_minutes": 480,
      "lunch_minutes": 60
    },
    "Saturday": {
      "type": "work",
      "expected_minutes": 240,
      "lunch_minutes": 0
    },
    "Sunday": {
      "type": "rest",
      "expected_minutes": 0,
      "lunch_minutes": 0
    }
  },
  "description": "44h semanais: Segunda a Sexta 8h + Sábado 4h"
}
```

---

## 🔧 Criar Escalas Semanais

### **Opção 1: Via Interface Admin**

1. Acesse: `/wp-admin/admin.php?page=sistur-create-weekly-patterns`
2. Clique em: **"Criar/Atualizar Escalas Semanais"**
3. Sistema cria automaticamente:
   - ✅ Escala 44h (5x8h + 1x4h)
   - ✅ Escala 40h (5x8h)
   - ✅ Escala 36h (6x6h)

### **Opção 2: Via Código PHP**

```php
require_once SISTUR_PLUGIN_DIR . 'includes/create-weekly-patterns.php';
$result = sistur_create_weekly_shift_patterns();
// Retorna: ['created' => X, 'updated' => Y]
```

### **Opção 3: Inserir Manualmente no Banco**

```sql
INSERT INTO wp_sistur_shift_patterns
(name, description, pattern_type, pattern_config, status, weekly_hours_minutes)
VALUES (
  'Escala 44h Semanais',
  'Segunda a Sexta 8h + Sábado 4h',
  'weekly_rotation',
  '{
    "pattern_type": "weekly",
    "weekly": {
      "Monday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
      "Tuesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
      "Wednesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
      "Thursday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
      "Friday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
      "Saturday": {"type": "work", "expected_minutes": 240, "lunch_minutes": 0},
      "Sunday": {"type": "rest", "expected_minutes": 0}
    }
  }',
  1,
  2640
);
```

---

## 💡 Exemplos de Uso

### **Exemplo 1: 44h Semanais (CLT Padrão)**
```
Segunda a Sexta: 8h (480 min)
Sábado: 4h (240 min)
Domingo: Folga
Total: 44h semanais
```

**Vínculo ao Funcionário:**
```php
$shift_patterns = SISTUR_Shift_Patterns::get_instance();
$shift_patterns->save_employee_schedule(array(
    'employee_id' => 123,
    'shift_pattern_id' => $pattern_44h_id,
    'start_date' => '2025-01-01',
    'is_active' => 1
));
```

**Cálculo Automático:**
```
Segunda 08/01: Funcionário trabalhou 8h → Expectativa 8h → Saldo: 0
Terça 09/01: Funcionário trabalhou 9h → Expectativa 8h → Saldo: +1h
Sábado 13/01: Funcionário trabalhou 5h → Expectativa 4h → Saldo: +1h
Domingo 14/01: Funcionário não trabalhou → Expectativa 0h → Saldo: 0 (folga)
```

---

### **Exemplo 2: 40h Semanais (Comércio)**
```
Segunda a Sexta: 8h
Finais de semana: Folga
Total: 40h semanais
```

```json
{
  "pattern_type": "weekly",
  "weekly": {
    "Monday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Tuesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Wednesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Thursday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Friday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Saturday": {"type": "rest", "expected_minutes": 0},
    "Sunday": {"type": "rest", "expected_minutes": 0}
  }
}
```

---

### **Exemplo 3: 36h Semanais (Meio Período)**
```
Segunda a Sábado: 6h
Domingo: Folga
Total: 36h semanais
```

```json
{
  "pattern_type": "weekly",
  "weekly": {
    "Monday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Tuesday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Wednesday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Thursday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Friday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Saturday": {"type": "work", "expected_minutes": 360, "lunch_minutes": 15},
    "Sunday": {"type": "rest", "expected_minutes": 0}
  }
}
```

---

### **Exemplo 4: Escala Personalizada (Hotel)**
```
Segunda: 10h (check-out)
Terça a Quinta: 8h
Sexta: 10h (check-in)
Sábado e Domingo: Folga
Total: 44h semanais
```

```json
{
  "pattern_type": "weekly",
  "weekly": {
    "Monday": {"type": "work", "expected_minutes": 600, "lunch_minutes": 60},
    "Tuesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Wednesday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Thursday": {"type": "work", "expected_minutes": 480, "lunch_minutes": 60},
    "Friday": {"type": "work", "expected_minutes": 600, "lunch_minutes": 60},
    "Saturday": {"type": "rest", "expected_minutes": 0},
    "Sunday": {"type": "rest", "expected_minutes": 0}
  }
}
```

---

## 🔄 Fluxo de Cálculo

### **1. Funcionário Registra Ponto**
```
Funcionário: João (Escala 44h semanais)
Data: Segunda, 08/01/2025
Punches: 08:00, 12:00, 13:00, 17:00
```

### **2. Sistema Calcula Expectativa**
```php
$shift_patterns = SISTUR_Shift_Patterns::get_instance();
$expected = $shift_patterns->get_expected_hours_for_date(123, '2025-01-08');

// Resultado:
// array(
//     'expected_minutes' => 480,  // Segunda = 8h
//     'lunch_minutes' => 60,
//     'is_work_day' => true,
//     'pattern_type' => 'weekly',
//     'day_of_week' => 'Monday'
// )
```

### **3. Calcula Horas Trabalhadas**
```
Entrada 1: 08:00 → Saída 1: 12:00 = 4h
Entrada 2: 13:00 → Saída 2: 17:00 = 4h
Total: 8h (480 minutos)
```

### **4. Calcula Banco de Horas**
```
Trabalhado: 480 min
Esperado: 480 min (segunda-feira da escala 44h)
Saldo: 0 min
```

### **5. Exemplo com Sábado**
```
Data: Sábado, 13/01/2025
Expectativa: 240 min (4h conforme escala)
Trabalhou: 300 min (5h)
Saldo: +60 min (+1h extra)
```

---

## 📋 Diferenças entre Pattern Types

| Pattern Type | Quando Usar | Exemplo |
|--------------|-------------|---------|
| **weekly** | Horas diferentes por dia da semana | 5x8h + 1x4h = 44h |
| **cycle** | Ciclos fixos (12x36, plantões) | 1 dia 12h + 1 dia folga |
| **fixed_days** | Todos os dias iguais (6x1, 5x2) | 6 dias 8h + 1 folga |
| **flexible_hours** | Sem expectativa diária, apenas semanal | 44h/semana livre |

---

## 🧪 Testes

### **Teste 1: Criar Escala 44h**
```bash
1. Acesse: /wp-admin/admin.php?page=sistur-create-weekly-patterns
2. Clique em "Criar/Atualizar Escalas Semanais"
3. Verifique: SELECT * FROM wp_sistur_shift_patterns WHERE name LIKE '%44h%'
```

### **Teste 2: Vincular Funcionário**
```bash
1. Funcionários → Editar
2. Escala: Selecionar "Escala 44h Semanais (5x8h + 1x4h)"
3. Salvar
4. Verificar: SELECT * FROM wp_sistur_employee_schedules WHERE employee_id = X
```

### **Teste 3: Testar Cálculo**
```bash
1. Funcionário registra ponto na SEGUNDA:
   - 08:00, 12:00, 13:00, 17:00 (8h trabalhadas)
2. Verificar expectativa:
   - Esperado: 480 min (8h)
   - Saldo: 0

3. Funcionário registra ponto no SÁBADO:
   - 08:00, 12:00 (4h trabalhadas)
4. Verificar expectativa:
   - Esperado: 240 min (4h)
   - Saldo: 0

5. Funcionário NÃO trabalha no DOMINGO:
   - Expectativa: 0 min (folga)
   - Saldo: 0 (correto, é dia de folga)
```

---

## ⚙️ Arquivos Modificados

- `includes/class-sistur-shift-patterns.php`: Adicionado método `calculate_from_weekly_pattern()`
- `includes/create-weekly-patterns.php`: NOVO - Script para criar escalas semanais
- `sistur.php`: Registrado create-weekly-patterns.php

---

## 🎊 Benefícios

✅ **Flexibilidade Total:** Cada dia da semana pode ter horas diferentes
✅ **Banco de Horas Correto:** Cálculo dinâmico por dia
✅ **Folgas Automáticas:** Dias de folga reconhecidos automaticamente (0h esperadas)
✅ **CLT Compliant:** Suporta 44h semanais (5x8h + 1x4h)
✅ **Exceções:** Feriados sobrescrevem a escala normalmente

---

## 📞 Suporte

Para criar escalas personalizadas, edite o pattern_config manualmente ou use a interface admin:
`/wp-admin/admin.php?page=sistur-create-weekly-patterns`

**Versão:** 2.1.0
**Data:** 2025-12-02
