# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Estoque Blueprint — Gestão de Inventário.

Rotas
-----
GET  /estoque/                          → dashboard do módulo
GET  /estoque/produtos                  → listagem de produtos
GET  /estoque/produtos/novo             → formulário de cadastro
POST /estoque/produtos/novo             → salva novo produto
GET  /estoque/produtos/<id>/editar      → formulário de edição
POST /estoque/produtos/<id>/editar      → salva edição
POST /estoque/produtos/<id>/desativar   → desativa produto (soft-delete)
GET  /estoque/categorias                → listagem de categorias
POST /estoque/categorias/nova           → cria categoria
GET  /estoque/locais                    → listagem de locais de armazenamento
POST /estoque/locais/novo               → cria local
GET  /estoque/view                      → visão de estoque por local
POST /estoque/entrada                   → registra entrada de lote

Todas as rotas exigem sessão ativa (@login_required) e a permissão
correspondente (@require_permission).
"""

from __future__ import annotations

from flask import Blueprint, abort, flash, redirect, render_template, request, session, url_for

from app.blueprints.portal.routes import login_required
from app.core.permissions import require_permission
from app.extensions import db
from app.models.estoque import (
    CategoriaProduto,
    LocalArmazenamento,
    TipoLocal,
    TipoProduto,
    Unidade,
)
from app.models.funcionario import Funcionario
from app.services.configuracao_service import ConfiguracaoService
from app.services.estoque_service import (
    CategoriaProdutoService,
    EstoqueService,
    LocalArmazenamentoService,
    ProdutoService,
)

bp = Blueprint("estoque", __name__)


# ---------------------------------------------------------------------------
# Master Switch — bloqueia o módulo se desabilitado nas configurações globais
# ---------------------------------------------------------------------------

@bp.before_request
def verificar_modulo_ativo():
    """Retorna 403 se o módulo Estoque estiver desabilitado nas configurações globais.

    Super admins (role.is_super_admin=True) sempre têm acesso, independentemente
    do estado do master switch, para evitar lockout acidental.
    """
    if not ConfiguracaoService.is_module_enabled("estoque"):
        fid = session.get("funcionario_id")
        if fid:
            f = db.session.get(Funcionario, fid)
            if f and f.role and f.role.is_super_admin:
                return  # Super admin sempre tem acesso
        abort(403)


# ---------------------------------------------------------------------------
# Helpers internos
# ---------------------------------------------------------------------------

def _get_ator_id() -> int:
    """Retorna o ID do funcionário logado a partir da sessão Flask."""
    return session["funcionario_id"]


def _selects_produto() -> tuple:
    """Retorna os dados necessários para popular os selects do formulário de produto.

    Returns:
        Tupla (categorias, unidades, tipos_produto, locais) para uso nos templates.
    """
    categorias = CategoriaProdutoService.listar()
    unidades = db.session.query(Unidade).order_by(Unidade.nome).all()
    tipos = [(t.value, t.name) for t in TipoProduto]
    return categorias, unidades, tipos


# ---------------------------------------------------------------------------
# Dashboard
# ---------------------------------------------------------------------------

@bp.route("/")
@login_required
@require_permission("estoque", "view")
def dashboard():
    """Exibe o painel principal do módulo Estoque com resumo do inventário.

    Mostra contadores: total de produtos ativos, categorias, locais e
    lotes com estoque baixo (abaixo do estoque_minimo).

    Returns:
        Renderização de estoque/dashboard.html.
    """
    from app.models.estoque import LoteEstoque, Produto, StatusLote

    total_produtos = (
        db.session.query(Produto)
        .filter_by(ativo=True)
        .count()
    )
    total_categorias = db.session.query(CategoriaProduto).count()
    total_locais = (
        db.session.query(LocalArmazenamento)
        .filter_by(ativo=True)
        .count()
    )

    return render_template(
        "estoque/dashboard.html",
        total_produtos=total_produtos,
        total_categorias=total_categorias,
        total_locais=total_locais,
    )


# ---------------------------------------------------------------------------
# Produtos
# ---------------------------------------------------------------------------

@bp.route("/produtos")
@login_required
@require_permission("estoque", "view")
def listar_produtos():
    """Exibe a tabela de produtos com filtros opcionais por nome, SKU, tipo e categoria.

    Query params:
        q (str): Busca livre em nome e SKU.
        tipo (str): Filtro por TipoProduto (RAW, RESALE, MANUFACTURED, RESOURCE).
        categoria_id (int): Filtro por categoria.

    Returns:
        Renderização de estoque/produtos/list.html.
    """
    q = (request.args.get("q") or "").strip()
    tipo = request.args.get("tipo") or None
    categoria_id = request.args.get("categoria_id", type=int)

    try:
        produtos = ProdutoService.listar(
            apenas_ativos=True,
            tipo=tipo,
            categoria_id=categoria_id,
            q=q or None,
        )
    except ValueError:
        produtos = []

    categorias = CategoriaProdutoService.listar()
    tipos = list(TipoProduto)

    return render_template(
        "estoque/produtos/list.html",
        produtos=produtos,
        categorias=categorias,
        tipos=tipos,
        q=q,
        tipo_selecionado=tipo,
        categoria_selecionada=categoria_id,
    )


@bp.route("/produtos/novo", methods=["GET", "POST"])
@login_required
@require_permission("estoque", "create")
def novo_produto():
    """Exibe formulário de cadastro ou salva um novo produto.

    GET  → formulário em branco.
    POST → valida e persiste o produto, redireciona para a listagem.

    Form fields:
        nome (str): Nome do produto — obrigatório.
        tipo (str): TipoProduto — obrigatório.
        descricao (str): Descrição — opcional.
        sku (str): Código SKU único — opcional.
        codigo_barras (str): Código de barras — opcional.
        categoria_id (int): FK para CategoriaProduto — opcional.
        unidade_base_id (int): FK para Unidade — opcional.
        preco_venda (float): Preço de venda sugerido — opcional.
        estoque_minimo (float): Quantidade mínima desejada — padrão 0.
        is_perecivel (bool): Checkbox — 1 se marcado.

    Returns:
        GET:  Renderização de estoque/produtos/form.html.
        POST (sucesso): Redirect para estoque.listar_produtos.
        POST (erro):    Renderização do formulário com HTTP 400 e flash de erro.
    """
    categorias, unidades, tipos = _selects_produto()

    if request.method == "GET":
        return render_template(
            "estoque/produtos/form.html",
            produto=None,
            categorias=categorias,
            unidades=unidades,
            tipos=tipos,
        )

    ator_id = _get_ator_id()

    try:
        produto = ProdutoService.criar(
            nome=request.form.get("nome", ""),
            tipo=request.form.get("tipo", "RESALE"),
            descricao=request.form.get("descricao") or None,
            sku=request.form.get("sku") or None,
            codigo_barras=request.form.get("codigo_barras") or None,
            categoria_id=int(request.form["categoria_id"]) if request.form.get("categoria_id") else None,
            unidade_base_id=int(request.form["unidade_base_id"]) if request.form.get("unidade_base_id") else None,
            preco_venda=float(request.form["preco_venda"]) if request.form.get("preco_venda") else None,
            estoque_minimo=float(request.form.get("estoque_minimo") or 0),
            is_perecivel=bool(request.form.get("is_perecivel")),
            ator_id=ator_id,
        )
    except (ValueError, KeyError) as exc:
        flash(str(exc), "erro")
        return render_template(
            "estoque/produtos/form.html",
            produto=None,
            categorias=categorias,
            unidades=unidades,
            tipos=tipos,
        ), 400

    flash(f"Produto '{produto.nome}' cadastrado com sucesso.", "sucesso")
    return redirect(url_for("estoque.listar_produtos"))


@bp.route("/produtos/<int:produto_id>/editar", methods=["GET", "POST"])
@login_required
@require_permission("estoque", "edit")
def editar_produto(produto_id: int):
    """Exibe formulário de edição pré-preenchido ou salva as alterações de um produto.

    Path params:
        produto_id (int): PK do produto a editar.

    Returns:
        GET:  Renderização de estoque/produtos/form.html com dados preenchidos.
        POST (sucesso): Redirect para estoque.listar_produtos.
        POST (erro):    Renderização do formulário com HTTP 400 e flash de erro.
    """
    try:
        produto = ProdutoService.buscar_por_id(produto_id)
    except ValueError:
        flash("Produto não encontrado.", "erro")
        return redirect(url_for("estoque.listar_produtos"))

    categorias, unidades, tipos = _selects_produto()

    if request.method == "GET":
        return render_template(
            "estoque/produtos/form.html",
            produto=produto,
            categorias=categorias,
            unidades=unidades,
            tipos=tipos,
        )

    ator_id = _get_ator_id()

    dados = {}
    for campo in ("nome", "descricao", "sku", "codigo_barras"):
        val = request.form.get(campo)
        dados[campo] = val or None

    if request.form.get("categoria_id"):
        dados["categoria_id"] = int(request.form["categoria_id"])
    else:
        dados["categoria_id"] = None

    if request.form.get("unidade_base_id"):
        dados["unidade_base_id"] = int(request.form["unidade_base_id"])
    else:
        dados["unidade_base_id"] = None

    if request.form.get("preco_venda"):
        dados["preco_venda"] = float(request.form["preco_venda"])

    dados["estoque_minimo"] = float(request.form.get("estoque_minimo") or 0)
    dados["is_perecivel"] = bool(request.form.get("is_perecivel"))

    try:
        produto = ProdutoService.atualizar(produto_id, dados, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return render_template(
            "estoque/produtos/form.html",
            produto=produto,
            categorias=categorias,
            unidades=unidades,
            tipos=tipos,
        ), 400

    flash(f"Produto '{produto.nome}' atualizado com sucesso.", "sucesso")
    return redirect(url_for("estoque.listar_produtos"))


@bp.route("/produtos/<int:produto_id>/desativar", methods=["POST"])
@login_required
@require_permission("estoque", "delete")
def desativar_produto(produto_id: int):
    """Desativa um produto (soft-delete: ativo=False).

    Path params:
        produto_id (int): PK do produto a desativar.

    Returns:
        Redirect para estoque.listar_produtos com flash de confirmação ou erro.
    """
    ator_id = _get_ator_id()

    try:
        produto = ProdutoService.desativar(produto_id, ator_id)
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("estoque.listar_produtos"))

    flash(f"Produto '{produto.nome}' desativado.", "aviso")
    return redirect(url_for("estoque.listar_produtos"))


# ---------------------------------------------------------------------------
# Categorias
# ---------------------------------------------------------------------------

@bp.route("/categorias")
@login_required
@require_permission("estoque", "view")
def listar_categorias():
    """Exibe a listagem de categorias de produto com formulário inline de criação.

    Returns:
        Renderização de estoque/categorias/list.html.
    """
    categorias = CategoriaProdutoService.listar()
    return render_template("estoque/categorias/list.html", categorias=categorias)


@bp.route("/categorias/nova", methods=["POST"])
@login_required
@require_permission("estoque", "create")
def nova_categoria():
    """Cria uma nova categoria de produto a partir do formulário inline.

    Form fields:
        nome (str): Nome único da categoria — obrigatório.
        descricao (str): Descrição — opcional.

    Returns:
        Redirect para estoque.listar_categorias com flash de sucesso ou erro.
    """
    ator_id = _get_ator_id()

    try:
        cat = CategoriaProdutoService.criar(
            nome=request.form.get("nome", ""),
            descricao=request.form.get("descricao") or None,
            ator_id=ator_id,
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("estoque.listar_categorias"))

    flash(f"Categoria '{cat.nome}' criada com sucesso.", "sucesso")
    return redirect(url_for("estoque.listar_categorias"))


# ---------------------------------------------------------------------------
# Locais de Armazenamento
# ---------------------------------------------------------------------------

@bp.route("/locais")
@login_required
@require_permission("estoque", "view")
def listar_locais():
    """Exibe a listagem de locais de armazenamento com formulário inline de criação.

    Returns:
        Renderização de estoque/locais/list.html.
    """
    locais = LocalArmazenamentoService.listar(apenas_ativos=False)
    tipos = list(TipoLocal)
    return render_template("estoque/locais/list.html", locais=locais, tipos=tipos)


@bp.route("/locais/novo", methods=["POST"])
@login_required
@require_permission("estoque", "create")
def novo_local():
    """Cria um novo local de armazenamento a partir do formulário inline.

    Form fields:
        nome (str): Nome do local — obrigatório.
        tipo (str): TipoLocal — obrigatório.
        codigo (str): Código alfanumérico curto — opcional.
        descricao (str): Descrição — opcional.
        parent_id (int): Local pai para hierarquia — opcional.

    Returns:
        Redirect para estoque.listar_locais com flash de sucesso ou erro.
    """
    ator_id = _get_ator_id()

    try:
        local = LocalArmazenamentoService.criar(
            nome=request.form.get("nome", ""),
            tipo=request.form.get("tipo", "almoxarifado"),
            codigo=request.form.get("codigo") or None,
            descricao=request.form.get("descricao") or None,
            parent_id=int(request.form["parent_id"]) if request.form.get("parent_id") else None,
            ator_id=ator_id,
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("estoque.listar_locais"))

    flash(f"Local '{local.nome}' criado com sucesso.", "sucesso")
    return redirect(url_for("estoque.listar_locais"))


# ---------------------------------------------------------------------------
# Visão de Estoque
# ---------------------------------------------------------------------------

@bp.route("/view")
@login_required
@require_permission("estoque", "view")
def view_estoque():
    """Exibe o estoque atual agrupado por local de armazenamento.

    Query params:
        local_id (int): Filtro por local específico.
        produto_id (int): Filtro por produto específico.

    Returns:
        Renderização de estoque/view.html com lotes agrupados.
    """
    local_id = request.args.get("local_id", type=int)
    produto_id = request.args.get("produto_id", type=int)

    locais = LocalArmazenamentoService.listar()

    lotes = []
    if local_id:
        try:
            lotes = EstoqueService.consultar_estoque_por_local(local_id)
        except ValueError as exc:
            flash(str(exc), "erro")
    elif produto_id:
        try:
            lotes = EstoqueService.consultar_estoque_por_produto(produto_id)
        except ValueError as exc:
            flash(str(exc), "erro")

    return render_template(
        "estoque/view.html",
        lotes=lotes,
        locais=locais,
        local_id_selecionado=local_id,
        produto_id_selecionado=produto_id,
    )


# ---------------------------------------------------------------------------
# Entrada de Estoque
# ---------------------------------------------------------------------------

@bp.route("/entrada", methods=["GET", "POST"])
@login_required
@require_permission("estoque", "registrar_entrada")
def registrar_entrada():
    """Exibe o formulário de entrada de lote ou registra a entrada no estoque.

    GET  → formulário de entrada com seleção de produto e local.
    POST → valida e registra o LoteEstoque, redireciona para view de estoque.

    Form fields (POST):
        produto_id (int): PK do produto — obrigatório.
        local_id (int): PK do local de destino — obrigatório.
        quantidade (float): Quantidade do lote — obrigatório, > 0.
        unidade_id (int): Unidade de medida — obrigatório.
        custo_unitario (float): Custo unitário deste lote — obrigatório.
        codigo_lote (str): Código do fornecedor — opcional.
        local_aquisicao (str): Local de compra — opcional.
        data_validade (date): Data de validade — opcional.
        fornecedor_cnpj (str): CNPJ do fornecedor — opcional.
        num_nfe (str): Número da NF-e — opcional.
        observacoes (str): Observações livres — opcional.

    Returns:
        GET:  Renderização de estoque/entrada/form.html.
        POST (sucesso): Redirect para estoque.view_estoque com flash de sucesso.
        POST (erro):    Renderização do formulário com HTTP 400 e flash de erro.
    """
    produtos = ProdutoService.listar(apenas_ativos=True)
    locais = LocalArmazenamentoService.listar()
    unidades = db.session.query(Unidade).order_by(Unidade.nome).all()

    if request.method == "GET":
        return render_template(
            "estoque/entrada/form.html",
            produtos=produtos,
            locais=locais,
            unidades=unidades,
        )

    ator_id = _get_ator_id()

    try:
        lote = EstoqueService.registrar_entrada(
            produto_id=int(request.form["produto_id"]),
            local_id=int(request.form["local_id"]),
            quantidade=float(request.form["quantidade"]),
            unidade_id=int(request.form["unidade_id"]),
            custo_unitario=float(request.form["custo_unitario"]),
            ator_id=ator_id,
            codigo_lote=request.form.get("codigo_lote") or None,
            local_aquisicao=request.form.get("local_aquisicao") or None,
            data_validade=request.form.get("data_validade") or None,
            fornecedor_cnpj=request.form.get("fornecedor_cnpj") or None,
            num_nfe=request.form.get("num_nfe") or None,
            observacoes=request.form.get("observacoes") or None,
        )
    except (ValueError, KeyError) as exc:
        flash(str(exc), "erro")
        return render_template(
            "estoque/entrada/form.html",
            produtos=produtos,
            locais=locais,
            unidades=unidades,
        ), 400

    flash(
        f"Entrada registrada: lote {lote.codigo_lote} — {lote.quantidade} unid.",
        "sucesso",
    )
    return redirect(url_for("estoque.view_estoque", local_id=lote.local_id))
