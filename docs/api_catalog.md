# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

# API Catalog — SISTUR Portal do Colaborador

> **Source of truth** for all HTTP endpoints.
> Update this file whenever a route is added, changed, or removed.

---

## Conventions

| Item | Convention |
|---|---|
| Base URL | `/` (served via Nginx reverse proxy on port 80/443) |
| Content-Type | `application/x-www-form-urlencoded` for form POSTs; `application/json` for API responses |
| Auth | Session cookie (`funcionario_id` in server-side session) |
| Error format | Flash message (HTML pages) or `{"erro": "message"}` (JSON endpoints) |
| Timestamps | ISO 8601, UTC (`2026-02-24T18:30:00Z`) |

---

## Custom Error Codes

| Code | HTTP Status | Meaning |
|---|---|---|
| `CPF_INVALIDO` | 400 | CPF failed check-digit validation |
| `CPF_NAO_ENCONTRADO` | 401 | CPF not in DB or employee inactive |
| `SESSAO_EXPIRADA` | 401 | Session cookie expired or missing |
| `NAO_AUTORIZADO` | 403 | Authenticated but lacks permission for this resource |
| `NAO_ENCONTRADO` | 404 | Requested resource does not exist |
| `CPF_DUPLICADO` | 409 | CPF already registered |

---

## Module: Auth (`/portal`)

### `GET /portal/login`

Renders the login page.

**Auth required:** No
**Redirect:** If already authenticated → `GET /portal/dashboard`

**Response:** `200 text/html` — `auth/login.html`

---

### `POST /portal/login`

Authenticate via CPF.

**Auth required:** No
**Content-Type:** `application/x-www-form-urlencoded`

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `cpf` | string | Yes | CPF in any format (`000.000.000-00` or `00000000000`) |

**Success response:** `302` → `GET /portal/dashboard`

**Error responses:**

| Scenario | Status | Flash category | Message |
|---|---|---|---|
| CPF format invalid | 400 | `erro` | "CPF inválido." |
| CPF not found / inactive | 401 | `erro` | "CPF não encontrado ou funcionário inativo." |

**Audit:** `AuditLog(action=login, module=auth, new_state={"success": bool})`

---

### `POST /portal/logout`

End the authenticated session.

**Auth required:** No (safe to call even unauthenticated)
**Response:** `302` → `GET /portal/login`

**Audit:** `AuditLog(action=logout, module=auth)` — only if session was active

---

## Module: Portal (`/portal`)

### `GET /portal/dashboard`

Employee dashboard page.

**Auth required:** Yes → redirects to `/portal/login` if unauthenticated
**Response:** `200 text/html` — `portal/dashboard.html`

**Template variables:**

| Variable | Type | Description |
|---|---|---|
| `funcionario` | `Funcionario` | Authenticated employee ORM object |
| `company_name` | string | From `COMPANY_NAME` env var (via context processor) |
| `company_logo` | string | From `COMPANY_LOGO` env var (empty = SVG fallback) |

---

## Module: Funcionários (planned — `/admin/funcionarios`)

> Not yet implemented. Planned for next sprint.

| Method | Path | Description |
|---|---|---|
| `GET` | `/admin/funcionarios` | List all active employees |
| `POST` | `/admin/funcionarios` | Create employee (calls `FuncionarioService.criar`) |
| `GET` | `/admin/funcionarios/<id>` | Employee detail |
| `POST` | `/admin/funcionarios/<id>/editar` | Update employee (calls `FuncionarioService.atualizar`) |
| `POST` | `/admin/funcionarios/<id>/desativar` | Soft-delete (calls `FuncionarioService.desativar`) |

---

## Module: Banco de Horas (planned — `/portal/banco-horas`)

> Not yet implemented. Blocked by Ponto Eletrônico port.

| Method | Path | Description |
|---|---|---|
| `GET` | `/portal/banco-horas` | Employee's current balance and history |

---

## Module: Ponto Eletrônico (planned — `/ponto`)

> Not yet implemented. Reference: `legacy/mu-plugins/externo/ponto.py`

| Method | Path | Description |
|---|---|---|
| `POST` | `/ponto/registrar` | Register a clock-in/out via QR token |
| `GET` | `/ponto/historico/<funcionario_id>` | Time entries for a period |
