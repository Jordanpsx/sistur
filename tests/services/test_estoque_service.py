# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Unit tests para EstoqueService e ReceitaService.

Test isolation: cada teste recebe um schema SQLite in-memory isolado
via a fixture autouse `db` do conftest.py.  Nenhum dado de produção
é lido ou gravado.

Cobertura:
    ProdutoService.criar()       — happy path, nome vazio, SKU duplicado
    ProdutoService.atualizar()   — happy path, produto não encontrado
    ProdutoService.desativar()   — happy path, produto não encontrado
    CategoriaProdutoService.criar() — happy path, nome duplicado, AuditLog
    EstoqueService.registrar_entrada() — cria lote, cria transação,
                                         recalcula custo_medio, AuditLog
    ReceitaService.criar_item()  — happy path, ingrediente duplicado
    ReceitaService.calcular_custo_receita() — cálculo com yield factor
    AuditLog: toda mutação deve gerar exatamente um AuditLog
"""

from __future__ import annotations

from decimal import Decimal

import pytest

from app.core.models import AuditAction, AuditLog
from app.models.estoque import (
    LoteEstoque,
    Produto,
    StatusLote,
    TipoProduto,
    TipoTransacao,
    TransacaoEstoque,
    Unidade,
    TipoUnidade,
    LocalArmazenamento,
    TipoLocal,
    CategoriaProduto,
)
from app.services.estoque_service import (
    CategoriaProdutoService,
    EstoqueService,
    LocalArmazenamentoService,
    ProdutoService,
)
from app.services.receita_service import ReceitaService


# ---------------------------------------------------------------------------
# Helpers de fixture
# ---------------------------------------------------------------------------

def _criar_unidade(db, nome="Kilograma", simbolo="kg") -> Unidade:
    """Cria uma Unidade diretamente no banco (sem service — é dado de suporte)."""
    un = Unidade(nome=nome, simbolo=simbolo, tipo=TipoUnidade.dimensional, is_sistema=True)
    db.session.add(un)
    db.session.commit()
    return un


def _criar_local(db, nome="Almoxarifado") -> LocalArmazenamento:
    """Cria um LocalArmazenamento diretamente no banco."""
    local = LocalArmazenamento(nome=nome, tipo=TipoLocal.almoxarifado, ativo=True)
    db.session.add(local)
    db.session.commit()
    return local


def _count_audit(db, action: AuditAction, entity_id: int | None = None) -> int:
    """Conta linhas de AuditLog filtrando por ação e entity_id opcional."""
    q = db.session.query(AuditLog).filter_by(action=action)
    if entity_id is not None:
        q = q.filter_by(entity_id=entity_id)
    return q.count()


# ---------------------------------------------------------------------------
# ProdutoService.criar()
# ---------------------------------------------------------------------------

class TestProdutoServiceCriar:

    def test_cria_produto_com_sucesso(self, app, db):
        """Cria produto com dados válidos e verifica campos persistidos."""
        with app.app_context():
            p = ProdutoService.criar(
                nome="Arroz Parboilizado",
                tipo=TipoProduto.RAW,
                ator_id=None,
            )
            assert p.id is not None
            assert p.nome == "Arroz Parboilizado"
            assert p.tipo == TipoProduto.RAW
            assert p.ativo is True
            assert p.custo_medio == 0

    def test_criar_dispara_audit_create(self, app, db):
        """Criação de produto deve gerar exatamente um AuditLog de action=create."""
        with app.app_context():
            p = ProdutoService.criar(
                nome="Feijão Preto",
                tipo=TipoProduto.RAW,
                ator_id=1,
            )
            count = _count_audit(db, AuditAction.create, entity_id=p.id)
        assert count == 1

    def test_criar_nome_vazio_levanta_valueerror(self, app, db):
        """Nome vazio deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="nome"):
                ProdutoService.criar(nome="", tipo=TipoProduto.RESALE)

    def test_criar_tipo_invalido_levanta_valueerror(self, app, db):
        """Tipo de produto inexistente deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="Tipo de produto inválido"):
                ProdutoService.criar(nome="Produto X", tipo="INVALIDO")

    def test_criar_sku_duplicado_levanta_valueerror(self, app, db):
        """SKU já cadastrado em outro produto deve levantar ValueError."""
        with app.app_context():
            ProdutoService.criar(nome="Produto A", tipo=TipoProduto.RESALE, sku="SKU-001")
            with pytest.raises(ValueError, match="SKU"):
                ProdutoService.criar(nome="Produto B", tipo=TipoProduto.RESALE, sku="SKU-001")


# ---------------------------------------------------------------------------
# ProdutoService.atualizar()
# ---------------------------------------------------------------------------

class TestProdutoServiceAtualizar:

    def test_atualiza_produto_com_sucesso(self, app, db):
        """Atualização de nome e preço deve persistir corretamente."""
        with app.app_context():
            p = ProdutoService.criar(nome="Produto Original", tipo=TipoProduto.RESALE)
            p_atualizado = ProdutoService.atualizar(
                p.id,
                {"nome": "Produto Renomeado", "preco_venda": 9.99},
                ator_id=None,
            )
            assert p_atualizado.nome == "Produto Renomeado"
            assert float(p_atualizado.preco_venda) == pytest.approx(9.99, abs=0.0001)

    def test_atualizar_dispara_audit_update(self, app, db):
        """Atualização deve gerar exatamente um AuditLog de action=update."""
        with app.app_context():
            p = ProdutoService.criar(nome="Produto Audit", tipo=TipoProduto.RESALE)
            ProdutoService.atualizar(p.id, {"nome": "Novo Nome"}, ator_id=1)
            count = _count_audit(db, AuditAction.update, entity_id=p.id)
        assert count == 1

    def test_atualizar_produto_nao_encontrado(self, app, db):
        """Atualizar produto inexistente deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                ProdutoService.atualizar(9999, {"nome": "X"}, ator_id=None)


