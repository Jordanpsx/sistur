# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
RH Blueprint — Gestão de Funcionários.

Rotas
-----
GET  /rh/                               → dashboard com abas (Visão Geral + Colaboradores)
GET  /rh/funcionarios                   → lista de funcionários (com filtros avançados)
GET  /rh/funcionarios/novo              → formulário de cadastro
POST /rh/funcionarios/novo              → salva novo funcionário
GET  /rh/funcionarios/<id>/editar       → formulário de edição pré-preenchido
POST /rh/funcionarios/<id>/editar       → salva edição
POST /rh/funcionarios/<id>/desativar    → desativa funcionário (soft-delete)
GET  /rh/funcionarios/<id>/banco-horas  → banco de horas detalhado do funcionário (visão RH)

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente no role do funcionário logado (@require_permission).
"""

from __future__ import annotations

import json
from collections import defaultdict
from datetime import date, timedelta

from flask import Blueprint, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.core.models import Area
from app.core.permissions import require_permission
from app.extensions import db
from app.models.funcionario import Funcionario
from app.services.funcionario_service import FuncionarioService
from app.services.rh_service import RHService
from app.services.role_service import RoleService

bp = Blueprint("rh", __name__)


# ---------------------------------------------------------------------------
# Dashboard Principal (abas)
# ---------------------------------------------------------------------------

@bp.route("/", methods=["GET"])
@login_required
@require_permission("funcionarios", "view")
def dashboard():
    """Dashboard RH com duas abas: Visão Geral e Colaboradores.

    Aba 1 (visao-geral): resumo mensal agregado via func.sum() e central de
    alertas com dias marcados como needs_review nos últimos 7 dias.

    Aba 2 (colaboradores): listagem filtrada por nome/CPF, área e status
    (ativo/inativo/todos), com saldo do banco de horas em destaque.

    Query params:
        aba (str): "visao-geral" | "colaboradores". Padrão: "visao-geral".
        q (str): Busca livre por nome ou CPF (Aba 2).
        area_id (int): Filtro de área (Aba 2).
        status (str): "ativo" | "inativo" | "todos" (Aba 2). Padrão: "ativo".

    Returns:
        Renderização de rh/index.html com todos os dados de ambas as abas.
    """
    hoje = date.today()

    # Navegação de mês (padrão: mês atual)
    try:
        mes = int(request.args.get("mes") or hoje.month)
        ano = int(request.args.get("ano") or hoje.year)
    except (ValueError, TypeError):
        mes, ano = hoje.month, hoje.year

    # --- Aba 1: Visão Geral ---
    resumo = RHService.resumo_mensal(ano, mes)
    alertas = RHService.alertas_revisao(7)
    matriz = RHService.matriz_batidas_mes(ano, mes)

    # Meses adjacentes para navegação ← →
    primeiro_do_mes = date(ano, mes, 1)
    mes_ant = (primeiro_do_mes - timedelta(days=1)).replace(day=1)
    mes_prx = (primeiro_do_mes.replace(day=28) + timedelta(days=4)).replace(day=1)

    # Rótulos abreviados de dia da semana para cada dia do mês (1-indexed)
    _abrev = ["Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"]
    dias_semana_labels = {
        d: _abrev[date(ano, mes, d).weekday()]
        for d in range(1, matriz["dias_no_mes"] + 1)
    }

    # --- Aba 2: Colaboradores ---
    q = (request.args.get("q") or "").strip()
    area_id = int(request.args["area_id"]) if request.args.get("area_id") else None
    status = request.args.get("status", "ativo")
    aba = request.args.get("aba", "visao-geral")

    funcionarios = RHService.listar_com_filtros(q=q, area_id=area_id, status=status)
    areas = db.session.query(Area).filter_by(is_active=True).order_by(Area.name).all()

    return render_template(
        "rh/index.html",
        resumo=resumo,
        alertas=alertas,
        matriz=matriz,
        dias_semana_labels=dias_semana_labels,
        funcionarios=funcionarios,
        areas=areas,
        q=q,
        area_id=area_id,
        status=status,
        aba=aba,
        mes=mes,
        ano=ano,
        mes_ant_mes=mes_ant.month,
        mes_ant_ano=mes_ant.year,
        mes_prx_mes=mes_prx.month,
        mes_prx_ano=mes_prx.year,
    )


# ---------------------------------------------------------------------------
# Helpers internos
# ---------------------------------------------------------------------------

def _get_ator_id() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


def _popular_selects() -> tuple[list[Area], list]:
    """
    Retorna as listas necessárias para popular os selects do formulário.

    Returns:
        Tupla (areas, roles) com registros ativos de cada entidade.
    """
    areas = db.session.query(Area).filter_by(is_active=True).order_by(Area.name).all()
    roles = RoleService.listar(apenas_ativos=True)
    return areas, roles


_DIAS_SEMANA = ("segunda", "terca", "quarta", "quinta", "sexta", "sabado", "domingo")


def _parse_jornada(form) -> dict:
    """
    Constrói o dicionário jornada_semanal a partir dos campos do formulário.

    Para cada dia da semana lê três inputs:
        jornada_<dia>_ativo    (checkbox — presente se marcado)
        jornada_<dia>_minutos  (number)
        jornada_<dia>_almoco   (number)

    Returns:
        Dict com estrutura {"segunda": {"ativo": bool, "minutos": int, "almoco": int}, ...}
    """
    jornada = {}
    for dia in _DIAS_SEMANA:
        ativo = form.get(f"jornada_{dia}_ativo") is not None
        minutos = int(form.get(f"jornada_{dia}_minutos") or 0) if ativo else 0
        almoco = int(form.get(f"jornada_{dia}_almoco") or 0) if ativo else 0
        jornada[dia] = {"ativo": ativo, "minutos": minutos, "almoco": almoco}
    return jornada


def _aplicar_senha(funcionario: Funcionario, form, ator_id: int) -> None:
    """
    Aplica nova senha ao funcionário se o campo 'nova_senha' estiver preenchido.

    Valida se nova_senha == confirmar_senha antes de persistir.
    Levanta ValueError se as senhas não coincidem ou são muito curtas.

    Args:
        funcionario: Instância já persistida do Funcionario.
        form:        ImmutableMultiDict do request.form.
        ator_id:     ID do ator que realiza a ação.

    Raises:
        ValueError: Se as senhas não coincidem ou têm menos de 6 caracteres.
    """
    nova = (form.get("nova_senha") or "").strip()
    confirmar = (form.get("confirmar_senha") or "").strip()

    if not nova:
        return  # Campo vazio → mantém senha atual

    if len(nova) < 6:
        raise ValueError("A senha deve ter pelo menos 6 caracteres.")
    if nova != confirmar:
        raise ValueError("As senhas não coincidem.")

    FuncionarioService.definir_senha(funcionario.id, nova, ator_id)


def _aplicar_role(funcionario: Funcionario, role_id_str: str | None, ator_id: int) -> None:
    """
    Aplica ou remove o role de um funcionário conforme o valor recebido do formulário.

    Se role_id_str for vazio/None e o funcionário já tiver um role, remove.
    Se role_id_str for preenchido e diferente do atual, atribui o novo role.
    Caso contrário, não faz nada (evita logs desnecessários).

    Args:
        funcionario:  Instância do Funcionario já persistida.
        role_id_str:  Valor bruto do campo 'role_id' do formulário.
        ator_id:      ID do funcionário que está realizando a ação.
    """
    role_id_novo = int(role_id_str) if role_id_str else None

    if role_id_novo and role_id_novo != funcionario.role_id:
        RoleService.atribuir_ao_funcionario(funcionario.id, role_id_novo, ator_id)
    elif not role_id_novo and funcionario.role_id:
        RoleService.remover_do_funcionario(funcionario.id, ator_id)


# ---------------------------------------------------------------------------
# Listagem
# ---------------------------------------------------------------------------

@bp.route("/funcionarios", methods=["GET"])
@login_required
@require_permission("funcionarios", "view")
def listar_funcionarios():
    """Exibe a tabela de funcionários com filtros por nome/CPF, área e status.

    Query params:
        q (str): Termo de busca livre filtrado em nome e CPF.
        area_id (int): Filtra por área específica.
        status (str): "ativo" | "inativo" | "todos". Padrão: "ativo".

    Returns:
        Renderização de rh/funcionarios/list.html com a lista filtrada.
    """
    q = (request.args.get("q") or "").strip()
    area_id = int(request.args["area_id"]) if request.args.get("area_id") else None
    status = request.args.get("status", "ativo")

    funcionarios = RHService.listar_com_filtros(q=q, area_id=area_id, status=status)
    areas = db.session.query(Area).filter_by(is_active=True).order_by(Area.name).all()

    return render_template(
        "rh/funcionarios/list.html",
        funcionarios=funcionarios,
        areas=areas,
        q=q,
        area_id=area_id,
        status=status,
    )


# ---------------------------------------------------------------------------
# Criar
# ---------------------------------------------------------------------------

@bp.route("/funcionarios/novo", methods=["GET", "POST"])
@login_required
@require_permission("funcionarios", "create")
def novo_funcionario():
    """
    GET  → exibe o formulário em branco para cadastro de novo funcionário.
    POST → valida e persiste o novo funcionário, redireciona para a lista.

    Form fields:
        nome (str): Nome completo — obrigatório.
        cpf (str): CPF em qualquer formato — obrigatório, validado pelo algoritmo.
        email, telefone, cargo, matricula, data_admissao (str): Opcionais.
        ctps, ctps_uf, cbo (str): Documentação trabalhista — opcionais.
        area_id (int): FK para sistur_areas — opcional.
        role_id (int): FK para sistur_roles — opcional, tratado via RoleService.
        minutos_esperados_dia (int): Padrão 480.
        minutos_almoco (int): Padrão 60.

    Returns:
        GET:  Renderização de rh/funcionarios/form.html.
        POST (sucesso): Redirect para rh.listar_funcionarios com flash de sucesso.
        POST (erro):    Renderização do formulário com HTTP 400 e flash de erro.
    """
    areas, roles = _popular_selects()

    if request.method == "GET":
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=None,
            areas=areas,
            roles=roles,
        )

    # --- POST ---
    ator_id = _get_ator_id()

    try:
        f = FuncionarioService.criar(
            nome=request.form.get("nome", ""),
            cpf=request.form.get("cpf", ""),
            cargo=request.form.get("cargo") or None,
            matricula=request.form.get("matricula") or None,
            area_id=int(request.form["area_id"]) if request.form.get("area_id") else None,
            minutos_esperados_dia=int(request.form.get("minutos_esperados_dia") or 480),
            minutos_almoco=int(request.form.get("minutos_almoco") or 60),
            ator_id=ator_id,
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=None,
            areas=areas,
            roles=roles,
        ), 400

    # Campos adicionais + jornada semanal
    dados_extras: dict = {}
    for campo in ("email", "telefone", "data_admissao", "ctps", "ctps_uf", "cbo"):
        valor = request.form.get(campo) or None
        if valor:
            dados_extras[campo] = valor
    dados_extras["jornada_semanal"] = _parse_jornada(request.form)
    FuncionarioService.atualizar(f.id, dados_extras, ator_id)

    # Senha (opcional)
    try:
        _aplicar_senha(f, request.form, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=f,
            areas=areas,
            roles=roles,
        ), 400

    # Aplicar role separadamente (auditado como permission_change)
    _aplicar_role(f, request.form.get("role_id"), ator_id)

    flash(f"Funcionário {f.nome} cadastrado com sucesso.", "sucesso")
    return redirect(url_for("rh.listar_funcionarios"))


# ---------------------------------------------------------------------------
# Editar
# ---------------------------------------------------------------------------

@bp.route("/funcionarios/<int:funcionario_id>/editar", methods=["GET", "POST"])
@login_required
@require_permission("funcionarios", "edit")
def editar_funcionario(funcionario_id: int):
    """
    GET  → exibe o formulário pré-preenchido com os dados atuais do funcionário.
    POST → aplica as alterações e redireciona para a lista.

    Path params:
        funcionario_id (int): PK do funcionário a editar.

    Returns:
        GET:  Renderização de rh/funcionarios/form.html com dados preenchidos.
        POST (sucesso): Redirect para rh.listar_funcionarios com flash de sucesso.
        POST (erro):    Renderização do formulário com HTTP 400 e flash de erro.
    """
    f = db.session.get(Funcionario, funcionario_id)
    if not f or not f.ativo:
        flash("Funcionário não encontrado.", "erro")
        return redirect(url_for("rh.listar_funcionarios"))

    areas, roles = _popular_selects()

    if request.method == "GET":
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=f,
            areas=areas,
            roles=roles,
        )

    # --- POST ---
    ator_id = _get_ator_id()

    dados = {
        campo: request.form.get(campo) or None
        for campo in (
            "nome", "cargo", "matricula", "email", "telefone",
            "data_admissao", "ctps", "ctps_uf", "cbo",
        )
    }
    # Campos numéricos
    if request.form.get("area_id"):
        dados["area_id"] = int(request.form["area_id"])
    else:
        dados["area_id"] = None

    for campo in ("minutos_esperados_dia", "minutos_almoco"):
        if request.form.get(campo):
            dados[campo] = int(request.form[campo])

    dados["jornada_semanal"] = _parse_jornada(request.form)

    try:
        f = FuncionarioService.atualizar(funcionario_id, dados, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=f,
            areas=areas,
            roles=roles,
        ), 400

    # Senha (opcional)
    try:
        _aplicar_senha(f, request.form, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template(
            "rh/funcionarios/form.html",
            funcionario=f,
            areas=areas,
            roles=roles,
        ), 400

    _aplicar_role(f, request.form.get("role_id"), ator_id)

    flash(f"Dados de {f.nome} atualizados com sucesso.", "sucesso")
    return redirect(url_for("rh.listar_funcionarios"))


# ---------------------------------------------------------------------------
# Desativar
# ---------------------------------------------------------------------------

@bp.route("/funcionarios/<int:funcionario_id>/desativar", methods=["POST"])
@login_required
@require_permission("funcionarios", "desativar")
def desativar_funcionario(funcionario_id: int):
    """
    Desativa o funcionário (soft-delete: ativo=False) e redireciona para a lista.

    A ação é auditada automaticamente por FuncionarioService.desativar.
    Após desativação, o funcionário não aparece mais na lista nem consegue
    fazer login no portal.

    Path params:
        funcionario_id (int): PK do funcionário a desativar.

    Returns:
        Redirect para rh.listar_funcionarios com flash de confirmação ou erro.
    """
    ator_id = _get_ator_id()

    try:
        f = FuncionarioService.desativar(funcionario_id, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("rh.listar_funcionarios"))

    flash(f"Funcionário {f.nome} desativado.", "aviso")
    return redirect(url_for("rh.listar_funcionarios"))


# ---------------------------------------------------------------------------
# Reprocessamento em Lote (Ponto Eletrônico)
# ---------------------------------------------------------------------------

@bp.route("/funcionarios/<int:funcionario_id>/reprocessar_ponto", methods=["POST"])
@login_required
@require_permission("funcionarios", "edit")
def reprocessar_ponto(funcionario_id: int):
    """
    Aciona o reprocessamento em lote do saldo de horas para o funcionário dado.

    Form fields:
        data_inicio (str): ISO Format (YYYY-MM-DD).
        data_fim (str): ISO Format (YYYY-MM-DD).
        sobrescrever_jornada (bool opcional): Se presente, atualiza o snapshot do
                                              passado com os dados do contrato atual.

    Returns:
        Redirect para a listagem ou perfil com flash message.
    """
    from datetime import datetime
    from app.services.ponto_service import PontoService

    ator_id = _get_ator_id()
    
    data_inicio_str = request.form.get("data_inicio")
    data_fim_str = request.form.get("data_fim")
    sobrescrever = request.form.get("sobrescrever_jornada") is not None

    if not data_inicio_str or not data_fim_str:
        flash("Ambas as datas de início e fim são obrigatórias.", "erro")
        return redirect(url_for("rh.listar_funcionarios"))

    try:
        data_inicio = datetime.strptime(data_inicio_str, "%Y-%m-%d").date()
        data_fim = datetime.strptime(data_fim_str, "%Y-%m-%d").date()
    except ValueError:
        flash("Formato de data inválido.", "erro")
        return redirect(url_for("rh.listar_funcionarios"))

    try:
        resultado = PontoService.reprocess_interval(
            funcionario_id=funcionario_id,
            start_date=data_inicio,
            end_date=data_fim,
            ator_id=ator_id,
            override_snapshots=sobrescrever
        )
        
        dias = resultado["dias_processados"]
        if dias > 0:
            flash(f"Reprocessamento concluído! {dias} dia(s) atualizado(s) com sucesso.", "sucesso")
        else:
            flash(f"Nenhum registro de ponto encontrado para reprocessar neste período.", "aviso")
            
    except Exception as exc:
        flash(f"Erro ao reprocessar ponto: {str(exc)}", "erro")

    # Idealmente poderia redirecionar para a página de edição do funcionário, mantemos na listagem por padrão
    return redirect(url_for("rh.editar_funcionario", funcionario_id=funcionario_id))


# ---------------------------------------------------------------------------
# Banco de Horas Detalhado — visão do RH para qualquer funcionário
# ---------------------------------------------------------------------------

@bp.route("/funcionarios/<int:funcionario_id>/banco-horas", methods=["GET"])
@login_required
@require_permission("funcionarios", "view")
def banco_horas_funcionario(funcionario_id: int):
    """Exibe o banco de horas detalhado de um funcionário específico para o RH.

    Reutiliza o template ponto/analise.html com visão administrativa:
    o gestor pode ver o histórico de qualquer colaborador ativo, com gráficos,
    estatísticas e tabela diária no layout CLT. A navegação de meses e o
    botão "Voltar" apontam para o contexto do RH (não do portal do colaborador).

    Path params:
        funcionario_id (int): PK do funcionário a consultar.

    Query params:
        mes (int): mês a exibir (1–12). Padrão: mês corrente.
        ano (int): ano a exibir. Padrão: ano corrente.

    Returns:
        Renderização de ponto/analise.html com dados do funcionário alvo.
        HTTP 404 se o funcionário não for encontrado.
    """
    from app.models.ponto import TimeDay, TimeEntry

    funcionario = db.session.get(Funcionario, funcionario_id)
    if not funcionario:
        flash("Funcionário não encontrado.", "erro")
        return redirect(url_for("rh.dashboard", aba="colaboradores"))

    hoje = date.today()
    try:
        mes = int(request.args.get("mes") or hoje.month)
        ano = int(request.args.get("ano") or hoje.year)
    except (TypeError, ValueError):
        mes, ano = hoje.month, hoje.year

    inicio = date(ano, mes, 1)
    fim = date(ano + 1, 1, 1) if mes == 12 else date(ano, mes + 1, 1)

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
    entries_by_day: dict[date, list] = defaultdict(list)
    for e in entries_raw:
        entries_by_day[e.shift_date].append(e)

    DOW_ABBR = ["Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"]
    accumulated = 0
    chart_labels, chart_worked, chart_balance, chart_expected = [], [], [], []
    for d in days:
        dow = DOW_ABBR[d.shift_date.weekday()]
        chart_labels.append(f"{dow} {d.shift_date.strftime('%d/%m')}")
        chart_worked.append(round(d.minutos_trabalhados / 60, 2))
        chart_expected.append(round(d.expected_minutes_snapshot / 60, 2))
        accumulated += d.saldo_calculado_minutos
        chart_balance.append(round(accumulated / 60, 2))

    total_min = sum(d.minutos_trabalhados for d in days)
    total_saldo = sum(d.saldo_calculado_minutos for d in days)
    dias_ok = sum(1 for d in days if not d.needs_review)
    dias_revisar = sum(1 for d in days if d.needs_review)
    maior_credito = max((d.saldo_calculado_minutos for d in days), default=0)
    maior_debito = min((d.saldo_calculado_minutos for d in days), default=0)

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
            "minutos_trabalhados": d.minutos_trabalhados,
            "saldo_calculado":     d.saldo_calculado_minutos,
            "needs_review":        d.needs_review,
            "is_today":            d.shift_date == hoje,
        })

    return render_template(
        "ponto/analise.html",
        funcionario=funcionario,
        mes=mes,
        ano=ano,
        hoje=hoje,
        days=days,
        tabela_rows=tabela_rows,
        total_min=total_min,
        total_saldo=total_saldo,
        dias_ok=dias_ok,
        dias_revisar=dias_revisar,
        maior_credito=maior_credito,
        maior_debito=maior_debito,
        chart_labels=json.dumps(chart_labels),
        chart_worked=json.dumps(chart_worked),
        chart_expected=json.dumps(chart_expected),
        chart_balance=json.dumps(chart_balance),
        # flags para o template adaptar navegação ao contexto RH
        from_rh=True,
        back_url=url_for("rh.dashboard", aba="colaboradores"),
    )
