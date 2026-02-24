# Cálculo de Banco de Horas em Tempo Real

## Problema Identificado

Anteriormente, o banco de horas tinha um atraso de até 1 dia na visualização porque:

1. **Processamento imediato**: Quando um funcionário registrava um ponto, o sistema processava o dia imediatamente (linha 196)
2. **Visualização desatualizada**: Mas quando o funcionário acessava a página de banco de horas, as APIs apenas **consultavam** os dados já salvos, sem verificar se havia registros novos para processar

Isso causava o atraso, pois se um funcionário batesse o ponto e imediatamente visualizasse o banco de horas, o processamento poderia não ter sido concluído ainda, ou poderia haver falhas no processamento automático.

## Solução Implementada

Foi criado um sistema de **processamento sob demanda** que garante dados atualizados antes de exibir:

### 1. Nova Função Auxiliar: `process_period_if_needed()`

Localização: `includes/class-sistur-punch-processing.php` (linha 755)

```php
private function process_period_if_needed($employee_id, $start_date, $end_date)
```

**O que faz:**
- Busca datas com registros pendentes no período
- Verifica também se há registros sem processamento na tabela `sistur_time_days`
- Processa automaticamente todos os dias pendentes
- Retorna o número de dias processados

**Lógica:**
1. Primeiro busca datas com `processing_status = 'PENDENTE'`
2. Se não houver pendentes, busca datas que têm registros mas não têm entrada na tabela `sistur_time_days`
3. Processa cada dia encontrado usando `process_employee_day()`

### 2. APIs Atualizadas

#### A. `api_get_weekly_timebank()` - Linha 920
**Antes:** Apenas consultava os dados
**Depois:** Processa dias pendentes da semana antes de consultar

```php
// NOVO: Processar dias pendentes antes de consultar
$this->process_period_if_needed($employee_id, $week_start, $week_end);
```

#### B. `api_get_monthly_timebank()` - Linha 1060
**Antes:** Apenas consultava os dados
**Depois:** Processa dias pendentes do mês antes de consultar

```php
// NOVO: Processar dias pendentes antes de consultar
$this->process_period_if_needed($employee_id, $month_start, $month_end);
```

#### C. `api_get_balance()` - Linha 259
**Antes:** Apenas somava os saldos salvos
**Depois:** Processa últimos 90 dias antes de calcular

```php
// NOVO: Processar registros pendentes dos últimos 90 dias
$start_date = date('Y-m-d', strtotime('-90 days'));
$end_date = date('Y-m-d');
$this->process_period_if_needed($user_id, $start_date, $end_date);
```

## Benefícios

### 1. Visualização em Tempo Real
- Funcionário bate o ponto e **imediatamente** vê o banco de horas atualizado
- Não há mais espera de 1 dia pelo cron noturno

### 2. Processamento Inteligente
- Só processa o que é necessário (registros pendentes)
- Não reprocessa dados já calculados
- Performance otimizada

### 3. Garantia de Dados
- Mesmo se o processamento automático falhar, a visualização força o processamento
- Dados sempre consistentes e atualizados

### 4. Backup Mantido
- O cron noturno continua funcionando como backup
- Garante que todos os dados sejam processados eventualmente

## Fluxo Atualizado

### Antes:
```
1. Funcionário bate ponto → Processamento imediato tenta executar
2. Funcionário visualiza banco → Consulta dados salvos (pode estar desatualizado)
3. 01:00 AM → Cron noturno reprocessa tudo
```

### Depois:
```
1. Funcionário bate ponto → Processamento imediato executa
2. Funcionário visualiza banco → Verifica pendentes + Processa se necessário + Exibe atualizado
3. 01:00 AM → Cron noturno reprocessa como backup (se houver falhas)
```

## Performance

A solução é eficiente porque:

1. **Consulta rápida**: Primeiro verifica se há pendentes (query simples)
2. **Processamento seletivo**: Só processa o necessário
3. **Período limitado**:
   - Semanal: máximo 5 dias
   - Mensal: máximo ~22 dias úteis
   - Saldo total: últimos 90 dias
4. **Cache natural**: Dados já processados não são reprocessados

## Compatibilidade

As mudanças são **100% retrocompatíveis**:

- Não alteraram a estrutura do banco de dados
- Não modificaram as assinaturas das APIs
- Mantiveram todos os comportamentos existentes
- Adicionaram apenas processamento extra quando necessário

## Testes Recomendados

1. **Teste de tempo real**:
   - Bater ponto → Visualizar banco imediatamente
   - Verificar se o novo registro aparece

2. **Teste de múltiplos dias**:
   - Adicionar registros manualmente em dias anteriores
   - Visualizar banco e verificar se processa automaticamente

3. **Teste de performance**:
   - Medir tempo de resposta das APIs
   - Verificar logs de processamento

## Arquivos Modificados

- `includes/class-sistur-punch-processing.php`
  - Nova função: `process_period_if_needed()` (linha 755)
  - Modificada: `api_get_weekly_timebank()` (linha 920)
  - Modificada: `api_get_monthly_timebank()` (linha 1060)
  - Modificada: `api_get_balance()` (linha 259)

## Data da Implementação

19 de Janeiro de 2025

## Observações

- O processamento sob demanda é executado apenas quando necessário
- Não há impacto significativo na performance
- A solução resolve completamente o atraso de 1 dia reportado
- O cron noturno continua como backup para garantir consistência
