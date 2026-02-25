# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Testes de integração para as rotas do blueprint RH.

Cada teste usa o test client do Flask para simular requisições HTTP
completas contra o banco SQLite in-memory.

Cenários cobertos
-----------------
- Sem sessão → redirect 302 para login
- Sem permissão `funcionarios.view` → 403
- GET /rh/funcionarios com permissão → 200
- POST /rh/funcionarios/novo com dados válidos → 302 + funcionário no banco + 1 AuditLog
- POST /rh/funcionarios/novo com CPF duplicado → 400 + flash erro
- POST /rh/funcionarios/<id>/editar → 302 + dados atualizados
- POST /rh/funcionarios/<id>/desativar → 302 + ativo=False + 1 AuditLog
"""

from __future__ import annotations

import pytest

from app.core.audit import AuditAction
from app.core.models import AuditLog
from app.extensions import db as _db
from app.models.funcionario import Funcionario
from app.models.role import Role, RolePermission


# ---------------------------------------------------------------------------
# Helpers de fixtures
# ---------------------------------------------------------------------------

def _criar_role_super_admin() -> Role:
    """Cria e persiste um Role super_admin no banco."""
    role = Role(nome="super_admin", descricao="Acesso total", is_super_admin=True, ativo=True)
    _db.session.add(role)
    _db.session.flush()
    return role


def _criar_role_rh() -> Role:
    """
    Cria e persiste um Role 'rh' com todas as permissões do módulo funcionarios.

    Returns:
        Instância do Role com permissões de view, create, edit e desativar.
    """
    role = Role(nome="rh", descricao="Acesso ao módulo RH", is_super_admin=False, ativo=True)
    _db.session.add(role)
    _db.session.flush()

    for acao in ("view", "create", "edit", "desativar"):
        _db.session.add(RolePermission(role_id=role.id, modulo="funcionarios", acao=acao))

    _db.session.flush()
    return role


def _criar_funcionario(nome: str, cpf: str, role: Role | None = None) -> Funcionario:
    """
    Cria e persiste um Funcionario ativo no banco.

    Args:
        nome:  Nome do funcionário.
        cpf:   CPF limpo (11 dígitos).
        role:  Role a atribuir (opcional).

    Returns:
        Instância persistida do Funcionario.
    """
    f = Funcionario(nome=nome, cpf=cpf, ativo=True)
    if role:
        f.role_id = role.id
    _db.session.add(f)
    _db.session.commit()
    return f


# ---------------------------------------------------------------------------
# Fixtures de clientes autenticados
# ---------------------------------------------------------------------------

@pytest.fixture
def client_sem_sessao(client):
    """Cliente sem sessão ativa."""
    return client


@pytest.fixture
def client_sem_permissao(app, client, db):
    """
    Cliente autenticado como funcionário sem nenhuma permissão de RH.

    O funcionário tem o role 'funcionario' (apenas dashboard.view).
    """
    with app.app_context():
        role = Role(nome="funcionario", descricao="Básico", is_super_admin=False, ativo=True)
        _db.session.add(role)
        _db.session.flush()
        _db.session.add(RolePermission(role_id=role.id, modulo="dashboard", acao="view"))
        _db.session.flush()
        f = _criar_funcionario("Sem Permissão", "07699220030", role)

    with client.session_transaction() as sess:
        sess["funcionario_id"] = f.id
    return client


@pytest.fixture
def client_rh(app, client, db):
    """
    Cliente autenticado como funcionário com role super_admin.

    Garante acesso irrestrito a todas as rotas RH.
    """
    with app.app_context():
        role = _criar_role_super_admin()
        f = _criar_funcionario("Admin RH", "52998224725", role)

    with client.session_transaction() as sess:
        sess["funcionario_id"] = f.id
    return client


# ---------------------------------------------------------------------------
# Listagem
# ---------------------------------------------------------------------------

class TestListarFuncionarios:

    def test_sem_sessao_redireciona_para_login(self, client_sem_sessao):
        """Acesso sem sessão deve redirecionar para a página de login."""
        resp = client_sem_sessao.get("/rh/funcionarios")
        assert resp.status_code == 302
        assert "/portal/login" in resp.headers["Location"]

    def test_sem_permissao_retorna_403(self, client_sem_permissao):
        """Funcionário sem permissão funcionarios.view deve receber 403."""
        resp = client_sem_permissao.get("/rh/funcionarios")
        assert resp.status_code == 403

    def test_com_permissao_retorna_200(self, app, client_rh, db):
        """Funcionário com role super_admin deve ver a lista (HTTP 200)."""
        resp = client_rh.get("/rh/funcionarios")
        assert resp.status_code == 200
        assert "Funcionários" in resp.data.decode()

    def test_filtro_por_nome(self, app, client_rh, db):
        """
        O filtro ?q= deve retornar apenas funcionários que correspondem ao termo.
        """
        with app.app_context():
            _criar_funcionario("Alice Oliveira", "07699220030")
            _criar_funcionario("Bob Santos", "27113507070")

        resp = client_rh.get("/rh/funcionarios?q=Alice")
        body = resp.data.decode()

        assert "Alice Oliveira" in body
        assert "Bob Santos" not in body


# ---------------------------------------------------------------------------
# Criar
# ---------------------------------------------------------------------------

class TestNovoFuncionario:

    def test_get_exibe_formulario(self, client_rh):
        """GET deve retornar o formulário em branco (HTTP 200)."""
        resp = client_rh.get("/rh/funcionarios/novo")
        assert resp.status_code == 200
        assert "Novo Funcionário" in resp.data.decode()

    def test_post_valido_cria_funcionario(self, app, client_rh, db):
        """
        POST com dados válidos deve criar o funcionário, registrar um AuditLog
        e redirecionar para a lista (302).
        """
        resp = client_rh.post("/rh/funcionarios/novo", data={
            "nome": "Carla Lima",
            "cpf": "27113507070",
            "cargo": "Atendente",
            "minutos_esperados_dia": "480",
            "minutos_almoco": "60",
        })

        assert resp.status_code == 302
        assert "/rh/funcionarios" in resp.headers["Location"]

        with app.app_context():
            f = _db.session.query(Funcionario).filter_by(cpf="27113507070").first()
            assert f is not None
            assert f.nome == "Carla Lima"
            assert f.ativo is True

            log_count = _db.session.query(AuditLog).filter_by(
                action=AuditAction.create,
                entity_id=f.id,
            ).count()
            assert log_count == 1

    def test_post_cpf_duplicado_retorna_400(self, app, client_rh, db):
        """POST com CPF já cadastrado deve retornar 400 e exibir flash de erro."""
        with app.app_context():
            _criar_funcionario("Original", "27113507070")

        resp = client_rh.post("/rh/funcionarios/novo", data={
            "nome": "Duplicado",
            "cpf": "27113507070",
        })

        assert resp.status_code == 400
        assert "CPF" in resp.data.decode() or "já" in resp.data.decode()

    def test_post_cpf_invalido_retorna_400(self, client_rh):
        """POST com CPF inválido deve retornar 400."""
        resp = client_rh.post("/rh/funcionarios/novo", data={
            "nome": "Teste",
            "cpf": "00000000000",
        })
        assert resp.status_code == 400

    def test_sem_sessao_redireciona(self, client_sem_sessao):
        """POST sem sessão deve redirecionar para login."""
        resp = client_sem_sessao.post("/rh/funcionarios/novo", data={
            "nome": "X",
            "cpf": "27113507070",
        })
        assert resp.status_code == 302
        assert "/portal/login" in resp.headers["Location"]


# ---------------------------------------------------------------------------
# Editar
# ---------------------------------------------------------------------------

class TestEditarFuncionario:

    def test_get_exibe_formulario_preenchido(self, app, client_rh, db):
        """GET deve retornar o formulário com os dados atuais do funcionário."""
        with app.app_context():
            f = _criar_funcionario("Daniel Souza", "07699220030")
            fid = f.id

        resp = client_rh.get(f"/rh/funcionarios/{fid}/editar")
        assert resp.status_code == 200
        assert "Daniel Souza" in resp.data.decode()

    def test_post_atualiza_dados(self, app, client_rh, db):
        """
        POST deve atualizar os campos do funcionário e redirecionar (302).
        """
        with app.app_context():
            f = _criar_funcionario("Eduardo Costa", "07699220030")
            fid = f.id

        resp = client_rh.post(f"/rh/funcionarios/{fid}/editar", data={
            "nome": "Eduardo Costa Jr.",
            "cargo": "Gerente",
            "minutos_esperados_dia": "480",
            "minutos_almoco": "60",
        })

        assert resp.status_code == 302

        with app.app_context():
            f = _db.session.get(Funcionario, fid)
            assert f.nome == "Eduardo Costa Jr."
            assert f.cargo == "Gerente"

    def test_funcionario_inexistente_redireciona(self, client_rh):
        """Editar ID inexistente deve redirecionar com flash de erro."""
        resp = client_rh.get("/rh/funcionarios/9999/editar")
        assert resp.status_code == 302

    def test_sem_permissao_retorna_403(self, client_sem_permissao):
        """Funcionário sem permissão de edição deve receber 403."""
        resp = client_sem_permissao.post("/rh/funcionarios/1/editar", data={"nome": "X"})
        assert resp.status_code == 403


# ---------------------------------------------------------------------------
# Desativar
# ---------------------------------------------------------------------------

class TestDesativarFuncionario:

    def test_post_desativa_funcionario(self, app, client_rh, db):
        """
        POST deve desativar o funcionário (ativo=False), criar 1 AuditLog
        e redirecionar para a lista (302).
        """
        with app.app_context():
            f = _criar_funcionario("Fernanda Nunes", "07699220030")
            fid = f.id

        resp = client_rh.post(f"/rh/funcionarios/{fid}/desativar")

        assert resp.status_code == 302
        assert "/rh/funcionarios" in resp.headers["Location"]

        with app.app_context():
            f = _db.session.get(Funcionario, fid)
            assert f.ativo is False

            log_count = _db.session.query(AuditLog).filter_by(
                action=AuditAction.delete,
                entity_id=fid,
            ).count()
            assert log_count == 1

    def test_sem_permissao_retorna_403(self, client_sem_permissao):
        """Funcionário sem permissão desativar deve receber 403."""
        resp = client_sem_permissao.post("/rh/funcionarios/1/desativar")
        assert resp.status_code == 403

    def test_sem_sessao_redireciona(self, client_sem_sessao):
        """POST sem sessão deve redirecionar para login."""
        resp = client_sem_sessao.post("/rh/funcionarios/1/desativar")
        assert resp.status_code == 302
        assert "/portal/login" in resp.headers["Location"]
