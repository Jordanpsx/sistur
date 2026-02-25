# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

# Módulo: funcionarios

Gerencia o cadastro e o ciclo de vida dos colaboradores no **Portal do Colaborador** SISTUR.

---

## Visão Geral

| Camada | Arquivo |
|---|---|
| Model (ORM) | `app/models/funcionario.py` — `Funcionario` |
| Service | `app/services/funcionario_service.py` — `FuncionarioService` |
| Blueprint / Rotas | `app/blueprints/rh/routes.py` — `Blueprint("rh")` |
| Tabela DB | `sistur_funcionarios` |

---

## Modelo — `Funcionario`

### Campos obrigatórios

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | `Integer` PK | Chave primária auto-incrementada |
| `nome` | `String(255)` | Nome completo do colaborador |
| `cpf` | `String(11)` | CPF limpo (só dígitos), `UNIQUE`, usado como identificador de login |
| `ativo` | `Boolean` | `True` = ativo. Soft-delete via `ativo=False` |

### Campos de autenticação

| Campo | Tipo | Descrição |
|---|---|---|
| `senha_hash` | `String(255)` | Hash pbkdf2:sha256 (werkzeug). Nullable na fase atual — login por CPF sem senha habilitado |
| `token_qr` | `String(36)` | Token UUID para leitura via scanner de ponto |

### Campos profissionais

| Campo | Tipo | Descrição |
|---|---|---|
| `cargo` | `String(255)` | Cargo / título do posto |
| `matricula` | `String(50)` | Número de matrícula interna |
| `data_admissao` | `Date` | Data de admissão (CLT) |
| `ctps` | `String(50)` | Número da Carteira de Trabalho |
| `ctps_uf` | `String(2)` | UF da CTPS |
| `cbo` | `String(20)` | Código Brasileiro de Ocupações |
| `foto` | `String(500)` | URL da foto de perfil |
| `bio` | `Text` | Texto livre de apresentação |

### Jornada e Banco de Horas

| Campo | Tipo | Padrão | Descrição |
|---|---|---|---|
| `minutos_esperados_dia` | `SmallInteger` | `480` (8h) | Minutos de trabalho esperados por dia — fallback global |
| `minutos_almoco` | `SmallInteger` | `60` (1h) | Pausa de almoço padrão — fallback global |
| `jornada_semanal` | `JSON` | `null` | Jornada por dia-da-semana. **Sobrepõe os campos globais acima quando presente** |

#### Estrutura `jornada_semanal`

```json
{
  "segunda":  { "ativo": true,  "minutos": 480, "almoco": 60 },
  "terca":    { "ativo": true,  "minutos": 480, "almoco": 60 },
  "quarta":   { "ativo": true,  "minutos": 480, "almoco": 60 },
  "quinta":   { "ativo": true,  "minutos": 480, "almoco": 60 },
  "sexta":    { "ativo": true,  "minutos": 480, "almoco": 60 },
  "sabado":   { "ativo": false, "minutos": 0,   "almoco": 0  },
  "domingo":  { "ativo": false, "minutos": 0,   "almoco": 0  }
}
```

Dias com `"ativo": false` são considerados folga — o Banco de Horas não gera débito para esses dias.

### Relacionamentos

| Relação | FK | Tabela |
|---|---|---|
| `area` | `area_id` → `sistur_areas.id` | Área/departamento do colaborador |
| `role` | `role_id` → `sistur_roles.id` | Role de permissões no portal |

### Helpers de modelo

```python
funcionario.saldo_banco_horas()           # int — saldo atual em minutos (stub)
funcionario.saldo_banco_horas_formatado() # str — ex. "2h 30min"
funcionario.cpf_formatado()               # str — ex. "529.982.247-25"
```

---

## Validação de CPF — `validar_cpf(cpf: str) → str`

Função standalone em `app/models/funcionario.py`.  
Aceita qualquer formato (pontuado ou só dígitos), valida os dois dígitos verificadores (algoritmo padrão da Receita Federal) e retorna os 11 dígitos limpos.

```python
validar_cpf("529.982.247-25")  # → "52998224725"
validar_cpf("11111111111")     # → ValueError: "CPF inválido."
```

---

## Service — `FuncionarioService`

Todos os métodos são `@staticmethod` — sem estado de instância. Cython-compatíveis.

### Consultas (read-only)

| Método | Retorno | Descrição |
|---|---|---|
| `buscar_por_id(id)` | `Funcionario \| None` | Busca por PK |
| `buscar_por_cpf(cpf)` | `Funcionario \| None` | Busca por CPF; retorna `None` se inativo ou CPF inválido |
| `listar_ativos()` | `list[Funcionario]` | Lista todos os ativos ordenados por nome |

### Mutações (com auditoria obrigatória)

#### `criar(...) → Funcionario`

```python
FuncionarioService.criar(
    nome="Ana Clara Santos",
    cpf="529.982.247-25",       # qualquer formato aceito
    cargo="Recepcionista",
    matricula="001",
    area_id=2,
    minutos_esperados_dia=480,  # padrão 8h
    minutos_almoco=60,          # padrão 1h
    ator_id=session["funcionario_id"],
)
```

- Valida CPF via `validar_cpf()` — levanta `ValueError` se inválido.
- Rejeita CPF já cadastrado (unicidade na tabela).
- Registra `AuditLog` com `action="create"`, `module="funcionarios"`.

