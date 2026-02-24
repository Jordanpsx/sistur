# 🕐 Banco de Horas - Documentação Completa

## ✅ Status da Implementação

**O SISTEMA DE BANCO DE HORAS JÁ ESTÁ TOTALMENTE IMPLEMENTADO E FUNCIONANDO!**

O sistema calcula automaticamente o saldo do banco de horas comparando o tempo trabalhado com a carga horária esperada do funcionário.

---

## 📊 Como Funciona o Cálculo

### 1. **Carga Horária do Funcionário**

O sistema busca a carga horária esperada em ordem de prioridade:

1. **Prioridade 1**: Carga do tipo de contrato (`sistur_contract_types.carga_horaria_diaria_minutos`)
2. **Prioridade 2**: Carga específica do funcionário (`sistur_employees.time_expected_minutes`)
3. **Prioridade 3**: Padrão CLT de 480 minutos (8 horas)

**Código**: `class-sistur-punch-processing.php:971-985`

```php
private function get_expected_minutes($employee) {
    $expected_minutes = 480; // Padrão CLT: 8 horas

    // Prioridade 1: Carga horária do tipo de contrato
    if (!empty($employee->carga_horaria_diaria_minutos) && intval($employee->carga_horaria_diaria_minutos) > 0) {
        $expected_minutes = intval($employee->carga_horaria_diaria_minutos);
    }
    // Prioridade 2: time_expected_minutes do funcionário
    elseif (!empty($employee->time_expected_minutes) && intval($employee->time_expected_minutes) > 0) {
        $expected_minutes = intval($employee->time_expected_minutes);
    }

    return $expected_minutes;
}
```

---

### 2. **Cálculo do Tempo Trabalhado**

O sistema utiliza o **Algoritmo 1-2-3-4** que exige EXATAMENTE 4 batidas por dia:

1. **Batida 1**: Entrada (clock_in)
2. **Batida 2**: Início do almoço (lunch_start)
3. **Batida 3**: Fim do almoço (lunch_end)
4. **Batida 4**: Saída (clock_out)

**Fórmula**:
```
Tempo Trabalhado = (Batida2 - Batida1) + (Batida4 - Batida3)
```

**Código**: `class-sistur-punch-processing.php:398-400`

```php
// Calcular intervalos trabalhados
$intervalo_manha_minutos = ($punch_2 - $punch_1) / 60;
$intervalo_tarde_minutos = ($punch_4 - $punch_3) / 60;
$total_trabalhado_minutos = $intervalo_manha_minutos + $intervalo_tarde_minutos;
```

---

### 3. **Cálculo do Saldo do Banco de Horas**

**Fórmula**:
```
Saldo = Tempo Trabalhado - Carga Horária Esperada
```

**Código**: `class-sistur-punch-processing.php:406`

```php
// Calcular saldo do dia
$saldo_calculado_minutos = $total_trabalhado_minutos - $expected_minutes;
```

---

## 🎯 Interpretação dos Resultados

| Saldo | Significado | Exemplo |
|-------|------------|---------|
| **Saldo = 0** | ✅ Funcionário trabalhou **exatamente** a carga horária esperada | Esperado: 8h, Trabalhado: 8h, Saldo: 0h |
| **Saldo > 0 (positivo)** | ⬆️ Funcionário trabalhou **MAIS** que o esperado (hora extra) | Esperado: 8h, Trabalhado: 9h, Saldo: +1h |
| **Saldo < 0 (negativo)** | ⬇️ Funcionário trabalhou **MENOS** que o esperado (falta/atraso) | Esperado: 8h, Trabalhado: 7h, Saldo: -1h |

---

## 📝 Exemplos Práticos

### Exemplo 1: Saldo ZERO (0) ✅

**Carga horária esperada**: 480 minutos (8 horas)

```
Entrada:        08:00
Início almoço:  12:00  → Manhã: 4h
Fim almoço:     13:00
Saída:          17:00  → Tarde: 4h

Tempo trabalhado: 4h + 4h = 8h = 480 min
Saldo: 480 - 480 = 0 min ✅ (em dia)
```

---

### Exemplo 2: Saldo POSITIVO (+1h) ⬆️

**Carga horária esperada**: 480 minutos (8 horas)

```
Entrada:        08:00
Início almoço:  12:00  → Manhã: 4h
Fim almoço:     13:00
Saída:          18:00  → Tarde: 5h

Tempo trabalhado: 4h + 5h = 9h = 540 min
Saldo: 540 - 480 = +60 min (+1h) ⬆️ (hora extra)
```

---

### Exemplo 3: Saldo NEGATIVO (-1h) ⬇️

**Carga horária esperada**: 480 minutos (8 horas)

