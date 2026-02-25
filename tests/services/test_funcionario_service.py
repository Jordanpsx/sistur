# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Unit tests for FuncionarioService.

Test isolation: every test receives a fresh in-memory SQLite schema
via the autouse `db` fixture in conftest.py.  No production data is
ever read or written.

Coverage targets:
    - criar(): happy path, duplicate CPF, invalid CPF
    - atualizar(): happy path, not found, field whitelist enforcement
    - desativar(): happy path, not found, already inactive
    - AuditLog: every mutation must produce exactly one AuditLog row
"""

import pytest

from app.core.models import AuditLog, AuditAction
from app.services.funcionario_service import FuncionarioService


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_CPF_VALIDO   = "52998224725"   # passes check-digit algorithm
_CPF_VALIDO_2 = "11144477735"   # second valid CPF for uniqueness tests
_CPF_INVALIDO = "11111111111"   # all same digits — structurally invalid


def _count_audit(db, action: AuditAction, entity_id: int | None = None) -> int:
    """Count AuditLog rows matching action and optional entity_id."""
    q = db.session.query(AuditLog).filter_by(action=action)
    if entity_id is not None:
        q = q.filter_by(entity_id=entity_id)
    return q.count()


# ---------------------------------------------------------------------------
# criar()
# ---------------------------------------------------------------------------

class TestCriar:

    def test_cria_funcionario_com_sucesso(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(
                nome="Maria Souza",
                cpf=_CPF_VALIDO,
                cargo="Recepcionista",
            )
            assert f.id is not None
            assert f.nome == "Maria Souza"
            assert f.cpf == _CPF_VALIDO
            assert f.cargo == "Recepcionista"
            assert f.ativo is True

    def test_cria_com_cpf_formatado(self, app, db):
        """CPF with punctuation must be accepted and stored clean."""
        with app.app_context():
            f = FuncionarioService.criar(nome="João Silva", cpf="529.982.247-25")
            assert f.cpf == _CPF_VALIDO

    def test_cria_dispara_audit_log(self, app, db):
        """Antigravity Rule #1 — criar() must produce one AuditLog(create)."""
        with app.app_context():
            f = FuncionarioService.criar(nome="Ana Lima", cpf=_CPF_VALIDO)
            count = _count_audit(db, AuditAction.create, entity_id=f.id)

        assert count == 1

    def test_cria_com_ator_registra_user_id_no_audit(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(
                nome="Carlos Ramos", cpf=_CPF_VALIDO, ator_id=99
            )
            log = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.create, entity_id=f.id)
                .first()
            )

        assert log.user_id == 99

    def test_cria_falha_cpf_duplicado(self, app, db):
        with app.app_context():
            FuncionarioService.criar(nome="Pedro Costa", cpf=_CPF_VALIDO)
            with pytest.raises(ValueError, match="já está cadastrado"):
                FuncionarioService.criar(nome="Outro Nome", cpf=_CPF_VALIDO)

    def test_cria_falha_cpf_invalido(self, app, db):
        with app.app_context():
            with pytest.raises(ValueError, match="CPF inválido"):
                FuncionarioService.criar(nome="Nome Qualquer", cpf=_CPF_INVALIDO)

    def test_cria_falha_nome_vazio(self, app, db):
        with app.app_context():
            with pytest.raises(ValueError, match="obrigatório"):
                FuncionarioService.criar(nome="", cpf=_CPF_VALIDO)

    def test_cria_remove_espacos_do_nome(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="  Luisa Fernanda  ", cpf=_CPF_VALIDO)
            assert f.nome == "Luisa Fernanda"

    def test_cria_defaults_jornada(self, app, db):
        """Default schedule must be 480 min work + 60 min lunch."""
        with app.app_context():
            f = FuncionarioService.criar(nome="Teste", cpf=_CPF_VALIDO)
            assert f.minutos_esperados_dia == 480
            assert f.minutos_almoco == 60


# ---------------------------------------------------------------------------
# atualizar()
# ---------------------------------------------------------------------------

class TestAtualizar:

    def _criar_funcionario(self, app, db):
        with app.app_context():
            return FuncionarioService.criar(nome="Original", cpf=_CPF_VALIDO)

    def test_atualiza_nome_e_cargo(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Original", cpf=_CPF_VALIDO)
            f = FuncionarioService.atualizar(
                f.id, {"nome": "Atualizado", "cargo": "Gerente"}
            )
            assert f.nome == "Atualizado"
            assert f.cargo == "Gerente"

    def test_atualiza_dispara_audit_log(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Original", cpf=_CPF_VALIDO)
            FuncionarioService.atualizar(f.id, {"cargo": "Novo Cargo"})
            count = _count_audit(db, AuditAction.update, entity_id=f.id)

        assert count == 1

    def test_audit_captura_estado_anterior(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Original", cpf=_CPF_VALIDO)
            FuncionarioService.atualizar(f.id, {"nome": "Novo Nome"})
            log = (
                db.session.query(AuditLog)
                .filter_by(action=AuditAction.update, entity_id=f.id)
                .first()
            )

        assert log.previous_state["nome"] == "Original"
        assert log.new_state["nome"] == "Novo Nome"

    def test_atualiza_ignora_campos_nao_permitidos(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Alvo", cpf=_CPF_VALIDO)
            original_cpf = f.cpf
            FuncionarioService.atualizar(f.id, {"cpf": _CPF_VALIDO_2, "ativo": False})
            f_atualizado = FuncionarioService.buscar_por_id(f.id)

        # cpf and ativo are not in the whitelist — must not change
        assert f_atualizado.cpf == original_cpf
        assert f_atualizado.ativo is True

    def test_atualiza_falha_nao_encontrado(self, app, db):
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                FuncionarioService.atualizar(9999, {"nome": "X"})


# ---------------------------------------------------------------------------
# desativar()
# ---------------------------------------------------------------------------

class TestDesativar:

    def test_desativa_funcionario(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Ativo", cpf=_CPF_VALIDO)
            f = FuncionarioService.desativar(f.id)
            assert f.ativo is False

    def test_desativa_dispara_audit_log(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Ativo", cpf=_CPF_VALIDO)
            FuncionarioService.desativar(f.id)
            count = _count_audit(db, AuditAction.update, entity_id=f.id)

        assert count == 1

    def test_desativa_falha_ja_inativo(self, app, db):
        with app.app_context():
            f = FuncionarioService.criar(nome="Ativo", cpf=_CPF_VALIDO)
            FuncionarioService.desativar(f.id)
            with pytest.raises(ValueError, match="já está inativo"):
                FuncionarioService.desativar(f.id)

    def test_desativa_falha_nao_encontrado(self, app, db):
        with app.app_context():
            with pytest.raises(ValueError, match="não encontrado"):
                FuncionarioService.desativar(9999)