# ---------------------------------------------------------------------------
# ProdutoService.desativar()
# ---------------------------------------------------------------------------

class TestProdutoServiceDesativar:

    def test_desativa_produto_com_sucesso(self, app, db):
        """Desativar produto deve setar ativo=False."""
        with app.app_context():
            p = ProdutoService.criar(nome="Produto Ativo", tipo=TipoProduto.RESALE)
            p_desativado = ProdutoService.desativar(p.id, ator_id=None)
            assert p_desativado.ativo is False

    def test_desativar_dispara_audit_delete(self, app, db):
        """Desativação deve gerar exatamente um AuditLog de action=delete."""
        with app.app_context():
            p = ProdutoService.criar(nome="Para Desativar", tipo=TipoProduto.RESALE)
            ProdutoService.desativar(p.id, ator_id=1)
            count = _count_audit(db, AuditAction.delete, entity_id=p.id)
        assert count == 1

    def test_desativar_nao_encontrado(self, app, db):
        """Desativar produto inexistente deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                ProdutoService.desativar(9999, ator_id=None)


# ---------------------------------------------------------------------------
# CategoriaProdutoService.criar()
# ---------------------------------------------------------------------------

class TestCategoriaProdutoServiceCriar:

    def test_cria_categoria_com_sucesso(self, app, db):
        """Criar categoria com nome único deve retornar instância persistida."""
        with app.app_context():
            cat = CategoriaProdutoService.criar(nome="Laticínios", ator_id=None)
            assert cat.id is not None
            assert cat.nome == "Laticínios"

    def test_criar_categoria_dispara_audit(self, app, db):
        """Criação de categoria deve gerar exatamente um AuditLog de action=create."""
        with app.app_context():
            cat = CategoriaProdutoService.criar(nome="Bebidas", ator_id=1)
            count = _count_audit(db, AuditAction.create, entity_id=cat.id)
        assert count == 1

    def test_criar_categoria_nome_duplicado_levanta_valueerror(self, app, db):
        """Nome de categoria duplicado deve levantar ValueError."""
        with app.app_context():
            CategoriaProdutoService.criar(nome="Grãos", ator_id=None)
            with pytest.raises(ValueError, match="Grãos"):
                CategoriaProdutoService.criar(nome="Grãos", ator_id=None)

    def test_criar_categoria_nome_vazio_levanta_valueerror(self, app, db):
        """Nome vazio deve levantar ValueError."""
        with app.app_context():
            with pytest.raises(ValueError):
                CategoriaProdutoService.criar(nome="", ator_id=None)


# ---------------------------------------------------------------------------
# EstoqueService.registrar_entrada()
# ---------------------------------------------------------------------------

class TestEstoqueServiceRegistrarEntrada:

    def test_registrar_entrada_cria_lote(self, app, db):
        """Registrar entrada deve criar um LoteEstoque com status=ativo."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)
            produto = ProdutoService.criar(nome="Leite", tipo=TipoProduto.RAW)

            lote = EstoqueService.registrar_entrada(
                produto_id=produto.id,
                local_id=local.id,
                quantidade=10.0,
                unidade_id=un.id,
                custo_unitario=5.50,
                ator_id=None,
            )

            assert lote.id is not None
            assert lote.status == StatusLote.ativo
            assert float(lote.quantidade) == pytest.approx(10.0, abs=0.001)
            assert float(lote.custo_unitario) == pytest.approx(5.50, abs=0.0001)

    def test_registrar_entrada_cria_transacao(self, app, db):
        """Registrar entrada deve criar uma TransacaoEstoque do tipo IN."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)
            produto = ProdutoService.criar(nome="Manteiga", tipo=TipoProduto.RAW)

            lote = EstoqueService.registrar_entrada(
                produto_id=produto.id,
                local_id=local.id,
                quantidade=5.0,
                unidade_id=un.id,
                custo_unitario=20.0,
                ator_id=None,
            )

            transacoes = (
                db.session.query(TransacaoEstoque)
                .filter_by(lote_id=lote.id, tipo=TipoTransacao.IN)
                .all()
            )
            assert len(transacoes) == 1
            assert float(transacoes[0].quantidade) == pytest.approx(5.0, abs=0.001)

    def test_registrar_entrada_atualiza_custo_medio(self, app, db):
        """Após duas entradas com custos diferentes, custo_medio deve ser a média ponderada."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)
            produto = ProdutoService.criar(nome="Açúcar", tipo=TipoProduto.RAW)

            # Entrada 1: 10 kg a R$ 4.00 → valor = 40.00
            EstoqueService.registrar_entrada(
                produto_id=produto.id,
                local_id=local.id,
                quantidade=10.0,
                unidade_id=un.id,
                custo_unitario=4.0,
            )

            # Entrada 2: 10 kg a R$ 6.00 → valor = 60.00
            EstoqueService.registrar_entrada(
                produto_id=produto.id,
                local_id=local.id,
                quantidade=10.0,
                unidade_id=un.id,
                custo_unitario=6.0,
            )

            # custo_medio = (40 + 60) / (10 + 10) = 100 / 20 = 5.00
            produto_atualizado = db.session.get(Produto, produto.id)
            assert float(produto_atualizado.custo_medio) == pytest.approx(5.0, abs=0.0001)

    def test_registrar_entrada_dispara_audit(self, app, db):
        """Entrada de lote deve gerar exatamente um AuditLog adicional de action=create."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)
            produto = ProdutoService.criar(nome="Sal", tipo=TipoProduto.RAW)

            # Contagem antes da entrada (inclui o audit do produto)
            count_antes = db.session.query(AuditLog).filter_by(action=AuditAction.create).count()

            EstoqueService.registrar_entrada(
                produto_id=produto.id,
                local_id=local.id,
                quantidade=2.0,
                unidade_id=un.id,
                custo_unitario=1.5,
                ator_id=1,
            )

            count_depois = db.session.query(AuditLog).filter_by(action=AuditAction.create).count()

        # Exatamente um AuditLog novo deve ter sido criado para o lote
        assert count_depois - count_antes == 1

    def test_registrar_entrada_quantidade_zero_levanta_valueerror(self, app, db):
        """Quantidade zero ou negativa deve levantar ValueError."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)
            produto = ProdutoService.criar(nome="Óleo", tipo=TipoProduto.RAW)

            with pytest.raises(ValueError, match="maior que zero"):
                EstoqueService.registrar_entrada(
                    produto_id=produto.id,
                    local_id=local.id,
                    quantidade=0.0,
                    unidade_id=un.id,
                    custo_unitario=5.0,
                )

    def test_registrar_entrada_produto_inexistente_levanta_valueerror(self, app, db):
        """Produto não encontrado deve levantar ValueError."""
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)

            with pytest.raises(ValueError, match="não encontrado"):
                EstoqueService.registrar_entrada(
                    produto_id=9999,
                    local_id=local.id,
                    quantidade=1.0,
                    unidade_id=un.id,
                    custo_unitario=1.0,
                )


