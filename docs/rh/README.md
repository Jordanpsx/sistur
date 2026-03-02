# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

# Módulo: RH — Recursos Humanos

> Blueprint: `rh` — prefixo `/rh`  
> Serviço principal: `app/services/rh_service.py`

---

## Abas do Dashboard

| Aba | URL Param (`aba=`) | Descrição |
|---|---|---|
| Visão Geral | `visao-geral` | Planilha de batidas (matriz) + alertas de revisão |
| Colaboradores | `colaboradores` | Tabela filtrável + ações por funcionário |
| Folha de Ponto | `folha-ponto` | Redireciona para seleção de colaborador |

---

## Permissões RBAC — Módulo `folha_ponto`

| Ação | Descrição |
|---|---|
| `view` | Visualizar a Folha de Ponto de qualquer funcionário |
| `edit` | Adicionar, editar, ou deletar batidas administrativamente |
| `deducao` | Registrar deduções de banco de horas |
| `imprimir` | Gerar e baixar o PDF da Folha de Ponto |

Super admins têm todas as permissões automaticamente.

---

## Folha de Ponto Individual — Rotas

### `GET /rh/folha-ponto/<funcionario_id>`

Exibe a tabela CLT de 10 colunas do funcionário para o mês/ano selecionado.

**Permissão:** `folha_ponto.view`  
**Query params:** `mes` (int, default: mês corrente), `ano` (int, default: ano corrente)  
**Template:** `rh/folha_ponto.html`

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/adicionar`

Adiciona uma nova batida administrativa.

**Permissão:** `folha_ponto.edit`  
**Form fields:**

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `novo_horario` | `datetime-local` | ✅ | Data e hora da batida |
| `motivo` | string | ✅ | Justificativa auditada |
| `mes` | int | ✅ | Mês de retorno |
| `ano` | int | ✅ | Ano de retorno |

**Audit:** Cria `AuditLog` via `PontoService.registrar_batida(source="admin")`

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/<entry_id>/editar`

Edita o horário de uma batida existente.

**Permissão:** `folha_ponto.edit`  
**Form fields:** `novo_horario`, `motivo`, `mes`, `ano`  
**Audit:** `PontoService.editar_batida_admin` — registra snapshot before/after.

---

### `POST /rh/folha-ponto/<funcionario_id>/batida/<entry_id>/deletar`

Remove uma batida com auditoria.

**Permissão:** `folha_ponto.edit`  
**Form fields:** `motivo` (obrigatório), `mes`, `ano`  
**Audit:** `PontoService.deletar_batida_admin` — snapshot completo antes da exclusão.

---

### `POST /rh/folha-ponto/<funcionario_id>/deducao`

Registra uma dedução de banco de horas.

**Permissão:** `folha_ponto.deducao`  
**Form fields:**

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `deduction_type` | string | ✅ | Enum: `ferias`, `falta_justificada`, `falta_injustificada`, `atestado`, `licenca`, `outro` |
| `minutos` | int | ✅ | Quantidade de minutos a deduzir |
| `data_registro` | date | ✅ | Data da dedução (`YYYY-MM-DD`) |
| `observacao` | string | ❌ | Observação opcional |

**Delegates to:** `PontoService.registrar_abatimento_horas`

---

### `GET /rh/folha-ponto/<funcionario_id>/pdf`

Gera e retorna o PDF da Folha de Ponto via WeasyPrint.

**Permissão:** `folha_ponto.imprimir`  
**Query params:** `mes`, `ano`  
**Response:** `application/pdf` inline, filename `folha_ponto_{cpf}_{ano}-{MM}.pdf`  
**Template:** `rh/folha_ponto_pdf.html` (CSS puro, sem JS, sem fontes externas)  
**Dados da empresa:** Lidos de `sistema_settings` (CNPJ, endereço, nome) — configurados em  
`GET /admin/configuracoes/ → aba Dados da Empresa`

---

## Serviço: `RHService.folha_ponto_mes`

```python
RHService.folha_ponto_mes(funcionario_id: int, ano: int, mes: int) -> dict
```

**Retorna:**

| Chave | Tipo | Descrição |
|---|---|---|
| `funcionario` | `Funcionario` | Instância ORM |
| `linhas` | `list[dict]` | Uma linha por `TimeDay` registrado (10 colunas CLT) |
| `total_minutos_trabalhados` | int | Soma do mês |
| `total_saldo_minutos` | int | Saldo líquido do mês (positivo = banco acumulado) |
| `total_expected_minutos` | int | Soma dos minutos esperados |
| `dias_com_revisao` | int | Contagem de `TimeDay.needs_review = True` |

Usa **2 queries separadas** (TimeDay + TimeEntry) para evitar produto cartesiano  
— cada linha do dict mapeia `clock_in`, `lunch_start`, `lunch_end`, `clock_out` para  
instâncias `TimeEntry | None`.

---

## Dados da Empresa (para PDF)

Configurados em `/admin/configuracoes/ → Dados da Empresa`:

| Chave | System Settings | Descrição |
|---|---|---|
| `empresa.cnpj` | `sistur_system_settings` | CNPJ exibido no cabeçalho do PDF |
| `empresa.endereco` | `sistur_system_settings` | Endereço completo |

O nome da empresa reutiliza `branding.empresa_nome`.

---

## Regras Antigravity aplicadas

- **Rule #1** — Toda operação de escrita (add/edit/delete batida, dedução) registra `AuditLog`.
- **Rule #2** — Queries de banco ficam em `RHService` e `PontoService`, nunca nos blueprints.
- **Rule #3** — Edições e exclusões de ponto sempre exigem `motivo` não vazio e salvam snapshot before/after.