```
Entrada:        09:00
Início almoço:  12:00  → Manhã: 3h
Fim almoço:     13:00
Saída:          17:00  → Tarde: 4h

Tempo trabalhado: 3h + 4h = 7h = 420 min
Saldo: 420 - 480 = -60 min (-1h) ⬇️ (faltou 1 hora)
```

---

## 🗄️ Estrutura de Dados

### Tabela: `wp_sistur_time_entries` (Batidas)

| Campo | Descrição |
|-------|-----------|
| `employee_id` | ID do funcionário |
| `punch_type` | Tipo: clock_in, lunch_start, lunch_end, clock_out |
| `punch_time` | Data/hora da batida |
| `shift_date` | Data do turno |
| `processing_status` | PENDENTE ou PROCESSADO |

### Tabela: `wp_sistur_time_days` (Dias Processados)

| Campo | Descrição |
|-------|-----------|
| `employee_id` | ID do funcionário |
| `shift_date` | Data da jornada |
| `minutos_trabalhados` | ✅ Total de minutos trabalhados |
| `saldo_calculado_minutos` | ✅ Saldo do dia (trabalhado - esperado) |
| `saldo_final_minutos` | ✅ Saldo final (calculado + ajuste manual) |
| `needs_review` | Flag se precisa revisão (≠ 4 batidas) |
| `status` | present, absence_no_pay, holiday, etc. |

---

## 🔄 Como o Processamento Funciona

### Processamento Automático

- **Quando**: Diariamente às 01:00 (configurável)
- **Como**: WP-Cron job (`sistur_nightly_processing`)
- **O que processa**: Batidas de ONTEM com status PENDENTE
- **Código**: `class-sistur-punch-processing.php:294-341`

### Processamento Manual

```bash
# Via API REST
GET /wp-json/sistur/v1/cron/process
```

**Código**: `class-sistur-punch-processing.php:260-271`

---

## 📱 APIs Disponíveis

### 1. Saldo Total Acumulado

```bash
GET /wp-json/sistur/v1/balance/{employee_id}
```

**Resposta**:
```json
{
  "user_id": 123,
  "total_banco_horas_minutos": -120,
  "formatted": "-02:00"
}
```

### 2. Dados Semanais (Segunda a Sexta)

```bash
GET /wp-json/sistur/v1/time-bank/{employee_id}/weekly?week=2025-11-18
```

**Resposta**:
```json
{
  "employee_id": 123,
  "employee_name": "João Silva",
  "week_start": "2025-11-18",
  "week_end": "2025-11-22",
  "days": [
    {
      "date": "2025-11-18",
      "day_name": "Segunda-feira",
      "worked_minutes": 480,
      "expected_minutes": 480,
      "deviation_minutes": 0,
      "deviation_formatted": "+0h"
    }
  ],
  "summary": {
    "total_worked_minutes": 2400,
    "total_worked_formatted": "40h",
    "total_expected_minutes": 2400,
    "total_expected_formatted": "40h",
    "week_deviation_minutes": 0,
    "week_deviation_formatted": "+0h",
    "accumulated_bank_minutes": 120,
    "accumulated_bank_formatted": "+2h"
  }
}
```

### 3. Dados Mensais

```bash
GET /wp-json/sistur/v1/time-bank/{employee_id}/monthly?month=2025-11
```

---

## 🖥️ Interfaces do Usuário

### 1. Página Completa do Banco de Horas

- **URL**: `/banco-de-horas/`
- **Arquivo**: `templates/banco-de-horas.php`
- **Recursos**:
  - Saldo total acumulado
  - Filtros: Semanal, Mensal, Personalizado
  - Gráfico de barras (Chart.js)
  - Tabela detalhada com batidas
  - Export PDF

### 2. Widget Semanal (Painel do Funcionário)

- **Arquivo**: `templates/components/time-bank-widget.php`
- **Recursos**:
  - Resumo de 4 cards (Trabalhadas, Esperadas, Saldo Semana, Banco Total)
  - Tabela compacta (segunda a sexta)
  - Navegação entre semanas

---

## ⚙️ Configurações do Sistema

As configurações são armazenadas em `sistur_settings`:

```php
'auto_processing_enabled'      => true,    // Ativar processamento automático
'processing_time'              => "01:00", // Hora do processamento noturno
'processing_batch_size'        => 50,      // Funcionários por lote
'tolerance_minutes_per_punch'  => 5,       // Tolerância por batida (minutos)
'tolerance_type'               => "PER_PUNCH", // ou "DAILY"
```

---

## 🔍 Diagnóstico e Testes

### Script de Diagnóstico

Execute o script de teste completo:

