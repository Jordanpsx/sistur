# Como Reprocessar Horas Trabalhadas

Este guia explica como reprocessar dias específicos quando as horas trabalhadas aparecem incorretamente (zeradas) no sistema.

## Problema

Se você ver na folha de ponto que um funcionário tem batidas registradas mas as horas trabalhadas aparecem como `0min`, isso significa que o processamento não foi executado ou houve um erro.

Exemplo:
```
Ter 18/11: 06:54 - 11:04 - 12:56 - 17:56 → Trabalhadas: 0min ❌
```

## Solução: API de Reprocessamento

### Opção 1: Reprocessar um funcionário em uma data específica

```bash
curl -X POST http://localhost/wordpress/wp-json/sistur/v1/reprocess \
  -H "Content-Type: application/json" \
  -u admin:senha \
  -d '{
    "employee_id": 1,
    "start_date": "2024-11-18"
  }'
```

### Opção 2: Reprocessar todos os funcionários em um período

```bash
curl -X POST http://localhost/wordpress/wp-json/sistur/v1/reprocess \
  -H "Content-Type: application/json" \
  -u admin:senha \
  -d '{
    "start_date": "2024-11-17",
    "end_date": "2024-11-21"
  }'
```

### Opção 3: Usar JavaScript no Console do Navegador

1. Acesse a página de administração do WordPress
2. Abra o Console do Navegador (F12 → Console)
3. Execute o seguinte código:

```javascript
// Reprocessar dia 18/11/2024 para o funcionário ID 1
fetch('/wordpress/wp-json/sistur/v1/reprocess', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce // Se estiver logado no WordPress
  },
  body: JSON.stringify({
    employee_id: 1, // Opcional - omita para processar todos
    start_date: '2024-11-18',
    end_date: '2024-11-18' // Opcional - mesma data para processar apenas 1 dia
  })
})
.then(response => response.json())
.then(data => {
  console.log('Resultado:', data);
  if (data.success) {
    alert('Reprocessamento concluído! ' + data.processed_count + ' dia(s) processado(s).');
  } else {
    alert('Erro: ' + data.message);
  }
})
.catch(error => console.error('Erro:', error));
```

## Parâmetros da API

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `start_date` | string | Sim | Data inicial no formato YYYY-MM-DD |
| `end_date` | string | Não | Data final (padrão: mesma que start_date) |
| `employee_id` | int | Não | ID do funcionário (omitir para processar todos) |

## Resposta da API

Sucesso:
```json
{
  "success": true,
  "message": "Reprocessamento concluído. 5 dia(s) processado(s).",
  "processed_count": 5
}
```

Erro:
```json
{
  "success": false,
  "message": "Formato de data inválido. Use YYYY-MM-DD"
}
```

## Nova Coluna de Observações

Após as correções, a folha de ponto agora inclui uma coluna de **Observações** que mostra:

- **Notes**: Observações automáticas do sistema (ex: "Dia marcado para revisão")
- **Supervisor Notes**: Comentários adicionados pelo responsável durante a revisão

Exemplo:
```
Dia | Entrada | ... | Trabalhadas | Desvio | Observações
Ter | 06:54   | ... | 9h10min     | +1h10  | Aprovado pelo supervisor
```

## Logs de Debug

Se `WP_DEBUG` estiver ativado no `wp-config.php`, o sistema agora grava informações detalhadas de cálculo nas observações:

```
[DEBUG]
Par 1 calculado: 06:54 a 11:04 = 250.00 minutos
Par 2 calculado: 12:56 a 17:56 = 300.00 minutos
Total calculado: 550.00 minutos
```

Isso ajuda a identificar problemas de cálculo rapidamente.

## Prevenção de Problemas Futuros

1. **Certifique-se que o WP-Cron está ativo**: O processamento automático roda às 01:00 diariamente
2. **Verifique as configurações**: Em WordPress Admin → SISTUR → Configurações
3. **Habilite debug temporariamente**: Adicione ao `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

## Permissões

A API de reprocessamento requer permissões de **administrador** (`manage_options`). Usuários normais não podem acessar este endpoint.

## Suporte

Se após reprocessar os dados continuarem zerados, verifique:

1. Os horários das batidas estão corretos (saída deve ser depois da entrada)
2. As batidas têm o campo `shift_date` correto
3. O funcionário tem carga horária configurada
4. Verifique os logs em `wp-content/debug.log`
