# Troubleshooting - Banco de Horas

## Problema Resolvido

**Sintoma:** O banco de horas não aparecia no painel do funcionário e nem na folha de ponto impressa.

**Causa Raiz:** Os registros de ponto eram salvos com status `PENDENTE` e só eram processados às 01:00 da manhã do dia seguinte através de um cron job. Isso significa que:
- Dados do dia atual não apareciam até o dia seguinte
- Se o cron job falhasse, os dados nunca eram processados
- Não havia feedback visual de que o processamento estava pendente

**Solução Implementada:**
1. **Processamento Imediato:** Agora, cada vez que um funcionário bate o ponto, o sistema processa automaticamente os dados do dia e atualiza o banco de horas em tempo real.
2. **Scripts de Diagnóstico:** Criamos ferramentas para identificar e corrigir problemas rapidamente.

## Ferramentas de Diagnóstico

### 1. diagnose-timebank.php

**Uso:** `/diagnose-timebank.php?employee_id=10`

**Função:** Verifica o estado dos dados do banco de horas para um funcionário específico.

**O que verifica:**
- ✅ Dados do funcionário (nome, contrato, carga horária)
- ✅ Registros de ponto nos últimos 10 dias
- ✅ Dados processados (sistur_time_days) nos últimos 10 dias
- ✅ Saldo total acumulado
- ✅ Datas com registros não processados
- ✅ Link para testar a API

**Como usar:**
1. Acesse como administrador
2. Substitua `10` pelo ID do funcionário desejado
3. Analise os resultados para identificar problemas

### 2. reprocess-timebank.php

**Uso:** `/reprocess-timebank.php?days=30&employee_id=10`

**Parâmetros:**
- `days` (opcional): Número de dias retroativos a processar (padrão: 30)
- `employee_id` (opcional): ID do funcionário específico (se omitido, processa todos)

**Função:** Reprocessa dados históricos que não foram processados.

**Quando usar:**
- Após identificar dados não processados com o script de diagnóstico
- Após importar registros antigos
- Após corrigir bugs no código de processamento
- Para forçar recálculo de dias específicos

**Como usar:**
1. Acesse como administrador
2. Aguarde o processamento (pode levar alguns minutos para grandes volumes)
3. Verifique os resultados
4. Use o diagnóstico novamente para confirmar que tudo foi processado

## Fluxo de Processamento

### Antes (Problemático)

```
Funcionário bate ponto
    ↓
Registro salvo com status PENDENTE
    ↓
[AGUARDA ATÉ 01:00 DA MANHÃ SEGUINTE]
    ↓
Cron job executa (se funcionar)
    ↓
Dados processados e salvos em sistur_time_days
    ↓
Banco de horas atualizado
```

### Agora (Corrigido)

```
Funcionário bate ponto
    ↓
Registro salvo com status PENDENTE
    ↓
PROCESSAMENTO IMEDIATO ✓
    ↓
Dados processados e salvos em sistur_time_days
    ↓
Banco de horas atualizado EM TEMPO REAL
```

## Verificações de Rotina

### Verificar se o banco de horas está funcionando

1. Faça um funcionário bater ponto
2. Imediatamente acesse o painel do funcionário
3. O banco de horas deve mostrar os dados atualizados
4. Se não aparecer, use o script de diagnóstico

### Verificar dados históricos

```bash
# Use o script de diagnóstico
/diagnose-timebank.php?employee_id=10

# Se houver dados não processados, reprocesse
/reprocess-timebank.php?employee_id=10&days=30
```

## Alterações no Código

### Arquivo: includes/class-sistur-punch-processing.php

**Linha 179-181:** Adicionado processamento imediato após registro de ponto

```php
// PROCESSAMENTO IMEDIATO: Processar o dia atual após registrar a batida
// Isso garante que o banco de horas é atualizado em tempo real
$this->process_employee_day($employee->id, $shift_date);
```

## Observações Importantes

1. **Cron Job Mantido:** O cron job noturno ainda existe e funciona como backup, processando registros que por algum motivo não foram processados imediatamente.

2. **Performance:** O processamento imediato adiciona alguns milissegundos ao tempo de resposta da batida de ponto, mas garante dados sempre atualizados.

3. **Dados Antigos:** Registros anteriores à implementação desta correção precisam ser reprocessados manualmente usando o script `reprocess-timebank.php`.

4. **Coluna de Observações:** A folha de ponto impressa agora inclui uma coluna de observações que mostra `supervisor_notes` (prioritário) ou `notes` de cada dia.

5. **CSS de Impressão:** A folha de ponto já está configurada para impressão correta (formato A4, sem elementos do navegador).

## Suporte

Se o problema persistir:
1. Execute o diagnóstico
2. Verifique os logs do WordPress (wp-content/debug.log se WP_DEBUG estiver ativo)
3. Verifique se há erros JavaScript no console do navegador
4. Certifique-se de que o funcionário tem um tipo de contrato com carga horária definida

## Data da Correção

- **Data:** 2025-11-18
- **Branch:** claude/add-timesheet-observations-017Y5KeMiu2LCVfGEobtFYfY
