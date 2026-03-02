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

import json
from collections import defaultdict
from datetime import date, datetime, timezone

from flask import Blueprint, abort, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.ponto import TimeDay, TimeEntry
from app.services.configuracao_service import ConfiguracaoService
from app.services.ponto_service import PontoService

bp = Blueprint("ponto", __name__)


# ---------------------------------------------------------------------------
# Master Switch — bloqueia o módulo se desabilitado nas configurações globais
# ---------------------------------------------------------------------------

@bp.before_request
def verificar_modulo_ativo():
    """Retorna 403 se o módulo Ponto estiver desabilitado nas configurações globais.

    Super admins (role.is_super_admin=True) sempre têm acesso, independentemente
    do estado do master switch, para evitar lockout acidental.
    """
    if not ConfiguracaoService.is_module_enabled("ponto"):
        fid = session.get("funcionario_id")
        if fid:
            f = db.session.get(Funcionario, fid)
            if f and f.role and f.role.is_super_admin:
                return  # Super admin sempre tem acesso
        abort(403)


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

    # Batidas de hoje sempre buscadas independentemente do mês visualizado,
    # para que o botão de registrar ponto reflita o estado real do dia atual.
    if inicio <= hoje < fim:
        today_entries = entries_by_day.get(hoje, [])
    else:
        today_entries = (
            db.session.query(TimeEntry)
            .filter(
                TimeEntry.funcionario_id == funcionario_id,
                TimeEntry.shift_date == hoje,
            )
            .order_by(TimeEntry.punch_time)
            .all()
        )

    return render_template(
        "ponto/index.html",
        funcionario=funcionario,
        days=days,
        entries_by_day=entries_by_day,
        today_entries=today_entries,
        mes=mes,
        ano=ano,
        hoje=hoje,
    )


# ---------------------------------------------------------------------------
# Registrar batida
# ---------------------------------------------------------------------------

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


# ---------------------------------------------------------------------------
# Análise detalhada (gráficos + tabela expandida)
# ---------------------------------------------------------------------------

@bp.route("/analise", methods=["GET"])
@login_required
def analise():
    """
    Página de análise detalhada do banco de horas.

    Exibe:
    - Gráfico de barras: horas trabalhadas por dia (Chart.js)
    - Gráfico de linha: saldo acumulado ao longo do mês
    - Tabela expandida com colunas por tipo de batida (CLT layout)
    - Cards: dias completos, dias com pendência, maior saldo, maior débito

    Query params:
        mes (int): mês a exibir (1-12). Padrão: mês corrente.
        ano (int): ano a exibir. Padrão: ano corrente.
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

    inicio = date(ano, mes, 1)
    fim = date(ano + 1, 1, 1) if mes == 12 else date(ano, mes + 1, 1)

    # Agrega dias do mês (ascendente para gráfico)
    days = (
        db.session.query(TimeDay)
        .filter(
            TimeDay.funcionario_id == funcionario_id,
            TimeDay.shift_date >= inicio,
            TimeDay.shift_date < fim,
        )
        .order_by(TimeDay.shift_date)
        .all()
    )

    # Batidas agrupadas por dia
    entries_raw = (
        db.session.query(TimeEntry)
        .filter(
            TimeEntry.funcionario_id == funcionario_id,
            TimeEntry.shift_date >= inicio,
            TimeEntry.shift_date < fim,
        )
        .order_by(TimeEntry.shift_date, TimeEntry.punch_time)
        .all()
    )
    entries_by_day: dict[date, list[TimeEntry]] = defaultdict(list)
    for e in entries_raw:
        entries_by_day[e.shift_date].append(e)

    # --- Dados para Chart.js ---
    chart_labels = []
    chart_worked = []     # horas trabalhadas (float)
    chart_balance = []    # saldo acumulado em minutos
    chart_expected = []   # jornada esperada (horas)

    DOW_ABBR = ["Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"]
    accumulated = 0
    for d in days:
        dow = DOW_ABBR[d.shift_date.weekday()]
        chart_labels.append(f"{dow} {d.shift_date.strftime('%d/%m')}")
        chart_worked.append(round(d.minutos_trabalhados / 60, 2))
        chart_expected.append(round(d.expected_minutes_snapshot / 60, 2))
        accumulated += d.saldo_calculado_minutos
        chart_balance.append(round(accumulated / 60, 2))

    # --- Stats ---
    total_min = sum(d.minutos_trabalhados for d in days)
    total_saldo = sum(d.saldo_calculado_minutos for d in days)
    dias_ok = sum(1 for d in days if not d.needs_review)
    dias_revisar = sum(1 for d in days if d.needs_review)
    maior_credito = max((d.saldo_calculado_minutos for d in days), default=0)
    maior_debito  = min((d.saldo_calculado_minutos for d in days), default=0)

    # --- Tabela detalhada (layout CLT) ---
    # Para cada dia: Entrada | Saída Almoço | Volta Almoço | Saída
    TYPE_ORDER = ["clock_in", "lunch_start", "lunch_end", "clock_out"]
    tabela_rows = []
    for d in days:
        entries = entries_by_day.get(d.shift_date, [])
        by_type: dict[str, str] = {}
        for e in entries:
            key = e.punch_type.value
            if key not in by_type:
                by_type[key] = e.punch_time.strftime("%H:%M")
        tabela_rows.append({
            "shift_date": d.shift_date,
            "dow": DOW_ABBR[d.shift_date.weekday()],
            "clock_in":    by_type.get("clock_in", "—"),
            "lunch_start": by_type.get("lunch_start", "—"),
            "lunch_end":   by_type.get("lunch_end", "—"),
            "clock_out":   by_type.get("clock_out", "—"),
            "extras":      [e.punch_time.strftime("%H:%M") for e in entries
                            if e.punch_type.value not in TYPE_ORDER],
            "minutos_trabalhados":  d.minutos_trabalhados,
            "saldo_calculado":      d.saldo_calculado_minutos,
            "needs_review":         d.needs_review,
            "is_today":             d.shift_date == hoje,
        })

    return render_template(
        "ponto/analise.html",
        funcionario=funcionario,
        mes=mes,
        ano=ano,
        hoje=hoje,
        days=days,
        tabela_rows=tabela_rows,
        # stats
        total_min=total_min,
        total_saldo=total_saldo,
        dias_ok=dias_ok,
        dias_revisar=dias_revisar,
        maior_credito=maior_credito,
        maior_debito=maior_debito,
        # chart data (JSON strings)
        chart_labels=json.dumps(chart_labels),
        chart_worked=json.dumps(chart_worked),
        chart_expected=json.dumps(chart_expected),
        chart_balance=json.dumps(chart_balance),
    )