# ---------------------------------------------------------------------------
# ReceitaService.criar_item() e calcular_custo_receita()
# ---------------------------------------------------------------------------

class TestReceitaService:

    def test_criar_item_happy_path(self, app, db):
        """Adicionar ingrediente a uma ficha técnica deve persistir corretamente."""
        with app.app_context():
            pai = ProdutoService.criar(nome="Bolo de Chocolate", tipo=TipoProduto.MANUFACTURED)
            filho = ProdutoService.criar(nome="Farinha de Trigo", tipo=TipoProduto.RAW)

            item = ReceitaService.criar_item(
                produto_pai_id=pai.id,
                produto_filho_id=filho.id,
                quantidade_liquida=0.5,
                fator_rendimento=1.0,
                ator_id=None,
            )

            assert item.id is not None
            assert float(item.quantidade_liquida) == pytest.approx(0.5, abs=0.001)
            assert float(item.quantidade_bruta) == pytest.approx(0.5, abs=0.001)

    def test_criar_item_dispara_audit(self, app, db):
        """Criação de item de receita deve gerar exatamente um AuditLog adicional de action=create."""
        with app.app_context():
            pai = ProdutoService.criar(nome="Pizza", tipo=TipoProduto.MANUFACTURED)
            filho = ProdutoService.criar(nome="Queijo", tipo=TipoProduto.RAW)

            # Contagem antes de criar o item (inclui audits dos dois produtos)
            count_antes = db.session.query(AuditLog).filter_by(action=AuditAction.create).count()

            ReceitaService.criar_item(
                produto_pai_id=pai.id,
                produto_filho_id=filho.id,
                quantidade_liquida=0.3,
                ator_id=1,
            )

            count_depois = db.session.query(AuditLog).filter_by(action=AuditAction.create).count()

        # Exatamente um AuditLog novo deve ter sido criado para o item de receita
        assert count_depois - count_antes == 1

    def test_criar_item_ingrediente_duplicado_levanta_valueerror(self, app, db):
        """Adicionar o mesmo ingrediente duas vezes deve levantar ValueError."""
        with app.app_context():
            pai = ProdutoService.criar(nome="Pão", tipo=TipoProduto.MANUFACTURED)
            filho = ProdutoService.criar(nome="Farinha", tipo=TipoProduto.RAW)

            ReceitaService.criar_item(
                produto_pai_id=pai.id,
                produto_filho_id=filho.id,
                quantidade_liquida=0.5,
            )
            with pytest.raises(ValueError, match="já consta"):
                ReceitaService.criar_item(
                    produto_pai_id=pai.id,
                    produto_filho_id=filho.id,
                    quantidade_liquida=0.2,
                )

    def test_calcular_custo_receita_com_yield_factor(self, app, db):
        """Custo da ficha técnica deve usar quantidade_bruta × custo_medio do ingrediente.

        Cenário:
            Ingrediente: Arroz, custo_medio = R$ 5.00/kg
            Ficha: 0.25 kg líquidos (pós-cocção), fator_rendimento = 2.5
            quantidade_bruta = 0.25 / 2.5 = 0.10 kg
            custo_ingrediente = 0.10 × 5.00 = R$ 0.50
        """
        with app.app_context():
            un = _criar_unidade(db)
            local = _criar_local(db)

            pai = ProdutoService.criar(nome="Arroz Cozido (porção)", tipo=TipoProduto.MANUFACTURED)
            arroz = ProdutoService.criar(nome="Arroz Seco", tipo=TipoProduto.RAW)

            # Registrar entrada para definir custo_medio = R$ 5.00
            EstoqueService.registrar_entrada(
                produto_id=arroz.id,
                local_id=local.id,
                quantidade=1.0,
                unidade_id=un.id,
                custo_unitario=5.0,
            )

            ReceitaService.criar_item(
                produto_pai_id=pai.id,
                produto_filho_id=arroz.id,
                quantidade_liquida=0.25,
                fator_rendimento=2.5,
            )

            custo = ReceitaService.calcular_custo_receita(pai.id)
        # 0.10 × 5.00 = 0.50
        assert custo == pytest.approx(0.50, abs=0.0001)

    def test_quantidade_liquida_zero_levanta_valueerror(self, app, db):
        """Quantidade líquida zero deve levantar ValueError."""
        with app.app_context():
            pai = ProdutoService.criar(nome="Produto Pai", tipo=TipoProduto.MANUFACTURED)
            filho = ProdutoService.criar(nome="Ingrediente", tipo=TipoProduto.RAW)

            with pytest.raises(ValueError, match="maior que zero"):
                ReceitaService.criar_item(
                    produto_pai_id=pai.id,
                    produto_filho_id=filho.id,
                    quantidade_liquida=0.0,
                )

    def test_fator_rendimento_zero_levanta_valueerror(self, app, db):
        """Fator de rendimento zero deve levantar ValueError."""
        with app.app_context():
            pai = ProdutoService.criar(nome="Produto X", tipo=TipoProduto.MANUFACTURED)
            filho = ProdutoService.criar(nome="Ingrediente Y", tipo=TipoProduto.RAW)

            with pytest.raises(ValueError, match="maior que zero"):
                ReceitaService.criar_item(
                    produto_pai_id=pai.id,
                    produto_filho_id=filho.id,
                    quantidade_liquida=1.0,
                    fator_rendimento=0.0,
                )
