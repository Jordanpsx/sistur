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

## Rule #5 — Read CLAUDE.md First (if you haven't already)

> **`CLAUDE.md` is a mandatory rules and best practices file.**
> It contains project conventions, naming rules, port priority, tech stack,
> module descriptions, testing protocol, docstring standards, and the legacy reference index.

Before making **any** change, read `CLAUDE.md` at the root of the repository.
If you have already read it in this session, you do not need to re-read it —
but do not skip it on the first interaction of any new session.

Both `CLAUDE.md` and `docs/antigravity.md` are **complementary rule files**:
- `CLAUDE.md` — project-wide conventions, stack, module index, and guidelines
- `docs/antigravity.md` (this file) — non-negotiable architecture constraints

Both must be read before implementing any feature.

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

## Rule #9 — Database Migrations & Deployment

**When are migrations needed?**

Migrations are **only required when SQLAlchemy models change** (new columns, renamed fields, type changes, new tables, etc.).
Simply adding service methods, routes, or read-only queries does NOT require a migration.

**Migration Checklist:**

| Change type | Needs migration? | Example |
|---|---|---|
| Add/modify SQLAlchemy model | ✅ YES | Adding `last_login: DateTime` to `Funcionario` |
| Add service method (read-only) | ❌ NO | `RHService.matriz_batidas_mes()` — queries only |
| Add route / blueprint | ❌ NO | `GET /rh/dashboard` — HTTP layer |
| Add template / CSS / JS | ❌ NO | New Jinja2 template |
| Modify model relationship | ✅ YES | Adding `ForeignKey` or changing `back_populates` |
| Change model column constraint | ✅ YES | Adding `unique=True` or `nullable=False` |

**When you MUST run migrations:**

1. **Local development:**
   ```bash
   flask db migrate -m "Descriptive message"
   flask db upgrade
   ```

2. **On VPS after `git push origin develop` deployment:**
   ```bash
   docker exec -it sistur-flask-app flask db upgrade
   ```
   (The CI/CD pipeline auto-runs this command on deploy; only run manually if needed for urgent fixes)

3. **Generate migration file (local only):**
   ```bash
   flask db migrate -m "Add last_login to Funcionario"
   ```
   Then **commit and push** the generated migration file in `migrations/versions/`.

**Important notes:**
- Never edit migration files manually — they are version-controlled source of truth
- Always commit the auto-generated migration file to git before pushing to VPS
- If you forget to migrate locally, the VPS deployment will still work, but the app will crash when accessing the new model fields
- The `flask db upgrade` command on VPS is **idempotent** — it only applies pending migrations

---

## Rule #10 — Mobile-First UI (Non-Negotiable)

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

## Rule #11 — Mandatory Docstrings on Every Function

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

---

## Rule #12 — API Contract Documentation

Every HTTP endpoint is a **contract** between the backend and its consumers (web portal, mobile app, POS system, etc.).
This contract **must be documented** in the documentation files, especially for headless APIs like Reservas.

**What is a "contract"?**

The API contract consists of:
- **Request shape:** What JSON/form data the endpoint expects
- **Response shape:** What JSON the endpoint returns
- **Status codes:** HTTP status codes for success and errors
- **Field types and constraints:** Required vs optional, min/max values, enums
- **Error messages:** Exact error codes and messages returned

**When documentation is required:**

1. **Adding a new endpoint** → Document in `docs/api_catalog.md` and module's `docs/<module>/README.md`
2. **Changing request payload** (add/remove/rename field) → Update documentation immediately
3. **Changing response payload** (add/remove/rename field) → Update documentation immediately
4. **Changing field type** (string → int, optional → required) → Update documentation immediately
5. **Adding/removing status codes** → Update documentation immediately
6. **Adding/removing error codes** → Update documentation immediately

**Especially for Reservas module:**

The Reservas module is a **headless API** designed to be consumed by multiple frontends:
- Legacy WordPress plugin
- In-house Portal do Colaborador
- Physical POS counter system

Because of this design, the request/response contract is **critical** — changes to the API schema can break existing consumers.

**Rule:** Whenever the Reservas API changes (endpoints, payloads, status codes, error codes), the changes **must** be documented in `docs/reservas/README.md` with:
- Full request JSON schema (with all required and optional fields)
- Full response JSON schema (with all fields, types, and nesting)
- All status codes (success and error)
- All error codes with explanations

**Verification before code review:**

Code reviewers must check:

1. Does the route match what's documented in `docs/api_catalog.md`?
2. Does the request body match what's documented in `docs/<module>/README.md`?
3. Does the response JSON match what's documented?
4. Are all error codes documented?
5. For Reservas: Does the JSON schema in `docs/reservas/README.md` match the actual code?

**Failure to document is a blocking issue** — PRs with undocumented or incorrectly documented API changes will not be merged.
