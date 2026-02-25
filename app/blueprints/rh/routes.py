# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
RH Blueprint — Gestão de Funcionários.

Rotas
-----
GET  /rh/funcionarios                   → lista de funcionários ativos (com filtro por nome/CPF)
GET  /rh/funcionarios/novo              → formulário de cadastro
POST /rh/funcionarios/novo              → salva novo funcionário
GET  /rh/funcionarios/<id>/editar       → formulário de edição pré-preenchido
POST /rh/funcionarios/<id>/editar       → salva edição
POST /rh/funcionarios/<id>/desativar    → desativa funcionário (soft-delete)

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente no role do funcionário logado (@require_permission).
"""

from __future__ import annotations

from flask import Blueprint, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.core.models import Area
from app.core.permissions import require_permission
from app.extensions import db
from app.models.funcionario import Funcionario
from app.services.funcionario_service import FuncionarioService
from app.services.role_service import RoleService

bp = Blueprint("rh", __name__)


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
    """
    Exibe a tabela de funcionários ativos com filtro por nome ou CPF.

    Query params:
        q (str): Termo de busca livre filtrado em nome e CPF.

    Returns:
        Renderização de rh/funcionarios/list.html com a lista filtrada.
    """
    q = (request.args.get("q") or "").strip()

    query = db.session.query(Funcionario).filter_by(ativo=True)

    if q:
        termo = f"%{q}%"
        query = query.filter(
            db.or_(
                Funcionario.nome.ilike(termo),
                Funcionario.cpf.ilike(termo),
            )
        )

    funcionarios = query.order_by(Funcionario.nome).all()

    return render_template(
        "rh/funcionarios/list.html",
        funcionarios=funcionarios,
        q=q,
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

    # Campos adicionais não cobertos pelo criar() padrão
    dados_extras: dict = {}
    for campo in ("email", "telefone", "data_admissao", "ctps", "ctps_uf", "cbo"):
        valor = request.form.get(campo) or None
        if valor:
            dados_extras[campo] = valor
    if dados_extras:
        FuncionarioService.atualizar(f.id, dados_extras, ator_id)

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
