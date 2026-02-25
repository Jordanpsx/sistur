# Antigravity Rules — SISTUR Python

> These rules are **non-negotiable constraints** that apply to every feature
> implemented in the SISTUR Python rewrite.  When in doubt, re-read this file.

---

## Rule #1 — Mandatory Audit Logging

For every **mutation** (create, update, delete, state change) implemented in the
Employee Portal, a comprehensive audit log entry is **strictly required**.

Each `AuditLog` row must capture:

| Field | Requirement |
|---|---|
| `action` | What operation was performed (`create`, `update`, `delete`, `login`, …) |
| `previous_state` | JSON snapshot of the data **before** the change |
| `new_state` | JSON snapshot of the data **after** the change |
| `user_id` | ID of the actor who performed the action |
| `user_type` | `employee` (Funcionario) or `admin` (User) |
| `module` | System area the action belongs to (e.g. `'funcionarios'`, `'banco_horas'`) |
| `entity_id` | Primary key of the affected row |
| `ip_address` | Request IP (captured automatically by AuditService) |
| `created_at` | UTC timestamp (ISO 8601) |

**Implementation:** always use `AuditService` from `app/core/audit.py`.
Never write raw `AuditLog` rows from routes or services directly.

```python
# Correct — inside a service method
AuditService.log_update(
    "funcionarios",
    entity_id=funcionario.id,
    previous_state=snapshot_antes,
    new_state=snapshot_depois,
    actor_id=ator_id,
)
```

---

## Rule #2 — Layered Architecture

Code is strictly separated into three layers.
**No layer may import from a layer above it.**

```
┌─────────────────────────────────────────────────────────┐
│  Blueprints / Routes  (app/blueprints/)                 │
│  • HTTP handling only: parse request, call service,     │
│    return response / render template                    │
│  • No business logic. No math. No DB queries.           │
├─────────────────────────────────────────────────────────┤
│  Services  (app/services/)                              │
│  • All business logic and calculations live here        │
│  • Pure Python — no Flask imports (no request/session)  │
│  • Receives explicit parameters; raises Python errors   │
│  • Calls AuditService for every mutation (Rule #1)      │
│  • Designed to be compiled via Cython without API break │
├─────────────────────────────────────────────────────────┤
│  Models  (app/models/, app/core/models.py)              │
│  • SQLAlchemy ORM definitions only                      │
│  • No business logic; only simple computed properties   │
└─────────────────────────────────────────────────────────┘
```

**Why this matters:**
The services layer will be compiled via **Cython** before commercial distribution
to protect proprietary business logic (CLT calculations, Banco de Horas rules, etc.).
A service must be replaceable by its compiled `.so` equivalent without any change
to the blueprint that calls it.

---

## Rule #3 — Deploy via develop Branch

Changes are deployed to the VPS by pushing to `develop`.
The GitHub Actions workflow SSHes into the VPS and runs `docker compose up -d --build`.

**Never push secrets, credentials, or `.env` files to the repository.**

---

## Rule #4 — Portal do Colaborador is the Target

All new features go into the Python **Portal do Colaborador**.
Do not modify the WordPress admin panel unless explicitly instructed.

---

## Rule #5 — Read CLAUDE.md First

Before making any change, read `CLAUDE.md` for project conventions,
naming rules, port priority, and the legacy reference index.

---

## Rule #6 — Tests are Mandatory

Every new feature or mutation in `app/services/` **must** have a corresponding
pytest test before the code is pushed to `develop`.

**Minimum test coverage per service method:**

| Scenario | Required assertion |
|---|---|
| Happy path | Return value is correct |
| AuditLog created | `db.session.query(AuditLog).filter_by(action=..., entity_id=...).count() == 1` |
| Invalid input | `pytest.raises(ValueError)` |
| Not found | `pytest.raises(ValueError, match="não encontrado")` |