#### `atualizar(funcionario_id, dados, ator_id) → Funcionario`

Campos permitidos: `nome`, `cargo`, `matricula`, `area_id`, `minutos_esperados_dia`, `minutos_almoco`, `jornada_semanal`, `email`, `telefone`, `data_admissao`, `bio`, `ctps`, `ctps_uf`, `cbo`.

Qualquer chave não pertencente à whitelist é silenciosamente ignorada.  
Registra `AuditLog` com `action="update"` e snapshots antes/depois.

#### `definir_senha(funcionario_id, senha, ator_id) → Funcionario`

- Armazena hash pbkdf2:sha256 via `werkzeug.security.generate_password_hash`.
- Senha mínima: não vazia (validação de tamanho mínimo está na rota — ≥ 6 chars).
- O `AuditLog` registra `{"senha_hash": "[protegido]"}` / `{"senha_hash": "[redefinida]"}` — **o valor real jamais é auditado**.

#### `desativar(funcionario_id, ator_id) → Funcionario`

Soft-delete: seta `ativo=False`. O colaborador deixa de aparecer na listagem e perde o acesso ao portal.  
Levanta `ValueError` se o funcionário já estiver inativo.

---

## Rotas — Blueprint `rh`

Todas as rotas exigem `@login_required` e a permissão correspondente via `@require_permission`.

| Método | URL | Permissão | Descrição |
|---|---|---|---|
| `GET` | `/rh/funcionarios` | `funcionarios.view` | Lista colaboradores ativos com filtro `?q=` por nome/CPF |
| `GET` | `/rh/funcionarios/novo` | `funcionarios.create` | Formulário de cadastro em branco |
| `POST` | `/rh/funcionarios/novo` | `funcionarios.create` | Persiste novo colaborador |
| `GET` | `/rh/funcionarios/<id>/editar` | `funcionarios.edit` | Formulário pré-preenchido |
| `POST` | `/rh/funcionarios/<id>/editar` | `funcionarios.edit` | Aplica alterações |
| `POST` | `/rh/funcionarios/<id>/desativar` | `funcionarios.desativar` | Soft-delete |

### Fluxo de criação (POST `/rh/funcionarios/novo`)

```
1. FuncionarioService.criar()          → persiste campos obrigatórios + gera AuditLog
2. FuncionarioService.atualizar()      → aplica campos extras e jornada_semanal + AuditLog
3. FuncionarioService.definir_senha()  → (se nova_senha preenchida) → AuditLog
4. RoleService.atribuir_ao_funcionario() → (se role_id fornecido) → AuditLog
```

### Campos do formulário (novo / editar)

| Campo | Tipo | Obrigatório | Observação |
|---|---|---|---|
| `nome` | text | ✅ | Stripped de espaços |
| `cpf` | text | ✅ | Qualquer formato |
| `email` | email | — | |
| `telefone` | text | — | |
| `cargo` | text | — | |
| `matricula` | text | — | |
| `data_admissao` | date | — | |
| `ctps` | text | — | |
| `ctps_uf` | text (2) | — | |
| `cbo` | text | — | |
| `area_id` | select | — | FK `sistur_areas` |
| `role_id` | select | — | FK `sistur_roles` |
| `minutos_esperados_dia` | number | — | Padrão 480 |
| `minutos_almoco` | number | — | Padrão 60 |
| `jornada_<dia>_ativo` | checkbox | — | checkbox por dia da semana |
| `jornada_<dia>_minutos` | number | — | Minutos esperados naquele dia |
| `jornada_<dia>_almoco` | number | — | Almoço naquele dia |
| `nova_senha` | password | — | Mínimo 6 chars |
| `confirmar_senha` | password | — | Deve coincidir com `nova_senha` |

---

## Auditoria

Todo CRUD passa por `AuditService` (Antigravity Rule #1). O `module` gravado é `"funcionarios"`.

| Operação | `action` | `previous_state` | `new_state` |
|---|---|---|---|
| Criar | `create` | `null` | snapshot dos campos `_AUDIT_FIELDS` |
| Atualizar | `update` | snapshot antes | snapshot depois |
| Desativar | `update` | snapshot com `ativo=True` | snapshot com `ativo=False` |
| Definir senha | `update` | `{"senha_hash": "[protegido]"}` | `{"senha_hash": "[redefinida]"}` |

Campos capturados no snapshot: `id`, `nome`, `cpf`, `cargo`, `matricula`, `area_id`, `ativo`, `minutos_esperados_dia`, `minutos_almoco`.

---

## Regras de Negócio

1. **CPF é único** — não pode haver dois funcionários com o mesmo CPF, mesmo que um esteja inativo.
2. **Soft-delete** — nunca se deleta um `Funcionario` fisicamente; usa-se `ativo=False`.
3. **Jornada semanal tem prioridade** — quando `jornada_semanal` está preenchido, os campos `minutos_esperados_dia` e `minutos_almoco` são apenas fallbacks.
4. **Whitelist de campos atualizáveis** — o service rejeita silenciosamente qualquer campo fora da lista permitida em `atualizar()`.
5. **Senha nunca é auditada em texto plano** — o `AuditLog` registra apenas `[protegido]` / `[redefinida]`.
