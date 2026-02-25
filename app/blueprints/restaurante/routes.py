# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Restaurante Blueprint — CMV e Fichas Técnicas.

Rotas
-----
GET  /restaurante/                              → dashboard CMV (stub)
GET  /restaurante/receitas                      → lista produtos com ficha técnica
GET  /restaurante/receitas/<produto_id>         → detalhe da ficha técnica
POST /restaurante/receitas/<produto_id>/item    → adiciona ingrediente à ficha
POST /restaurante/receitas/item/<id>/editar     → edita item da ficha
POST /restaurante/receitas/item/<id>/remover    → remove item da ficha

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente (@require_permission).

Nota:
    O dashboard CMV completo (gráficos, percentual CMV, DRE) é deferred.
    Esta versão entrega apenas o esqueleto de navegação e as fichas técnicas.
"""

from __future__ import annotations

from flask import Blueprint, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.core.permissions import require_permission
from app.extensions import db
from app.models.estoque import Produto, TipoProduto, Unidade
from app.services.estoque_service import ProdutoService
from app.services.receita_service import ReceitaService

bp = Blueprint("restaurante", __name__)


# ---------------------------------------------------------------------------
# Helper interno
# ---------------------------------------------------------------------------

def _get_ator_id() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


# ---------------------------------------------------------------------------
# Dashboard CMV
# ---------------------------------------------------------------------------

@bp.route("/")
@login_required
@require_permission("restaurante", "view")
def dashboard():
    """Exibe o painel principal do módulo Restaurante (CMV).

    Versão atual: stub de navegação.
    Implementação completa do CMV (percentual, gráficos, DRE) é deferred.

    Returns:
        Renderização de restaurante/dashboard.html.
    """
    # Contagem de produtos com ficha técnica (tipo MANUFACTURED)
    total_com_receita = (
        db.session.query(Produto)
        .filter_by(tipo=TipoProduto.MANUFACTURED, ativo=True)
        .count()
    )

    return render_template(
        "restaurante/dashboard.html",
        total_com_receita=total_com_receita,
    )


# ---------------------------------------------------------------------------
# Fichas Técnicas (Receitas)
# ---------------------------------------------------------------------------

@bp.route("/receitas")
@login_required
@require_permission("cmv", "view")
def listar_receitas():
    """Exibe a lista de produtos fabricados com ficha técnica configurável.

    Query params:
        q (str): Busca livre por nome do produto.

    Returns:
        Renderização de restaurante/receitas/list.html.
    """
    q = (request.args.get("q") or "").strip()

    produtos = ProdutoService.listar(
        apenas_ativos=True,
        tipo=TipoProduto.MANUFACTURED,
        q=q or None,
    )

    return render_template(
        "restaurante/receitas/list.html",
        produtos=produtos,
        q=q,
    )


@bp.route("/receitas/<int:produto_id>")
@login_required
@require_permission("cmv", "view")
def detalhe_receita(produto_id: int):
    """Exibe a ficha técnica completa de um produto fabricado.

    Inclui: lista de ingredientes, quantidades brutas/líquidas,
    fator de rendimento de cada item e custo total calculado.

    Path params:
        produto_id (int): PK do produto fabricado.

    Returns:
        Renderização de restaurante/receitas/detail.html.
    """
    try:
        produto = ProdutoService.buscar_por_id(produto_id)
    except ValueError:
        flash("Produto não encontrado.", "erro")
        return redirect(url_for("restaurante.listar_receitas"))

    itens = ReceitaService.listar_por_produto(produto_id)
    custo_total = ReceitaService.calcular_custo_receita(produto_id)

    # Ingredientes disponíveis para adicionar (RAW e MANUFACTURED ativos, exceto o próprio)
    ingredientes_disponiveis = (
        db.session.query(Produto)
        .filter(
            Produto.ativo == True,  # noqa: E712
            Produto.id != produto_id,
            Produto.tipo.in_([TipoProduto.RAW, TipoProduto.MANUFACTURED]),
        )
        .order_by(Produto.nome)
        .all()
    )

    unidades = db.session.query(Unidade).order_by(Unidade.nome).all()

    return render_template(
        "restaurante/receitas/detail.html",
        produto=produto,
        itens=itens,
        custo_total=custo_total,
        ingredientes_disponiveis=ingredientes_disponiveis,
        unidades=unidades,
    )


@bp.route("/receitas/<int:produto_id>/item", methods=["POST"])
@login_required
@require_permission("cmv", "gerenciar_receitas")
def adicionar_item_receita(produto_id: int):
    """Adiciona um ingrediente à ficha técnica de um produto.

    Path params:
        produto_id (int): PK do produto pai (fabricado).

    Form fields:
        produto_filho_id (int): PK do ingrediente — obrigatório.
        quantidade_liquida (float): Quantidade no prato final — obrigatório.
        fator_rendimento (float): Fator de cocção — padrão 1.0.
        unidade_id (int): Unidade de medida — opcional.
        observacoes (str): Notas — opcional.

    Returns:
        Redirect para restaurante.detalhe_receita com flash de sucesso ou erro.
    """
    ator_id = _get_ator_id()

    try:
        item = ReceitaService.criar_item(
            produto_pai_id=produto_id,
            produto_filho_id=int(request.form["produto_filho_id"]),
            quantidade_liquida=float(request.form["quantidade_liquida"]),
            fator_rendimento=float(request.form.get("fator_rendimento") or 1.0),
            unidade_id=int(request.form["unidade_id"]) if request.form.get("unidade_id") else None,
            observacoes=request.form.get("observacoes") or None,
            ator_id=ator_id,
        )
    except (ValueError, KeyError) as exc:
        flash(str(exc), "erro")
        return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_id))

    flash(
        f"Ingrediente adicionado: {item.produto_filho.nome} — "
        f"{item.quantidade_bruta:.3f} (bruto).",
        "sucesso",
    )
    return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_id))


@bp.route("/receitas/item/<int:receita_id>/editar", methods=["POST"])
@login_required
@require_permission("cmv", "gerenciar_receitas")
def editar_item_receita(receita_id: int):
    """Atualiza quantidade ou fator de rendimento de um item da ficha técnica.

    Path params:
        receita_id (int): PK do item de receita.

    Form fields:
        produto_pai_id (int): PK do produto pai — para redirecionar após salvar.
        quantidade_liquida (float): Nova quantidade líquida — opcional.
        fator_rendimento (float): Novo fator de rendimento — opcional.
        unidade_id (int): Nova unidade — opcional.
        observacoes (str): Novas notas — opcional.

    Returns:
        Redirect para restaurante.detalhe_receita com flash de sucesso ou erro.
    """
    ator_id = _get_ator_id()
    produto_pai_id = int(request.form.get("produto_pai_id", 0))

    dados = {}
    if request.form.get("quantidade_liquida"):
        dados["quantidade_liquida"] = float(request.form["quantidade_liquida"])
    if request.form.get("fator_rendimento"):
        dados["fator_rendimento"] = float(request.form["fator_rendimento"])
    if request.form.get("unidade_id"):
        dados["unidade_id"] = int(request.form["unidade_id"])
    if "observacoes" in request.form:
        dados["observacoes"] = request.form["observacoes"] or None

    try:
        ReceitaService.atualizar_item(receita_id, dados, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_pai_id))

    flash("Item de receita atualizado.", "sucesso")
    return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_pai_id))


@bp.route("/receitas/item/<int:receita_id>/remover", methods=["POST"])
@login_required
@require_permission("cmv", "gerenciar_receitas")
def remover_item_receita(receita_id: int):
    """Remove um ingrediente da ficha técnica.

    Path params:
        receita_id (int): PK do item a remover.

    Form fields:
        produto_pai_id (int): PK do produto pai — para redirecionar após remoção.

    Returns:
        Redirect para restaurante.detalhe_receita com flash de confirmação ou erro.
    """
    ator_id = _get_ator_id()
    produto_pai_id = int(request.form.get("produto_pai_id", 0))

    try:
        ReceitaService.remover_item(receita_id, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_pai_id))

    flash("Ingrediente removido da ficha técnica.", "aviso")
    return redirect(url_for("restaurante.detalhe_receita", produto_id=produto_pai_id))