**Test isolation contract:**
- Tests use `TestingConfig` → SQLite in-memory, never production DB
- The `db` fixture (`conftest.py`) auto-creates and auto-drops schema per test
- Hardware (printers, QR scanners) must be mocked via `tests/hardware/mocks.py`

**Run before every push:**
```bash
pytest --tb=short
```

---

## Rule #7 — Document Every Endpoint

Every new route must be added to `docs/api_catalog.md` before the PR is merged.
Module-specific business rules go in `docs/<module>/README.md`.

---

## Rule #9 — Mobile-First UI (Non-Negotiable)

The primary users of the Portal do Colaborador are **employees accessing from their phones**.
Every Jinja2 template must work on a 375px-wide screen before being considered done.

**Required before any template is pushed to `develop`:**

1. **Single-column by default** — layout stacks vertically at mobile; CSS Grid/Flexbox expands at `sm:` (640px) and `lg:` (1024px)
2. **Touch targets ≥ 44px** — every button and link must be at least 44×44 px high on mobile
3. **No horizontal scroll** — no element may cause the viewport to scroll sideways at 375px
4. **Hero sections collapse** — side-by-side panels (e.g., employee card + Banco de Horas) must stack vertically on mobile with `grid-template-columns: 1fr` override
5. **Tables become cards or scroll** — wide tables must wrap in `overflow-x: auto` on mobile, or be redesigned as stacked card rows
6. **Font sizes** — body text ≥ 14px; helper labels ≥ 12px; monospace (CPF, balance) ≥ 13px
7. **Forms full-width** — no two form inputs side-by-side below `sm:` breakpoint

**CSS Variables + white-label** (separate concern but applies universally):
All primary colors in templates must use `var(--primary)` / `var(--primary-dark)` etc.
Hardcoded hex values for brand colors are forbidden — this enables client white-labeling.

**Verification for every template:**
```bash
# Open in browser, DevTools → device toolbar → iPhone SE (375×667)
# Check: no overflow, touch targets visible, text readable
# Check: iPad (768×1024) — 2-col grids look right
```

---

## Rule #8 — Mandatory Docstrings on Every Function

Every function and method in the codebase **must** have a Google-style docstring written in **Portuguese (pt-BR)**.
This is non-negotiable — undocumented functions will not be accepted in `develop`.

**Why this matters:**
The services layer will be compiled via Cython. Docstrings are the only human-readable
documentation that survives compilation and describes business rules to future maintainers.

**Required sections (when applicable):**

| Section | When to include |
|---|---|
| Summary line | Always — one line, imperative mood, no period |
| `Args:` | Whenever the function has parameters |
| `Returns:` | Whenever the function returns a non-`None` value |
| `Raises:` | Whenever the function raises an exception intentionally |

**Minimal example — service method:**
```python
def calcular_saldo_dia(cls, funcionario_id: int, data: date) -> int:
    """Calcula o saldo de horas trabalhadas em um dia, em minutos.

    Considera tolerância de 5 minutos para entrada e saída conforme
    as regras da CLT e a configuração do departamento do funcionário.

    Args:
        funcionario_id: ID do funcionário no banco de dados.
        data: Data a ser calculada.

    Returns:
        Saldo em minutos. Positivo = horas extras. Negativo = débito.

    Raises:
        ValueError: Se o funcionário não for encontrado.
        ValueError: Se não houver registro de ponto para a data informada.
    """
```

**Rules:**
1. Docstrings em **português (pt-BR)** — idioma do domínio de negócio
2. Primeira linha: resumo da responsabilidade (verbo no imperativo, sem ponto final)
3. `Args:`, `Returns:`, `Raises:` são obrigatórios quando aplicáveis
4. Documente regras de negócio e restrições, não a implementação óbvia
5. Métodos privados (`_prefixo`) também exigem docstring se a lógica não for trivial
6. Rotas de blueprint devem documentar os campos do formulário ou parâmetros de URL
