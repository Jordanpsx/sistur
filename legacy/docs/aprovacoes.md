# Módulo de Aprovações

## Visão Geral

O módulo de aprovações é a central para gerenciar todas as solicitações que exigem aprovação hierárquica no SISTUR. Qualquer módulo pode gerar solicitações de aprovação (ex: baixa de estoque, ajuste de ponto).

## Tabela: `sistur_approval_requests`

| Campo | Tipo | Descrição |
|---|---|---|
| `module` | varchar(50) | Módulo de origem (ex: `inventory`) |
| `action` | varchar(50) | Ação (ex: `stock_loss`) |
| `entity_type` | varchar(50) | Tipo da entidade (ex: `product`) |
| `entity_id` | mediumint | ID da entidade rel. |
| `request_data` | longtext (JSON) | Dados completos da solicitação |
| `requested_by` | mediumint | ID do funcionário solicitante |
| `status` | enum | `pending`, `approved`, `rejected`, `cancelled` |
| `priority` | enum | `low`, `normal`, `high`, `urgent` |
| `required_role_id` | mediumint | Role mín. para aprovar |
| `approved_by` | mediumint | ID do aprovador |
| `approval_notes` | text | Observação do aprovador |

## Fluxo de Aprovação

```
Funcionário solicita    →  Status: pending
                            ↓
Gerente aprova/rejeita  →  Status: approved/rejected
                            ↓ (se aprovado)
                         execute_approved_action()
                            → Executa ação (ex: baixa estoque)
```

## Hierarquia de Roles

Aprovação é baseada em `approval_level` na tabela `sistur_roles`:
- Funcionário só pode aprovar solicitações de quem tem `approval_level` **inferior**
- Admin (`is_admin = 1`) pode aprovar tudo
- Não pode aprovar próprias solicitações

## Ações que Geram Aprovação

| Módulo | Ação | Descrição | Execução pós-aprovação |
|---|---|---|---|
| `inventory` | `stock_loss` | Baixa de estoque | Cria movimentação + atualiza `current_stock` |

## AJAX Endpoints

Todos registrados com `wp_ajax_` + `wp_ajax_nopriv_`:

| Action | Método | Descrição |
|---|---|---|
| `sistur_create_approval_request` | `ajax_create_request` | Criar solicitação |
| `sistur_process_approval` | `ajax_process_approval` | Aprovar/rejeitar |
| `sistur_get_pending_approvals` | `ajax_get_pending` | Listar pendentes |
| `sistur_get_my_requests` | `ajax_get_my_requests` | Minhas solicitações |
| `sistur_cancel_request` | `ajax_cancel_request` | Cancelar pendente |

## Permissões Necessárias

- `approvals.view_own` — Ver próprias solicitações
- `approvals.view_pending` — Ver solicitações pendentes
- `approvals.approve` — Aprovar ou rejeitar

## Arquivos

- **Backend**: `includes/class-sistur-approvals.php`
- **Frontend**: `templates/components/module-aprovacoes.php`
- **Ativação**: `includes/class-sistur-activator.php` (schema, permissões)
- **Portal**: `templates/portal-colaborador.php` (tab `#tab-aprovacoes`)

## Como Adicionar Novo Módulo ao Sistema de Aprovações

1. No handler do módulo, chamar:
```php
$approvals = SISTUR_Approvals::get_instance();
$request_id = $approvals->create_request('modulo', 'acao', $entity_id, array(
    'entity_type' => 'tipo',
    // ... dados específicos
), $employee_id);
```

2. Em `class-sistur-approvals.php`, adicionar tratamento em `execute_approved_action()`:
```php
case 'modulo':
    $this->execute_modulo_action($request['action'], $data, $request);
    break;
```

3. Adicionar labels em `module-aprovacoes.php`:
```php
$module_labels['modulo'] = 'Nome Legível';
$action_labels['acao'] = 'Nome da Ação';
```
