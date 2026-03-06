# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Reservas Blueprint — Sistema de reservas genérico (bookings).

Rotas
-----
GET  /reservas/                      → index()          # dashboard do módulo
GET  /reservas/sources               → listar_fontes()  # listagem de venues
GET  /reservas/<int:reserva_id>      → detalhe()        # detalhe de uma reserva

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente (@require_permission).
"""

from __future__ import annotations

from flask import Blueprint, render_template

from app.blueprints.portal.routes import login_required
from app.core.permissions import require_permission
from app.extensions import db
from app.models.reservas import Reserva, ReservaSource

bp = Blueprint("reservas", __name__)


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------


@bp.route("/", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def index():
    """Exibe o dashboard principal do módulo Reservas.

    Mostra contadores: total de fontes (venues), total de reservas ativas
    e resumo de status das reservas.

    Returns:
        Renderização de reservas/dashboard.html.
    """
    from app.models.reservas import ReservaStatus
    from sqlalchemy import func

    # Conta de fontes ativas
    total_sources = db.session.query(ReservaSource).filter(
        ReservaSource.deleted_at.is_(None)
    ).count()

    # Contagem por status (apenas reservas ativas, excluindo versões arquivadas)
    status_counts = db.session.query(
        Reserva.status,
        func.count(Reserva.id).label("count")
    ).filter(
        Reserva.deleted_at.is_(None),
        Reserva.status != ReservaStatus.ARCHIVED_VERSION
    ).group_by(Reserva.status).all()

    status_dict = {status.value: count for status, count in status_counts}
    total_reservas = sum(status_dict.values())

    return render_template(
        "reservas/dashboard.html",
        total_sources=total_sources,
        total_reservas=total_reservas,
        status_dict=status_dict,
    )


@bp.route("/sources", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def listar_fontes():
    """Lista todas as fontes de reserva (venues/locais).

    Mostra nome, número de categorias e data de criação de cada fonte ativa.

    Returns:
        Renderização de reservas/sources.html.
    """
    sources = db.session.query(ReservaSource).filter(
        ReservaSource.deleted_at.is_(None)
    ).order_by(ReservaSource.name).all()

    return render_template(
        "reservas/sources.html",
        sources=sources,
    )


@bp.route("/<int:reserva_id>", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def detalhe(reserva_id: int):
    """Retorna detalhes de uma reserva específica.

    Args:
        reserva_id: ID da reserva a consultar.

    Returns:
        Renderização de reservas/detalhe.html ou erro 404.
    """
    reserva = db.session.get(Reserva, reserva_id)

    if not reserva or reserva.deleted_at is not None:
        return render_template(
            "reservas/erro.html",
            mensagem="Reserva não encontrada.",
            titulo="Erro 404"
        ), 404

    return render_template(
        "reservas/detalhe.html",
        reserva=reserva,
    )
