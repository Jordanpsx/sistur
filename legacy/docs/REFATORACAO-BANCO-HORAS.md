# Refatoração do Sistema de Banco de Horas

## Resumo Executivo

Este documento descreve as alterações realizadas no sistema de banco de horas do SISTUR, migrandode um modelo de processamento em lote noturno (WP-Cron) para um modelo de processamento síncrono em tempo real.

## Objetivos Alcançados

1. ✅ Processamento imediato ao registrar batida de ponto
2. ✅ Recálculo automático ao editar folha de ponto (criar, atualizar ou deletar)
3. ✅ Contador em tempo real no frontend
4. ✅ Remoção do WP-Cron
5. ✅ Exibição de horas extras no formato hh:mm
6. ✅ Endpoint para reprocessamento em massa
7. ✅ Testes automatizados

## Arquivos Modificados

### 1. `/includes/class-sistur-punch-processing.php`

**Alterações:**
- Removido agendamento e funções do WP-Cron (`schedule_nightly_processing`, `process_yesterday_punches`, `continue_batch_processing`)
- Melhorado retorno do endpoint `api_punch` para incluir dados detalhados do banco de horas
- Adicionado campo `last_punch_time` no retorno do endpoint `api_get_current_status`
- Mantido endpoint `api_reprocess_days` para reprocessamento em massa

**Código-chave:**
```php
// Após registrar batida, processa imediatamente
$this->process_employee_day($employee->id, $shift_date);

// Retorna dados completos incluindo horas extras
return new WP_REST_Response(array(
    'success' => true,
    'data' => array(
        'day_summary' => array(
            'worked_minutes' => $worked_minutes,
            'overtime_minutes' => $overtime_minutes,
            'overtime_formatted' => $this->format_minutes($overtime_minutes)
        ),
        'punches' => $punches
    )
), 201);
```

### 2. `/includes/class-sistur-time-tracking.php`

**Alterações:**
- Adicionado recálculo automático em `ajax_save_entry()` (criar registro)
- Adicionado recálculo automático em `ajax_update_entry()` (editar registro)
- Adicionado recálculo automático em `ajax_delete_entry()` (deletar registro)

**Código-chave:**
```php
// RECÁLCULO AUTOMÁTICO após qualquer alteração
if (class_exists('SISTUR_Punch_Processing')) {
    $processor = SISTUR_Punch_Processing::get_instance();
    $processor->process_employee_day($employee_id, $shift_date);
    error_log("SISTUR: Banco de horas recalculado...");
}
```

### 3. `/templates/painel-funcionario-clock.php`

**Alterações:**
- Implementado contador em tempo real usando JavaScript
- Contador atualiza localmente a cada minuto
- Sincronização com servidor a cada 30 segundos
- Cálculo de tempo trabalhado considerando batidas abertas

**Código-chave:**
```javascript
// Variáveis globais para contador
let lastPunchTime = null;
let lastWorkedMinutes = 0;
let expectedMinutes = <?php echo $carga_horaria; ?>;

// Atualizar contador local a cada minuto
function updateRealtimeCounter() {
    if (!lastPunchTime) return;
    
    // Se há batida ímpar (funcionário trabalhando), adicionar tempo
    if (todayEntries.length % 2 === 1) {
        const now = new Date();
        const diffMinutes = Math.floor((now - lastPunch) / 60000);
        totalMinutes = lastWorkedMinutes + diffMinutes;
    }
    
    // Atualizar display
    $('#today-worked').text(formatMinutes(totalMinutes));
}

// Atualizar a cada minuto
setInterval(updateRealtimeCounter, 60000);
```

## Funcionalidades Implementadas

### 1. Processamento Síncrono

**Antes:**
- Batidas ficavam pendentes até o processamento noturno
- Possibilidade de atrasos se o site não tivesse tráfego
- Dados defasados para o usuário

**Depois:**
- Processamento imediato ao registrar batida
- Dados sempre atualizados
- Feedback instantâneo ao usuário

### 2. Recálculo Automático

**Trigger de Recálculo:**
- Ao criar novo registro de ponto (admin)
- Ao editar registro existente (admin)
- Ao deletar registro (admin)
- Ao registrar batida via QR code ou app

**Escopo:**
- Recalcula apenas o dia específico do funcionário afetado
- Não impacta outros funcionários ou dias

### 3. Contador em Tempo Real

**Funcionamento:**
- Busca última batida do servidor
- Calcula tempo localmente usando relógio do navegador
- Atualiza display a cada minuto
- Sincroniza com servidor a cada 30 segundos

**Informações Exibidas:**
- Horas trabalhadas no dia (atualização em tempo real)
- Desvio em relação à jornada esperada (positivo/negativo)
- Indicador visual (verde/vermelho/neutro)

### 4. Formato de Horas Extras

**Especificação:**
- Formato: `Xh YY` onde X são as horas e YY os minutos (sempre com 2 dígitos)
- Exemplos:
  - 122 minutos → `2h02`
  - 90 minutos → `1h30`
  - 30 minutos → `30min`

