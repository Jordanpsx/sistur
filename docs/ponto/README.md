# Módulo: Ponto Eletrônico

## Visão Geral

O módulo **Ponto Eletrônico** registra, armazena e calcula o saldo diário de horas dos colaboradores do Portal do Colaborador. Ele implementa as regras de negócio definidas em `CLAUDE.md` e respeita as diretrizes de arquitetura de `docs/Antigravity.md`.

---

## Modelos ORM (`app/models/ponto.py`)

### `TimeEntry` → `sistur_time_entries`

Registro individual de cada batida de ponto.

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `id` | Integer PK | ✓ | |
| `funcionario_id` | FK `sistur_funcionarios.id` | ✓ | Colaborador |
| `punch_time` | DateTime (UTC) | ✓ | Timestamp exato da batida |
| `shift_date` | Date | ✓ | Data do turno (pode diferir de `punch_time.date()` em viragens de meia-noite) |
| `punch_type` | Enum | ✓ | `clock_in`, `lunch_start`, `lunch_end`, `clock_out`, `extra` |
| `source` | Enum | ✓ | `employee`, `admin`, `KIOSK`, `QR` |
| `processing_status` | Enum | ✓ | `PENDENTE` → `PROCESSADO` após `processar_dia()` |
| `admin_change_reason` | Text | — | Obrigatório quando `source = admin` (Rule #3) |
| `changed_by_user_id` | Integer | — | ID do admin que editou |
| `criado_em` | DateTime | ✓ | Timestamp de criação |

### `TimeDay` → `sistur_time_days`

Agregado diário por colaborador. Recalculado por `PontoService.processar_dia()`.

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | Integer PK | |
| `funcionario_id` | FK | |
| `shift_date` | Date | `UNIQUE` por `(funcionario_id, shift_date)` |
| `minutos_trabalhados` | Integer | Soma bruta dos pares de batidas |
| `saldo_calculado_minutos` | Integer | Após tolerância de 10 min (Rule #4) |
| `saldo_final_minutos` | Integer | Após deduções manuais de banco de horas |
| `needs_review` | Boolean | `True` quando batidas ímpares no dia (Rule #5) |
| `expected_minutes_snapshot` | Integer | Jornada esperada no dia (imutável) |
| `criado_em` / `atualizado_em` | DateTime | |

---

## Service (`app/services/ponto_service.py`)

Todos os métodos são **estáticos** e **sem imports do Flask** (Antigravity Rule #2).

### Métodos de Cálculo Puro (sem banco)

#### `calculate_daily_balance(punch_list, expected_minutes, almoco_minutes=60) → dict`

Calcula o saldo diário a partir de uma lista ordenada de batidas.

- **Rule #5**: número ímpar de batidas → retorna `needs_review=True`, saldo congelado em `0`.
- **Rule #4**: aplica tolerância de 10 min via `_apply_tolerance()`.
- Desconta almoço somente quando há ≥ 4 batidas.

#### `_apply_tolerance(saldo_bruto, tolerance) → int`

- `saldo_bruto >= 0` → retorna sem alteração (horas extras não são penalizadas).
- `abs(saldo_bruto) <= tolerance` → retorna `0` (atraso perdoado).
- Atraso acima da tolerância → penaliza apenas o excesso.

### Métodos de Mutação (com banco + AuditService)

#### `registrar_batida(funcionario_id, punch_time, source, ator_id) → TimeEntry`

- Valida colaborador ativo.
- Rejeita batidas duplicadas em janela de 5 segundos (anti-duplicata do legado).
- Infere `punch_type` automaticamente pela sequência do dia.
- Cria `TimeEntry`, registra `AuditLog(action=create, module='ponto')`.
- Chama `processar_dia()` para atualizar `TimeDay`.

#### `processar_dia(funcionario_id, shift_date) → TimeDay`

- Busca todas as `TimeEntry` do dia ordenadas por `punch_time`.
- Chama `calculate_daily_balance()`.
- Faz **upsert** de `TimeDay` (cria se não existe, atualiza se já existe).
- Marca todas as entradas do dia como `PROCESSADO`.

#### `editar_batida_admin(time_entry_id, novo_horario, motivo, ator_id) → dict`

- `motivo` não vazio é obrigatório (Rule #3).
- Seta `source = admin`, `admin_change_reason`, `changed_by_user_id`.
- Registra `AuditLog(action=update)` com snapshots antes/depois (Rule #1).
- Chama `processar_dia()` para recalcular o dia afetado.

---

## Regras de Negócio

| Rule | Descrição |
|---|---|
| **#3** | Edições administrativas exigem `admin_change_reason` não vazio |
| **#4** | Tolerância de 10 min: atrasos ≤ 10 min são perdoados; apenas o excesso é penalizado |
| **#5** | Número ímpar de batidas no dia → `needs_review = True`, saldo congelado |

---

## Rotas (`app/blueprints/ponto/routes.py`)

| Método | URL | Permissão | Descrição |
|---|---|---|---|
| `GET` | `/ponto/` | `ponto.view` | Histórico mensal de batidas |
| `POST` | `/ponto/registrar` | `ponto.create` | Registrar nova batida |

### GET `/ponto/`

Query params opcionais: `mes` (1–12), `ano`. Padrão: mês e ano correntes.

Renderiza `ponto/index.html` com:
- `days` → lista de `TimeDay` do mês
- `entries_by_day` → dict `{date: [TimeEntry]}` agrupado por dia

### POST `/ponto/registrar`

Form field: `punch_time` (ISO datetime, opcional). Se ausente, usa o timestamp do servidor.

Redireciona para `/ponto/` com mensagem flash de sucesso ou erro.

---

## Templates

- `app/templates/ponto/index.html` — tabela mensal com navegação de mês, botão de bater ponto, badge de status (OK/Revisar), saldo colorido por sinal.

---

## Auditoria

Toda mutação registra exatamente **um** `AuditLog`:

| Operação | `action` | `previous_state` | `new_state` |
|---|---|---|---|
| Nova batida | `create` | `null` | `{funcionario_id, punch_time, punch_type, source}` |
| Edição admin | `update` | horário e source anteriores | horário e source novos + motivo |

---

## Testes

```bash
# Apenas o módulo
pytest tests/services/test_ponto_service.py tests/blueprints/test_ponto_routes.py -v

# Suíte completa
pytest --tb=short
```

### Cobertura

| Classe de teste | Escopo |
|---|---|
| `TestApplyTolerance` | `_apply_tolerance` — 6 casos |
| `TestCalculateDailyBalance` | `calculate_daily_balance` — 9 casos |
| `TestRegistrarBatida` | mutação com DB — 5 casos |
| `TestProcessarDia` | agregação diária — 2 casos |
| `TestEditarBatidaAdmin` | edição admin — 4 casos |
| `TestHistoricoRoute` | GET /ponto/ — 3 casos |
| `TestRegistrarRoute` | POST /ponto/registrar — 3 casos |
