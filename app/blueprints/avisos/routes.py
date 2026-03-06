# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Blueprint Avisos — Módulo de Notificações Internas.

Rotas
-----
GET  /avisos/                         → historico()    — lista avisos do colaborador logado
POST /avisos/<id>/marcar-lido         → marcar_lido()  — marca aviso como lido (JSON)
POST /avisos/marcar-todos-lidos       → marcar_todos() — marca todos como lidos
POST /avisos/<id>/deletar             → deletar()      — exclui aviso (com guarda IDOR)

Todas as rotas exigem sessão ativa (@login_required).
Segurança IDOR: toda mutação verifica aviso.destinatario_id == fid antes de agir.
"""

from __future__ import annotations

from flask import Blueprint, abort, flash, jsonify, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.extensions import db
from app.models.avisos import Aviso
from app.services.aviso_service import AvisoService

bp = Blueprint("avisos", __name__)


# ---------------------------------------------------------------------------
# Helper interno
# ---------------------------------------------------------------------------

def _get_fid() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


# ---------------------------------------------------------------------------
# Histórico / listagem
# ---------------------------------------------------------------------------

@bp.route("/", methods=["GET"])
@login_required
def index():
    """Exibe a lista paginada de notificações do colaborador logado.

    Query params:
        page (int): Página atual. Padrão: 1.

    Returns:
        Renderização de avisos/index.html com avisos paginados.
    """
    fid = _get_fid()
    try:
        page = int(request.args.get("page", 1))
    except (TypeError, ValueError):
        page = 1

    avisos_pag = AvisoService.listar(fid, page=page)

    return render_template(
        "avisos/index.html",
        avisos=avisos_pag,
        page=page,
    )


# ---------------------------------------------------------------------------
# Marcar como lido (AJAX-friendly — retorna JSON)
# ---------------------------------------------------------------------------

@bp.route("/<int:aviso_id>/marcar-lido", methods=["POST"])
@login_required
def marcar_lido(aviso_id: int):
    """Marca um aviso específico como lido.

    Verifica que o aviso pertence ao funcionário logado antes de agir (IDOR guard).

    Args:
        aviso_id: PK do aviso a marcar.

    Returns:
        JSON {"ok": true} em sucesso ou {"ok": false, "erro": "..."} em falha.
        Redireciona para /avisos/ se chamado sem Accept: application/json.
    """
    fid = _get_fid()
    aviso = db.session.get(Aviso, aviso_id)

    if not aviso or aviso.destinatario_id != fid:
        if request.headers.get("Accept") == "application/json":
            return jsonify({"ok": False, "erro": "Aviso não encontrado."}), 404
        abort(404)

    try:
        AvisoService.marcar_lido(aviso_id, ator_id=fid)
        if request.headers.get("Accept") == "application/json":
            return jsonify({"ok": True})
    except ValueError as exc:
        if request.headers.get("Accept") == "application/json":
            return jsonify({"ok": False, "erro": str(exc)}), 400
        flash(str(exc), "erro")

    return redirect(url_for("avisos.index"))


# ---------------------------------------------------------------------------
# Marcar todos como lidos
# ---------------------------------------------------------------------------

@bp.route("/marcar-todos-lidos", methods=["POST"])
@login_required
def marcar_todos():
    """Marca todos os avisos não lidos do colaborador logado como lidos.

    Returns:
        Redirect para /avisos/ com flash de confirmação.
    """
    fid = _get_fid()
    count = AvisoService.marcar_todos_lidos(fid, ator_id=fid)
    if count > 0:
        flash(f"{count} aviso(s) marcado(s) como lido(s).", "sucesso")
    return redirect(url_for("avisos.index"))


# ---------------------------------------------------------------------------
# Excluir aviso
# ---------------------------------------------------------------------------

@bp.route("/<int:aviso_id>/deletar", methods=["POST"])
@login_required
def deletar(aviso_id: int):
    """Remove um aviso do colaborador logado.

    Verifica que o aviso pertence ao funcionário logado antes de agir (IDOR guard).

    Args:
        aviso_id: PK do aviso a remover.

    Returns:
        Redirect para /avisos/ com flash de confirmação ou erro.
    """
    fid = _get_fid()
    aviso = db.session.get(Aviso, aviso_id)

    if not aviso or aviso.destinatario_id != fid:
        abort(404)

    try:
        AvisoService.deletar(aviso_id, ator_id=fid)
        flash("Aviso excluído.", "sucesso")
    except ValueError as exc:
        flash(str(exc), "erro")

    return redirect(url_for("avisos.index"))
