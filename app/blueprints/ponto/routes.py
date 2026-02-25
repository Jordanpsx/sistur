# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Blueprint Ponto — Ponto Eletrônico do colaborador.

Rotas
-----
GET  /ponto/           → histórico de batidas do mês atual
POST /ponto/registrar  → registra nova batida para o colaborador logado

Todas as rotas exigem sessão ativa (@login_required).
"""

from __future__ import annotations

from datetime import date, datetime, timezone

from flask import Blueprint, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.ponto import TimeDay, TimeEntry
from app.services.ponto_service import PontoService

bp = Blueprint("ponto", __name__)


# ---------------------------------------------------------------------------
# Helper interno
# ---------------------------------------------------------------------------

def _get_ator_id() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


# ---------------------------------------------------------------------------
# Histórico mensal
# ---------------------------------------------------------------------------

@bp.route("/", methods=["GET"])
@login_required
def historico():
    """
    Exibe o histórico de batidas do mês atual para o colaborador logado.

    Query params:
        mes (int): mês a exibir (1-12). Padrão: mês corrente.
        ano (int): ano a exibir. Padrão: ano corrente.

    Returns:
        Renderização de ponto/index.html com os dias e batidas do mês.
    """
    funcionario_id = _get_ator_id()
    funcionario = db.session.get(Funcionario, funcionario_id)
    if not funcionario or not funcionario.ativo:
        session.clear()
        return redirect(url_for("portal.login"))

    hoje = date.today()
    try:
        mes = int(request.args.get("mes") or hoje.month)
        ano = int(request.args.get("ano") or hoje.year)
    except (TypeError, ValueError):
        mes, ano = hoje.month, hoje.year

    # Intervalo do mês
    inicio = date(ano, mes, 1)
    if mes == 12:
        fim = date(ano + 1, 1, 1)
    else:
        fim = date(ano, mes + 1, 1)

    # Agrega dias do mês
    days = (
        db.session.query(TimeDay)
        .filter(
            TimeDay.funcionario_id == funcionario_id,
            TimeDay.shift_date >= inicio,
            TimeDay.shift_date < fim,
        )
        .order_by(TimeDay.shift_date.desc())
        .all()
    )

    # Batidas agrupadas por dia (dict date → list[TimeEntry])
    entries_raw = (
        db.session.query(TimeEntry)
        .filter(
            TimeEntry.funcionario_id == funcionario_id,
            TimeEntry.shift_date >= inicio,
            TimeEntry.shift_date < fim,
        )
        .order_by(TimeEntry.shift_date.desc(), TimeEntry.punch_time)
        .all()
    )
    entries_by_day: dict[date, list[TimeEntry]] = {}
    for e in entries_raw:
        entries_by_day.setdefault(e.shift_date, []).append(e)

    return render_template(
        "ponto/index.html",
        funcionario=funcionario,
        days=days,
        entries_by_day=entries_by_day,
        mes=mes,
        ano=ano,
        hoje=hoje,
    )


# ---------------------------------------------------------------------------
# Registrar batida
# ---------------------------------------------------------------------------

@bp.route("/registrar", methods=["POST"])
@login_required
def registrar():
    """
    Registra uma nova batida de ponto para o colaborador logado.

    Form fields:
        punch_time (str, opcional): ISO datetime da batida.
                                    Se não enviado, usa o momento atual no servidor.

    Returns:
        Redirect para ponto.historico com flash de sucesso ou erro.
    """
    ator_id = _get_ator_id()

    # Aceita horário enviado pelo cliente (JS) ou usa o servidor como fallback
    punch_time_raw = request.form.get("punch_time")
    if punch_time_raw:
        try:
            punch_time = datetime.fromisoformat(punch_time_raw).replace(tzinfo=timezone.utc)
        except ValueError:
            punch_time = None
    else:
        punch_time = None

    try:
        entry = PontoService.registrar_batida(
            funcionario_id=ator_id,
            punch_time=punch_time,
            source="employee",
            ator_id=ator_id,
        )
        flash(
            f"Ponto registrado às {entry.punch_time.strftime('%H:%M')} — "
            f"{entry.punch_type.value.replace('_', ' ').title()}.",
            "sucesso",
        )
    except ValueError as exc:
        flash(str(exc), "erro")

    return redirect(url_for("ponto.historico"))
