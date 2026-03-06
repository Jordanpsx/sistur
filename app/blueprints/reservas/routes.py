# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Reservas Blueprint — Sistema de reservas genérico (bookings).

Rotas HTML (Dashboard)
---------------------
GET  /reservas/                      → index()          # dashboard do módulo
GET  /reservas/sources               → listar_fontes()  # listagem de venues
GET  /reservas/<int:reserva_id>      → detalhe()        # detalhe de uma reserva

Rotas JSON API (Headless)
-------------------------
GET  /reservas/api/                  → api_listar()                  # lista com filtros
GET  /reservas/api/sources           → api_listar_fontes()           # lista de venues
GET  /reservas/api/<int:id>          → api_detalhe(id)               # detalhe de reserva
GET  /reservas/api/disponibilidade   → api_disponibilidade()         # disponibilidade
POST /reservas/api/                  → api_criar()                   # cria nova reserva

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente (@require_permission).
"""

from __future__ import annotations

from datetime import date

from flask import Blueprint, abort, jsonify, render_template, request, session

from app.blueprints.portal.routes import login_required
from app.core.permissions import require_permission
from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.reservas import Reserva, ReservaSource
from app.services.configuracao_service import ConfiguracaoService
from app.services.reserva_service import ReservaService

bp = Blueprint("reservas", __name__)


# ---------------------------------------------------------------------------
# Master switch — module enabled check
# ---------------------------------------------------------------------------


@bp.before_request
def verificar_modulo_ativo():
    """Retorna 403 se o módulo Reservas estiver desabilitado nas configurações globais.

    Super admins sempre têm acesso para evitar lockout.
    """
    if not ConfiguracaoService.is_module_enabled("reservas"):
        fid = session.get("funcionario_id")
        if fid:
            f = db.session.get(Funcionario, fid)
            if f and f.role and f.role.is_super_admin:
                return
        abort(403)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _get_ator_id() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


def _serializar_reserva(r: Reserva, *, include_items: bool = False) -> dict:
    """Converte uma Reserva em dict JSON-serializável."""
    d = {
        "id": r.id,
        "group_id": r.group_id,
        "version": r.version,
        "source_id": r.source_id,
        "category_id": r.category_id,
        "customer_name": r.customer_name,
        "customer_document": r.customer_document,
        "origin": r.origin.value if r.origin else None,
        "status": r.status.value if r.status else None,
        "check_in_date": r.check_in_date.isoformat() if r.check_in_date else None,
        "check_out_date": r.check_out_date.isoformat() if r.check_out_date else None,
        "expires_at": r.expires_at.isoformat() if r.expires_at else None,
        "criado_em": r.criado_em.isoformat() if r.criado_em else None,
    }
    if include_items:
        d["items"] = [
            {
                "id": link.id,
                "item_id": link.item_id,
                "item_name": link.item.name if link.item else None,
                "quantity": link.quantity,
                "locked_price": str(link.locked_price),
            }
            for link in r.items
            if link.deleted_at is None
        ]
    return d


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


# ---------------------------------------------------------------------------
# JSON API Routes (Headless)
# ---------------------------------------------------------------------------


@bp.route("/api/", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def api_listar():
    """Lista reservas ativas com filtros e paginação (JSON API).

    Query params:
        source_id: int — filtra por ID da fonte
        status: str — filtra por status (PENDING, PAID, CANCELED, COMPLETED)
        q: str — busca livre (nome ou CPF do cliente)
        page: int — número da página (padrão 1)
        per_page: int — itens por página (padrão 20)

    Returns:
        200 JSON com lista de reservas, total e info de paginação
        400 Se status inválido
    """
    source_id = request.args.get("source_id", type=int)
    status = request.args.get("status") or None
    q = (request.args.get("q") or "").strip() or None
    page = request.args.get("page", 1, type=int)
    per_page = request.args.get("per_page", 20, type=int)

    try:
        reservas, total = ReservaService.listar(
            source_id=source_id,
            status=status,
            q=q,
            page=page,
            per_page=per_page,
        )
    except ValueError as exc:
        return jsonify({"erro": str(exc)}), 400

    return jsonify({
        "total": total,
        "page": page,
        "per_page": per_page,
        "reservas": [_serializar_reserva(r) for r in reservas],
    }), 200


@bp.route("/api/sources", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def api_listar_fontes():
    """Lista fontes (venues) ativas com categorias aninhadas (JSON API).

    Returns:
        200 JSON com lista de fontes
    """
    sources = ReservaService.listar_fontes()

    return jsonify({
        "sources": [
            {
                "id": s.id,
                "name": s.name,
                "is_active": s.is_active,
                "criado_em": s.criado_em.isoformat() if s.criado_em else None,
                "categories": [
                    {
                        "id": c.id,
                        "name": c.name,
                        "criado_em": c.criado_em.isoformat() if c.criado_em else None,
                    }
                    for c in s.categories
                    if c.deleted_at is None
                ],
            }
            for s in sources
        ]
    }), 200


@bp.route("/api/disponibilidade", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def api_disponibilidade():
    """Calcula disponibilidade de itens em uma categoria para um período (JSON API).

    Query params (todos obrigatórios):
        category_id: int — ID da categoria
        check_in: str — data de início (YYYY-MM-DD)
        check_out: str — data de término (YYYY-MM-DD)

    Returns:
        200 JSON com lista de itens e disponibilidade
        400 Se parâmetros obrigatórios ausentes ou datas inválidas
    """
    category_id = request.args.get("category_id", type=int)
    check_in_str = request.args.get("check_in")
    check_out_str = request.args.get("check_out")

    # Validate required params
    if not category_id:
        return jsonify({"erro": "Parâmetro 'category_id' é obrigatório."}), 400
    if not check_in_str:
        return jsonify({"erro": "Parâmetro 'check_in' é obrigatório."}), 400
    if not check_out_str:
        return jsonify({"erro": "Parâmetro 'check_out' é obrigatório."}), 400

    # Parse dates
    try:
        check_in = date.fromisoformat(check_in_str)
        check_out = date.fromisoformat(check_out_str)
    except ValueError:
        return jsonify({"erro": "Formato de data inválido. Use YYYY-MM-DD."}), 400

    try:
        items = ReservaService.verificar_disponibilidade(
            category_id=category_id,
            check_in=check_in,
            check_out=check_out,
        )
    except ValueError as exc:
        return jsonify({"erro": str(exc)}), 400

    return jsonify({
        "category_id": category_id,
        "check_in": check_in.isoformat(),
        "check_out": check_out.isoformat(),
        "items": items,
    }), 200


@bp.route("/api/<int:reserva_id>", methods=["GET"])
@login_required
@require_permission("reservas", "view")
def api_detalhe(reserva_id: int):
    """Retorna detalhes completos de uma reserva (JSON API).

    Args:
        reserva_id: ID da reserva a consultar

    Returns:
        200 JSON com detalhes da reserva e itens
        404 Se reserva não encontrada
    """
    try:
        reserva = ReservaService.detalhe(reserva_id)
    except ValueError:
        return jsonify({"erro": "Reserva não encontrada."}), 404

    return jsonify({
        "reserva": _serializar_reserva(reserva, include_items=True),
    }), 200


@bp.route("/api/", methods=["POST"])
@login_required
@require_permission("reservas", "create")
def api_criar():
    """Cria uma nova reserva (JSON API).

    Request body (JSON):
        source_name: str — nome da fonte (cria se não existir)
        category_name: str — nome da categoria
        customer_name: str — nome do cliente
        customer_document: str — CPF ou CNPJ (opcional)
        origin: str — "WEB" ou "BALCAO"
        check_in_date: str — data de check-in (YYYY-MM-DD)
        check_out_date: str — data de check-out (YYYY-MM-DD, opcional)
        items: list — [{"item_id": int, "quantity": int, "price_override": str}]

    Returns:
        201 JSON com reserva criada e expires_at setado para 15min
        400 Se validação falhar
    """
    data = request.get_json(silent=True) or {}

    # Validate required fields
    required = ["source_name", "category_name", "customer_name", "origin",
                "check_in_date", "items"]
    for field in required:
        if not data.get(field):
            return jsonify({"erro": f"Campo obrigatório ausente: '{field}'."}), 400

    # Parse dates
    try:
        check_in = date.fromisoformat(data["check_in_date"])
        check_out = (
            date.fromisoformat(data["check_out_date"])
            if data.get("check_out_date")
            else None
        )
    except ValueError:
        return jsonify({"erro": "Formato de data inválido. Use YYYY-MM-DD."}), 400

    # Create reservation
    try:
        reserva = ReservaService.criar(
            source_name=data["source_name"],
            category_name=data["category_name"],
            customer_name=data["customer_name"],
            customer_document=data.get("customer_document"),
            origin=data["origin"],
            check_in_date=check_in,
            check_out_date=check_out,
            items_payload=data["items"],
            ator_id=_get_ator_id(),
        )
    except ValueError as exc:
        return jsonify({"erro": str(exc)}), 400

    return jsonify({
        "reserva": _serializar_reserva(reserva, include_items=True),
    }), 201
