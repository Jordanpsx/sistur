# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Reservas Blueprint — Headless API backend para sistema de reservas genérico.

Rotas
-----
GET  /reservas/                      → index()          # placeholder
GET  /reservas/sources               → listar_fontes()  # placeholder
GET  /reservas/<int:reserva_id>      → detalhe()        # placeholder

Todas as rotas retornam JSON headless-API com status 501 Not Implemented
até que as lógicas de negócio sejam implementadas na camada de serviços.
"""

from __future__ import annotations

from flask import Blueprint, jsonify

from app.blueprints.portal.routes import login_required
from app.core.permissions import require_permission

bp = Blueprint("reservas", __name__)


# ---------------------------------------------------------------------------
# Helper
# ---------------------------------------------------------------------------


def _not_implemented():
    """Retorna resposta headless-API placeholder."""
    return jsonify({"status": "not_implemented", "message": "Endpoint not yet implemented"}), 501


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@bp.route("/", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def index():
    """Lista todas as reservas do usuário logado.

    Returns:
        JSON com status 501 Not Implemented (placeholder).
    """
    return _not_implemented()


@bp.route("/sources", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def listar_fontes():
    """Lista todas as fontes de reserva (locais/venues).

    Returns:
        JSON com status 501 Not Implemented (placeholder).
    """
    return _not_implemented()


@bp.route("/<int:reserva_id>", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def detalhe(reserva_id: int):
    """Retorna detalhes de uma reserva específica.

    Args:
        reserva_id: ID da reserva a consultar.

    Returns:
        JSON com status 501 Not Implemented (placeholder).
    """
    return _not_implemented()
