# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Admin — Audit Log Viewer.

Rotas
-----
GET /admin/audit-logs  → visualizador de logs de auditoria com filtros e paginação

Controle de Acesso (3 níveis)
------------------------------
1. super_admin (Role.is_super_admin=True)  → acesso irrestrito a todos os logs
2. gerente    (permissão audit.view_all)   → acesso irrestrito a todos os logs
3. supervisor (permissão audit.view)       → acesso limitado aos logs do próprio area_id
"""

from __future__ import annotations

import functools

from flask import (
    Blueprint,
    abort,
    redirect,
    render_template,
    request,
    session,
    url_for,
)

from app.core.models import AuditLog
from app.core.permissions import has_permission
from app.extensions import db
from app.models.funcionario import Funcionario

bp = Blueprint("admin", __name__)

# Número de registros por página
_PER_PAGE = 50


# ---------------------------------------------------------------------------
# Auth helpers
# ---------------------------------------------------------------------------

def _get_ator_sessao() -> Funcionario:
    """Carrega o Funcionario autenticado na sessão ou aborta com 401.

    Returns:
        Instância de Funcionario ativa correspondente à sessão atual.

    Raises:
        HTTP 401: Se não houver sessão ativa ou o funcionário estiver inativo.
    """
    fid = session.get("funcionario_id")
    if not fid:
        abort(401)
    f = db.session.get(Funcionario, fid)
    if not f or not f.ativo:
        session.clear()
        abort(401)
    return f


def _login_required(fn):
    """Redireciona para login se não houver sessão ativa."""
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        if not session.get("funcionario_id"):
            return redirect(url_for("portal.login"))
        return fn(*args, **kwargs)
    return wrapper


def _can_view_audit(funcionario_id: int) -> bool:
    """Verifica se o funcionário tem acesso ao módulo de auditoria (qualquer nível).

    Args:
        funcionario_id: ID do funcionário a verificar.

    Returns:
        True se tiver audit.view_all OU audit.view.
    """
    return has_permission(funcionario_id, "audit", "view_all") or \
           has_permission(funcionario_id, "audit", "view")


def _can_view_all(funcionario_id: int) -> bool:
    """Verifica se o funcionário tem acesso irrestrito a todos os logs.

    Retorna True para super_admin (bypass automático via has_permission)
    e para funcionários com permissão audit.view_all.

    Args:
        funcionario_id: ID do funcionário a verificar.

    Returns:
        True se tiver acesso irrestrito (super_admin ou gerente).
    """
    return has_permission(funcionario_id, "audit", "view_all")


# ---------------------------------------------------------------------------
# Rota principal
# ---------------------------------------------------------------------------

@bp.route("/audit-logs", methods=["GET"])
@_login_required
def audit_logs():
    """Lista logs de auditoria com filtros dinâmicos e paginação server-side.

    Níveis de acesso:
        - super_admin / gerente (audit.view_all): vê todos os logs.
        - supervisor (audit.view): vê apenas logs onde area_id == seu area_id.
        - Sem permissão: 403.

    Query params:
        user_id (int, opcional):  Filtra pelo ID do ator (Funcionario).
        module  (str, opcional):  Filtra pelo módulo (e.g. 'auth', 'ponto').
        page    (int, opcional):  Número da página (default=1).

    Returns:
        Renderização de admin/audit_logs.html com paginação e filtros ativos.
    """
    ator = _get_ator_sessao()

    # Verificação de acesso
    if not _can_view_audit(ator.id):
        abort(403)

    acesso_total = _can_view_all(ator.id)

    # ── Filtros via query params ────────────────────────────────────────────
    filtro_user_id = request.args.get("user_id", type=int)
    filtro_module  = request.args.get("module", "").strip() or None
    page           = request.args.get("page", 1, type=int)

    # ── Construção da query base ────────────────────────────────────────────
    query = db.session.query(AuditLog)

    if not acesso_total:
        # Supervisor: restringe ao area_id do próprio funcionário
        if ator.area_id:
            query = query.filter(AuditLog.area_id == ator.area_id)
        else:
            # Supervisor sem área definida não vê nada
            query = query.filter(AuditLog.id == None)  # noqa: E711

    # Filtros dinâmicos adicionais
    if filtro_user_id:
        query = query.filter(AuditLog.user_id == filtro_user_id)
    if filtro_module:
        query = query.filter(AuditLog.module == filtro_module)

    # Ordenação e paginação
    query = query.order_by(AuditLog.created_at.desc())
    pagination = query.paginate(page=page, per_page=_PER_PAGE, error_out=False)

    # ── Dados auxiliares para os dropdowns ─────────────────────────────────
    # Lista de todos os módulos registrados nos logs (para o dropdown)
    modulos_query = db.session.query(AuditLog.module).distinct().order_by(AuditLog.module)
    if not acesso_total and ator.area_id:
        modulos_query = modulos_query.filter(AuditLog.area_id == ator.area_id)
    modulos = [row[0] for row in modulos_query.all()]

    # Lista de funcionários que aparecem nos logs (para o dropdown de ator)
    user_ids_query = (
        db.session.query(AuditLog.user_id)
        .filter(AuditLog.user_id.isnot(None))
        .distinct()
    )
    if not acesso_total and ator.area_id:
        user_ids_query = user_ids_query.filter(AuditLog.area_id == ator.area_id)
    user_ids = [row[0] for row in user_ids_query.all()]

    # Resolve nomes dos atores (user_id é polimórfico: aponta para sistur_funcionarios)
    funcionarios_map: dict[int, str] = {}
    if user_ids:
        funcs = (
            db.session.query(Funcionario.id, Funcionario.nome)
            .filter(Funcionario.id.in_(user_ids))
            .all()
        )
        funcionarios_map = {f.id: f.nome for f in funcs}

    return render_template(
        "admin/audit_logs.html",
        pagination=pagination,
        logs=pagination.items,
        modulos=modulos,
        funcionarios_map=funcionarios_map,
        filtro_user_id=filtro_user_id,
        filtro_module=filtro_module,
        acesso_total=acesso_total,
        ator=ator,
    )