**Cálculo:**
```php
$expected_minutes = 480; // 8 horas
$worked_minutes = 500;   // 8h20
$overtime_minutes = max(0, $worked_minutes - $expected_minutes); // 20 min
```

## Endpoints da API

### GET `/wp-json/sistur/v1/time-bank/{employee_id}/current`

Retorna status do dia atual em tempo real.

**Resposta:**
```json
{
    "employee_id": 123,
    "date": "2025-11-26",
    "punch_count": 2,
    "worked_minutes": 180,
    "worked_formatted": "3h00",
    "expected_minutes": 480,
    "expected_formatted": "8h00",
    "deviation_minutes": -300,
    "deviation_formatted": "-5h00",
    "working_now": true,
    "is_working": true,
    "last_punch_time": "2025-11-26 12:00:00"
}
```

### POST `/wp-json/sistur/v1/reprocess`

Reprocessa dias específicos de um ou todos funcionários.

**Requisição:**
```json
{
    "employee_id": 123,       // opcional
    "start_date": "2025-11-01",
    "end_date": "2025-11-30"
}
```

**Resposta:**
```json
{
    "success": true,
    "message": "Reprocessamento concluído. 30 dia(s) processado(s).",
    "processed_count": 30
}
```

## Testes Automatizados

Arquivo: `/tests/test-banco-horas-refactoring.php`

**Cobertura de Testes:**

1. ✅ Processamento síncrono ao registrar ponto
2. ✅ Cálculo correto de horas extras
3. ✅ Recálculo automático ao editar folha
4. ✅ Formato de exibição de horas extras
5. ✅ Algoritmo de pares fechados
6. ✅ Endpoint de reprocessamento em massa

**Executar Testes:**
```bash
# Via PHPUnit
cd /path/to/sistur2-main
phpunit tests/test-banco-horas-refactoring.php

# Via WP-CLI
wp plugin test sistur --filter=SISTUR_Banco_Horas_Test
```

## Como Reprocessar Dados Antigos

Para reprocessar todos os registros existentes no sistema:

**Opção 1: Via API REST**
```bash
curl -X POST https://seu-site.com/wp-json/sistur/v1/reprocess \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "start_date": "2025-01-01",
    "end_date": "2025-11-26"
  }'
```

**Opção 2: Via PHP (executar no WordPress)**
```php
$processor = SISTUR_Punch_Processing::get_instance();
$request = new WP_REST_Request('POST', '/sistur/v1/reprocess');
$request->set_param('start_date', '2025-01-01');
$request->set_param('end_date', '2025-11-26');
$response = $processor->api_reprocess_days($request);
```

**Opção 3: Reprocessar funcionário específico**
```php
$processor = SISTUR_Punch_Processing::get_instance();
$employee_id = 123;
$start_date = '2025-01-01';
$end_date = '2025-11-26';

$current = strtotime($start_date);
$end = strtotime($end_date);

while ($current <= $end) {
    $date = date('Y-m-d', $current);
    $processor->process_employee_day($employee_id, $date);
    $current = strtotime('+1 day', $current);
}
```

## Impacto no Sistema

### Performance

**Antes:**
- Processamento em lote noturno (alta carga pontual)
- Possível timeout em empresas com muitos funcionários

**Depois:**
- Processamento distribuído ao longo do dia
- Carga reduzida e mais previsível
- Cada ação processa apenas 1 funcionário/1 dia

### Experiência do Usuário

**Antes:**
- Dados defasados (até 24h de atraso)
- Sem feedback imediato

**Depois:**
- Dados em tempo real
- Contador atualizado a cada minuto
- Feedback instantâneo ao bater ponto

### Manutenibilidade

**Antes:**
- Lógica distribuída entre cron e processamento
- Difícil debug de problemas

**Depois:**
- Processamento centralizado e determinístico
- Mais fácil rastrear e corrigir problemas
- Logs detalhados de cada operação

## Notas Importantes

1. **WP-Cron Removido:** O sistema não depende mais do WP-Cron do WordPress
2. **Compatibilidade:** Dados antigos são compatíveis e podem ser reprocessados
3. **Auditoria:** Todas as alterações continuam sendo registradas no log de auditoria
4. **Transações:** Processamento usa transações SQL para garantir consistência
5. **Algoritmo:** Mantém o algoritmo de "pares fechados" para cálculo de horas

## Próximos Passos (Opcional)

1. Adicionar cache para consultas frequentes
2. Implementar notificações push ao completar jornada
3. Dashboard analítico com gráficos de evolução
4. Exportação de relatórios em PDF/Excel
5. Integração com sistemas de folha de pagamento

## Suporte e Manutenção

Para dúvidas ou problemas:
1. Verificar logs: `wp-content/debug.log`
2. Executar testes automatizados
3. Consultar documentação da API REST
4. Verificar consistência dos dados via endpoint de reprocessamento

---

**Versão:** 2.0  
**Data:** 26/11/2025  
**Autor:** Sistema SISTUR
