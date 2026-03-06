import pytest
from datetime import datetime, date, time, timezone
from app.models.ajuste_ponto import AjustePontoRequest, StatusAjuste
from app.models.ponto import TimeEntry
from app.models.avisos import Aviso, TipoAviso
from app.models.funcionario import Funcionario
from app.models.role import Role, RolePermission
from app.services.ajuste_ponto_service import AjustePontoService
from app.extensions import db

@pytest.fixture
def supervisor_id(app):
    """Cria um supervisor com permissão de ajuste e retorna o ID."""
    with app.app_context():
        role = Role(nome="Supervisor", is_super_admin=False)
        db.session.add(role)
        db.session.flush()
        
        perm = RolePermission(role_id=role.id, modulo="ajuste_ponto", acao="aprovar")
        db.session.add(perm)
        
        sup = Funcionario(
            nome="Super Visor",
            cpf="111.111.111-11",
            email="supervisor@teste.com",
            role_id=role.id,
            ativo=True
        )
        db.session.add(sup)
        db.session.commit()
        return sup.id

@pytest.fixture
def funcionario_comum_id(app):
    """Cria um funcionário comum e retorna o ID."""
    with app.app_context():
        role = Role(nome="Comum", is_super_admin=False)
        db.session.add(role)
        db.session.flush()
        
        func = Funcionario(
            nome="Jose Comum",
            cpf="222.222.222-22",
            email="jose@teste.com",
            role_id=role.id,
            ativo=True
        )
        db.session.add(func)
        db.session.commit()
        return func.id

def test_criar_solicitacao(app, db, funcionario_comum_id, supervisor_id):
    """Testa se a criação da solicitação de ajuste funciona e cria um aviso associado."""
    with app.app_context():
        # Cria a solicitação
        solicitacao = AjustePontoService.criar_solicitacao(
            funcionario_id=funcionario_comum_id,
            data_solicitada=date(2023, 10, 25),
            horario_solicitado=datetime(2023, 10, 25, 8, 0, tzinfo=timezone.utc),
            motivo="Esqueci de bater o ponto na chegada",
            tipo_ponto='clock_in',
        )

        assert solicitacao.id is not None
        assert solicitacao.funcionario_id == funcionario_comum_id
        assert solicitacao.status == StatusAjuste.PENDENTE
        assert solicitacao.motivo == "Esqueci de bater o ponto na chegada"

        # Verifica se o aviso foi criado para os supervisores (ou no caso, quem tem permissão)
        avisos = Aviso.query.filter_by(tipo=TipoAviso.AJUSTE_PONTO).all()
        assert len(avisos) > 0
        assert avisos[0].ajuste_ponto_id == solicitacao.id


def test_aprovar_ajuste(app, db, funcionario_comum_id, supervisor_id):
    """Testa aprovar uma solicitação, que deve criar/atualizar um TimeEntry."""
    with app.app_context():
        # Arranjo
        solicitacao = AjustePontoService.criar_solicitacao(
            funcionario_id=funcionario_comum_id,
            data_solicitada=date(2023, 10, 25),
            horario_solicitado=datetime(2023, 10, 25, 8, 15, tzinfo=timezone.utc),
            motivo="Esqueci de bater o ponto",
            tipo_ponto='clock_in',
        )
        
        # Ação
        result = AjustePontoService.aprovar(
            ajuste_id=solicitacao.id,
            supervisor_id=supervisor_id,
        )
        
        # Assert
        assert result.status == StatusAjuste.APROVADO
        assert result.supervisor_id == supervisor_id
        assert result.resolvido_em is not None
        
        # Verifica no banco a batida
        entry = TimeEntry.query.filter_by(funcionario_id=funcionario_comum_id).order_by(TimeEntry.id.desc()).first()
        assert entry is not None
        assert entry.funcionario_id == funcionario_comum_id
        assert entry.source.name == 'admin'


def test_rejeitar_ajuste(app, db, funcionario_comum_id, supervisor_id):
    """Testa rejeitar um ajuste."""
    with app.app_context():
        # Arranjo
        solicitacao = AjustePontoService.criar_solicitacao(
            funcionario_id=funcionario_comum_id,
            data_solicitada=date(2023, 10, 27),
            horario_solicitado=datetime(2023, 10, 27, 18, 0, tzinfo=timezone.utc),
            motivo="Saída justificativa sem prova",
            tipo_ponto='clock_out',
        )
        
        # Ação
        result = AjustePontoService.rejeitar(
            ajuste_id=solicitacao.id,
            supervisor_id=supervisor_id,
            motivo_rejeicao="Não comprovado",
        )
        
        # Assert
        assert result.status == StatusAjuste.REJEITADO
        assert result.motivo_rejeicao == "Não comprovado"
        assert result.supervisor_id == supervisor_id