```bash
php test-banco-horas-completo.php
```

Este script verifica:
1. ✅ Funcionários e suas cargas horárias
2. ✅ Registros de ponto recentes
3. ✅ Dias processados e saldos calculados
4. ✅ Saldo total acumulado
5. ✅ Interpretação dos resultados

### Verificar Manualmente no Banco de Dados

```sql
-- Saldo total de um funcionário
SELECT SUM(saldo_final_minutos) as saldo_total
FROM wp_sistur_time_days
WHERE employee_id = 123
  AND status = 'present'
  AND needs_review = 0;

-- Dias processados recentes
SELECT shift_date, minutos_trabalhados, saldo_calculado_minutos, saldo_final_minutos
FROM wp_sistur_time_days
WHERE employee_id = 123
ORDER BY shift_date DESC
LIMIT 10;
```

---

## 📂 Arquivos Principais

| Arquivo | Função |
|---------|--------|
| `includes/class-sistur-punch-processing.php` | **Classe principal** - Lógica de processamento e cálculo |
| `includes/class-sistur-time-tracking.php` | Gerenciamento de registros de ponto |
| `includes/class-sistur-employees.php` | Gerenciamento de funcionários |
| `templates/banco-de-horas.php` | Interface completa do banco de horas |
| `templates/components/time-bank-widget.php` | Widget semanal |
| `test-banco-horas-completo.php` | Script de teste e diagnóstico |

---

## 🎓 Fluxo Completo

```
1. Funcionário bate ponto (4 batidas)
   └─> Armazenado em wp_sistur_time_entries (status: PENDENTE)

2. Processamento noturno (01:00) ou manual
   └─> Busca batidas PENDENTES de ontem
   └─> Para cada funcionário:
       ├─> Busca carga horária esperada (contrato > funcionário > 480 min)
       ├─> Valida: tem 4 batidas?
       │   ├─> SIM: Calcula tempo trabalhado
       │   │   └─> Saldo = Trabalhado - Esperado
       │   │   └─> Salva em wp_sistur_time_days
       │   └─> NÃO: Marca para revisão (needs_review=1)
       └─> Marca batidas como PROCESSADO

3. Consulta via API ou interface
   └─> Busca dados de wp_sistur_time_days
   └─> Calcula saldo total acumulado
   └─> Exibe para o usuário
```

---

## ✅ Checklist de Funcionamento

Para garantir que o banco de horas está funcionando:

- [ ] Funcionários têm carga horária configurada (tipo de contrato ou time_expected_minutes)
- [ ] Há batidas registradas (4 batidas por dia)
- [ ] Processamento está habilitado (`auto_processing_enabled = true`)
- [ ] WP-Cron está ativo OU executar manualmente `/wp-json/sistur/v1/cron/process`
- [ ] Verificar dados em `wp_sistur_time_days`
- [ ] Testar API: `/wp-json/sistur/v1/balance/{employee_id}`

---

## 🐛 Troubleshooting

### Problema: "Não há dados de banco de horas"

**Solução**:
1. Verificar se há batidas registradas: `SELECT * FROM wp_sistur_time_entries WHERE processing_status = 'PENDENTE'`
2. Executar processamento manual: `GET /wp-json/sistur/v1/cron/process`
3. Verificar se funcionário tem carga horária configurada
4. Verificar se tem EXATAMENTE 4 batidas por dia

### Problema: "Saldo sempre 0"

**Solução**:
1. Verificar carga horária do funcionário
2. Verificar se batidas estão corretas (4 batidas em ordem)
3. Verificar se o processamento está rodando
4. Executar script de diagnóstico: `php test-banco-horas-completo.php`

### Problema: "Dias marcados para revisão"

**Motivo**: Não tem EXATAMENTE 4 batidas
**Solução**: Garantir que funcionário bata ponto 4 vezes: entrada, início almoço, fim almoço, saída

---

## 📖 Referências Rápidas

**Linha do código - Cálculo do saldo**:
- `class-sistur-punch-processing.php:406` - Fórmula do saldo
- `class-sistur-punch-processing.php:398-400` - Cálculo do tempo trabalhado
- `class-sistur-punch-processing.php:971-985` - Busca carga horária esperada
- `class-sistur-punch-processing.php:344-462` - Algoritmo completo 1-2-3-4

**APIs**:
- Saldo total: `/wp-json/sistur/v1/balance/{employee_id}`
- Semanal: `/wp-json/sistur/v1/time-bank/{employee_id}/weekly`
- Mensal: `/wp-json/sistur/v1/time-bank/{employee_id}/monthly`
- Processar: `/wp-json/sistur/v1/cron/process`

---

**Última atualização**: 18/11/2025
