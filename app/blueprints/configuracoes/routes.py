# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Configurações — Painel de Controle Global (super_admin exclusivo).

Rotas
-----
GET  /admin/configuracoes/                        → painel principal (módulos, branding)
POST /admin/configuracoes/modulos/<chave>/toggle  → liga/desliga módulo (JSON)
POST /admin/configuracoes/branding                → salva identidade visual
GET  /admin/configuracoes/roles                   → lista de roles (RBAC)
POST /admin/configuracoes/roles/criar             → cria novo role
GET  /admin/configuracoes/roles/<id>/permissoes   → editor de permissões do role
POST /admin/configuracoes/roles/<id>/permissoes   → salva permissões (bulk)
POST /admin/configuracoes/roles/<id>/desativar    → desativa role

Controle de Acesso
------------------
Exclusivo para funcionários com Role.is_super_admin=True.
"""

from __future__ import annotations

import functools

from flask import (
    Blueprint,
    abort,
    flash,
    jsonify,
    redirect,
    render_template,
    request,
    session,
    url_for,
)

from app.extensions import db
from app.models.funcionario import Funcionario
from app.models.role import Role
from app.services.configuracao_service import (
    CHAVE_BRANDING_EMPRESA_LOGO,
    CHAVE_BRANDING_EMPRESA_NOME,
    CHAVE_EMPRESA_RAZAO_SOCIAL,
    CHAVE_EMPRESA_CNPJ,
    CHAVE_EMPRESA_ENDERECO,
    ConfiguracaoService,
)
from app.models.calendario import TipoEvento
from app.models.geofence import GeofenceLocation
from app.services.role_service import RoleService

bp = Blueprint("configuracoes", __name__)

# ---------------------------------------------------------------------------
# Mapa de todas as permissões conhecidas do sistema
# Usado para renderizar o grid de toggles na UI de edição de roles
# ---------------------------------------------------------------------------

PERMISSOES_DO_SISTEMA: dict[str, list[str]] = {
    "dashboard":    ["view"],
    "funcionarios": ["view", "create", "edit", "desativar"],
    "ponto":        ["view", "create", "edit_admin"],
    "estoque":      ["view", "create", "edit", "delete"],
    "restaurante":  ["view", "create", "edit", "delete"],
    "audit":        ["view", "view_all"],
    "leads":        ["view", "create", "edit"],
    "folha_ponto":  ["view", "edit", "deducao", "imprimir"],
    # avisos: view = pode ver as próprias notificações; receber_alertas = recebe alertas de atraso/falta
    "avisos":       ["view", "receber_alertas"],
}

# Nomes legíveis para exibição nas permissões
_LABELS_ACAO: dict[str, str] = {
    "view":       "Visualizar",
    "create":     "Criar",
    "edit":       "Editar",
    "delete":     "Excluir",
    "desativar":  "Desativar",
    "view_all":   "Visualizar Tudo",
    "edit_admin": "Editar (Admin)",
    "deducao":    "Registrar Dedução",
    "imprimir":   "Imprimir / Exportar PDF",
}

_LABELS_MODULO: dict[str, str] = {
    "dashboard":    "Dashboard",
    "funcionarios": "Funcionários",
    "ponto":        "Ponto Eletrônico",
    "estoque":      "Estoque",
    "restaurante":  "Restaurante",
    "audit":        "Auditoria",
    "leads":        "Leads",
    "folha_ponto":  "Folha de Ponto",
}


# ---------------------------------------------------------------------------
# Auth helpers
# ---------------------------------------------------------------------------

def _get_ator_sessao() -> Funcionario:
    """Carrega o Funcionario autenticado na sessão ou aborta.

    Returns:
        Instância de Funcionario ativa correspondente à sessão atual.

    Raises:
        HTTP 401: Se não houver sessão ativa ou o funcionário estiver inativo.
    """
    fid = session.get("funcionario_id")
    if not fid:
        abort(401)
    f = db.session.get(Funcionario, fid)
    if not f or not f.ativo:
        session.clear()
        abort(401)
    return f


def _login_required(fn):
    """Redireciona para login se não houver sessão ativa."""
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        if not session.get("funcionario_id"):
            return redirect(url_for("portal.login"))
        return fn(*args, **kwargs)
    return wrapper


def _super_admin_required(fn):
    """Aborta com 403 se o funcionário não for super_admin.

    Deve ser usado após @_login_required para garantir que a sessão existe.
    """
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        ator = _get_ator_sessao()
        if not ator.role or not ator.role.is_super_admin:
            abort(403)
        return fn(*args, **kwargs)
    return wrapper


def _get_ator_id() -> int:
    """Retorna o ID do funcionário em sessão.

    Returns:
        ID do funcionário autenticado.
    """
    return session["funcionario_id"]


# ---------------------------------------------------------------------------
# Painel principal
# ---------------------------------------------------------------------------

@bp.route("/", methods=["GET"])
@_login_required
@_super_admin_required
def index():
    """Exibe o painel de controle com módulos, regras globais e identidade visual.

    Returns:
        Renderização de admin/configuracoes/index.html com todas as
        configurações atuais e o estado de cada módulo.
    """
    todas = ConfiguracaoService.get_all()

    modulos_info = [
        {
            "chave":    f"modulo.{nome}",
            "nome":     nome,
            "label":    nome.capitalize(),
            "ativo":    ConfiguracaoService.is_module_enabled(nome),
        }
        for nome in ConfiguracaoService.MODULOS
    ]

    geofence_locations = (
        db.session.query(GeofenceLocation)
        .order_by(GeofenceLocation.name)
        .all()
    )

    from app.models.calendario import GlobalEvent
    eventos_calendario = (
        db.session.query(GlobalEvent)
        .order_by(GlobalEvent.data_evento.desc())
        .all()
    )

    return render_template(
        "admin/configuracoes/index.html",
        modulos_info=modulos_info,
        empresa_nome=todas.get(CHAVE_BRANDING_EMPRESA_NOME) or "",
        empresa_logo=todas.get(CHAVE_BRANDING_EMPRESA_LOGO) or "",
        empresa_razao_social=todas.get(CHAVE_EMPRESA_RAZAO_SOCIAL) or "",
        empresa_cnpj=todas.get(CHAVE_EMPRESA_CNPJ) or "",
        empresa_endereco=todas.get(CHAVE_EMPRESA_ENDERECO) or "",
        geofence_locations=geofence_locations,
        eventos_calendario=eventos_calendario,
    )


# ---------------------------------------------------------------------------
# Toggle de módulo (AJAX)
# ---------------------------------------------------------------------------

@bp.route("/modulos/<chave>/toggle", methods=["POST"])
@_login_required
@_super_admin_required
def toggle_modulo(chave: str):
    """Liga ou desliga um módulo pelo seu toggle switch.

    Recebe a chave no formato 'modulo.<nome>' e inverte o estado atual.
    Retorna JSON para que o frontend atualize a UI sem recarregar.

    Args:
        chave: Chave de configuração do módulo (e.g. 'modulo.ponto').

    Returns:
        JSON com o novo estado: {"ativo": bool, "chave": str}.

    Raises:
        HTTP 400: Se a chave não pertencer a um módulo válido.
        HTTP 403: Se o usuário não for super_admin.
    """
    modulos_validos = [f"modulo.{m}" for m in ConfiguracaoService.MODULOS]
    if chave not in modulos_validos:
        return jsonify({"erro": "Chave inválida."}), 400

    estado_atual = ConfiguracaoService.is_module_enabled(chave.replace("modulo.", ""))
    novo_estado = not estado_atual

    ConfiguracaoService.set(chave, novo_estado, ator_id=_get_ator_id())

    return jsonify({"ativo": novo_estado, "chave": chave})


# ---------------------------------------------------------------------------
# Branding
# ---------------------------------------------------------------------------

@bp.route("/branding", methods=["POST"])
@_login_required
@_super_admin_required
def salvar_branding():
    """Salva as configurações de identidade visual da empresa.

    Form fields:
        empresa_nome (str): Nome da empresa exibido no portal.
                            Vazio mantém a variável de ambiente.
        empresa_logo (str): URL do logotipo. Vazio usa a variável de ambiente.

    Returns:
        Redirect para o painel de configurações com mensagem de sucesso.
    """
    ator_id = _get_ator_id()
    nome = (request.form.get("empresa_nome") or "").strip() or None
    logo = (request.form.get("empresa_logo") or "").strip() or None

    ConfiguracaoService.set(CHAVE_BRANDING_EMPRESA_NOME, nome, ator_id=ator_id)
    ConfiguracaoService.set(CHAVE_BRANDING_EMPRESA_LOGO, logo, ator_id=ator_id)

    flash("Identidade visual salva com sucesso.", "sucesso")
    return redirect(url_for("configuracoes.index"))


# ---------------------------------------------------------------------------
# Dados da Empresa (para cabeçalho da Folha de Ponto)
# ---------------------------------------------------------------------------

@bp.route("/dados-empresa", methods=["POST"])
@_login_required
@_super_admin_required
def salvar_dados_empresa():
    """Salva CNPJ e endereço da empresa nos system settings.

    Esses dados são exibidos no cabeçalho do PDF da Folha de Ponto.

    Form fields:
        empresa_razao_social (str): Razão Social exibida no PDF da Folha.
        empresa_cnpj (str): CNPJ no formato XX.XXX.XXX/XXXX-XX. Pode ser vazio.
        empresa_endereco (str): Endereço completo. Pode ser vazio.

    Returns:
        Redirect para o painel de configurações com flash de confirmação.
    """
    ator_id = _get_ator_id()
    ConfiguracaoService.set(
        CHAVE_EMPRESA_RAZAO_SOCIAL,
        (request.form.get("empresa_razao_social") or "").strip() or None,
        ator_id=ator_id,
    )
    ConfiguracaoService.set(
        CHAVE_EMPRESA_CNPJ,
        (request.form.get("empresa_cnpj") or "").strip() or None,
        ator_id=ator_id,
    )
    ConfiguracaoService.set(
        CHAVE_EMPRESA_ENDERECO,
        (request.form.get("empresa_endereco") or "").strip() or None,
        ator_id=ator_id,
    )
    flash("Dados da empresa salvos com sucesso.", "sucesso")
    return redirect(url_for("configuracoes.index"))


# ---------------------------------------------------------------------------
# Roles — listagem
# ---------------------------------------------------------------------------

@bp.route("/roles", methods=["GET"])
@_login_required
@_super_admin_required
def listar_roles():
    """Lista todos os roles do sistema com contagem de permissões.

    Returns:
        Renderização de admin/configuracoes/roles.html com a lista
        de roles e flash messages de operações anteriores.
    """
    roles = RoleService.listar(apenas_ativos=False)
    return render_template("admin/configuracoes/roles.html", roles=roles)


# ---------------------------------------------------------------------------
# Roles — criação
# ---------------------------------------------------------------------------

@bp.route("/roles/criar", methods=["POST"])
@_login_required
@_super_admin_required
def criar_role():
    """Cria um novo role a partir do formulário inline na listagem.

    Form fields:
        nome (str):     Slug único do role (snake_case).
        descricao (str): Descrição legível.
        is_super_admin (checkbox): Presente se deve ser super_admin.

    Returns:
        Redirect para a listagem de roles com mensagem de resultado.
    """
    nome = (request.form.get("nome") or "").strip()
    descricao = (request.form.get("descricao") or "").strip() or None
    is_super_admin = request.form.get("is_super_admin") == "on"

    try:
        RoleService.criar(
            nome=nome,
            descricao=descricao,
            is_super_admin=is_super_admin,
            ator_id=_get_ator_id(),
        )
        flash(f"Role '{nome}' criado com sucesso.", "sucesso")
    except ValueError as exc:
        flash(str(exc), "erro")

    return redirect(url_for("configuracoes.listar_roles"))


# ---------------------------------------------------------------------------
# Roles — editor de permissões
# ---------------------------------------------------------------------------

@bp.route("/roles/<int:role_id>/permissoes", methods=["GET"])
@_login_required
@_super_admin_required
def editar_permissoes(role_id: int):
    """Exibe o editor de permissões de um role específico.

    Args:
        role_id: PK do role a editar.

    Returns:
        Renderização de admin/configuracoes/role_permissoes.html com
        o grid de toggles organizado por módulo.

    Raises:
        HTTP 404: Se o role não existir.
    """
    role = RoleService.buscar_por_id(role_id)
    if not role:
        abort(404)

    # Conjunto de permissões atuais do role
    perms_atuais = {(p.modulo, p.acao) for p in role.permissoes}

    return render_template(
        "admin/configuracoes/role_permissoes.html",
        role=role,
        permissoes_sistema=PERMISSOES_DO_SISTEMA,
        perms_atuais=perms_atuais,
        labels_modulo=_LABELS_MODULO,
        labels_acao=_LABELS_ACAO,
    )


@bp.route("/roles/<int:role_id>/permissoes", methods=["POST"])
@_login_required
@_super_admin_required
def salvar_permissoes(role_id: int):
    """Salva o conjunto completo de permissões de um role (bulk upsert/delete).

    Recebe os checkboxes marcados como 'perm_<modulo>_<acao>' e computa
    o diff em relação às permissões existentes, chamando add/remove
    do RoleService para cada diferença.

    Args:
        role_id: PK do role a editar.

    Returns:
        Redirect para o editor de permissões com mensagem de resultado.

    Raises:
        HTTP 404: Se o role não existir.
    """
    role = RoleService.buscar_por_id(role_id)
    if not role:
        abort(404)

    ator_id = _get_ator_id()

    # Permissões que devem estar ativas (vindas dos checkboxes do formulário)
    perms_desejadas: set[tuple[str, str]] = set()
    for modulo, acoes in PERMISSOES_DO_SISTEMA.items():
        for acao in acoes:
            campo = f"perm_{modulo}_{acao}"
            if request.form.get(campo) == "on":
                perms_desejadas.add((modulo, acao))

    # Permissões atualmente existentes no role
    perms_atuais: set[tuple[str, str]] = {
        (p.modulo, p.acao) for p in role.permissoes
    }

    # Adicionar as que estão faltando
    for modulo, acao in perms_desejadas - perms_atuais:
        try:
            RoleService.adicionar_permissao(role_id, modulo, acao, ator_id=ator_id)
        except ValueError:
            pass

    # Remover as que não deveriam mais existir
    for modulo, acao in perms_atuais - perms_desejadas:
        try:
            RoleService.remover_permissao(role_id, modulo, acao, ator_id=ator_id)
        except ValueError:
            pass

    flash(f"Permissões do role '{role.nome}' atualizadas.", "sucesso")
    return redirect(url_for("configuracoes.editar_permissoes", role_id=role_id))


# ---------------------------------------------------------------------------
# Roles — desativação
# ---------------------------------------------------------------------------

@bp.route("/roles/<int:role_id>/desativar", methods=["POST"])
@_login_required
@_super_admin_required
def desativar_role(role_id: int):
    """Desativa um role (soft-delete).

    Bloqueado para roles de super_admin para evitar lockout total.

    Args:
        role_id: PK do role a desativar.

    Returns:
        Redirect para a listagem de roles com mensagem de resultado.
    """
    try:
        role = RoleService.desativar(role_id, ator_id=_get_ator_id())
        flash(f"Role '{role.nome}' desativado.", "aviso")
    except ValueError as exc:
        flash(str(exc), "erro")

    return redirect(url_for("configuracoes.listar_roles"))


# ---------------------------------------------------------------------------
# Geofencing CRUD
# ---------------------------------------------------------------------------

_GEO_FIELDS = ["id", "name", "latitude", "longitude", "radius_meters", "is_active"]


@bp.route("/geofence/criar", methods=["POST"])
@_login_required
@_super_admin_required
def geofence_criar():
    """Cria uma nova zona de geofence e registra no log de auditoria.

    Form fields:
        name (str): Nome descritivo da zona.
        latitude (float): Latitude do centro da zona em graus decimais.
        longitude (float): Longitude do centro da zona em graus decimais.
        radius_meters (int): Raio da zona em metros.

    Returns:
        Redirect para configuracoes.index.
    """
    from app.core.audit import AuditService
    from app.services.base import BaseService

    try:
        name          = request.form["name"].strip()
        latitude      = float(request.form["latitude"])
        longitude     = float(request.form["longitude"])
        radius_meters = int(request.form["radius_meters"])
    except (KeyError, ValueError):
        flash("Dados inválidos. Verifique latitude, longitude e raio.", "erro")
        return redirect(url_for("configuracoes.index"))

    if not name:
        flash("O nome da zona é obrigatório.", "erro")
        return redirect(url_for("configuracoes.index"))

    zona = GeofenceLocation(
        name=name,
        latitude=latitude,
        longitude=longitude,
        radius_meters=radius_meters,
        is_active=True,
    )
    db.session.add(zona)
    db.session.flush()

    snapshot = BaseService._snapshot(zona, _GEO_FIELDS)
    db.session.commit()

    AuditService.log_create(
        module="geofence",
        entity_id=zona.id,
        new_state=snapshot,
        actor_id=_get_ator_id(),
    )

    flash(f"Zona '{zona.name}' criada com sucesso.", "sucesso")
    return redirect(url_for("configuracoes.index"))


@bp.route("/geofence/<int:loc_id>/editar", methods=["POST"])
@_login_required
@_super_admin_required
def geofence_editar(loc_id: int):
    """Atualiza os dados de uma zona de geofence existente.

    Args:
        loc_id: PK da GeofenceLocation a editar.

    Form fields:
        name (str): Novo nome da zona.
        latitude (float): Nova latitude.
        longitude (float): Nova longitude.
        radius_meters (int): Novo raio em metros.
        is_active (str): '1' para ativa, ausente para inativa.

    Returns:
        Redirect para configuracoes.index.
    """
    from app.core.audit import AuditService
    from app.services.base import BaseService

    zona = db.session.get(GeofenceLocation, loc_id)
    if not zona:
        flash("Zona não encontrada.", "erro")
        return redirect(url_for("configuracoes.index"))

    try:
        name          = request.form["name"].strip()
        latitude      = float(request.form["latitude"])
        longitude     = float(request.form["longitude"])
        radius_meters = int(request.form["radius_meters"])
        is_active     = request.form.get("is_active") == "1"
    except (KeyError, ValueError):
        flash("Dados inválidos. Verifique os campos do formulário.", "erro")
        return redirect(url_for("configuracoes.index"))

    if not name:
        flash("O nome da zona é obrigatório.", "erro")
        return redirect(url_for("configuracoes.index"))

    antes = BaseService._snapshot(zona, _GEO_FIELDS)

    zona.name          = name
    zona.latitude      = latitude
    zona.longitude     = longitude
    zona.radius_meters = radius_meters
    zona.is_active     = is_active
    db.session.commit()

    depois = BaseService._snapshot(zona, _GEO_FIELDS)

    AuditService.log_update(
        module="geofence",
        entity_id=zona.id,
        previous_state=antes,
        new_state=depois,
        actor_id=_get_ator_id(),
    )

    flash(f"Zona '{zona.name}' atualizada.", "sucesso")
    return redirect(url_for("configuracoes.index"))


@bp.route("/geofence/<int:loc_id>/deletar", methods=["POST"])
@_login_required
@_super_admin_required
def geofence_deletar(loc_id: int):
    """Remove permanentemente uma zona de geofence.

    Args:
        loc_id: PK da GeofenceLocation a remover.

    Returns:
        Redirect para configuracoes.index.
    """
    from app.core.audit import AuditService
    from app.services.base import BaseService

    zona = db.session.get(GeofenceLocation, loc_id)
    if not zona:
        flash("Zona não encontrada.", "erro")
        return redirect(url_for("configuracoes.index"))

    snapshot = BaseService._snapshot(zona, _GEO_FIELDS)
    nome = zona.name

    db.session.delete(zona)
    db.session.commit()

    AuditService.log_delete(
        module="geofence",
        entity_id=loc_id,
        previous_state=snapshot,
        actor_id=_get_ator_id(),
    )

    flash(f"Zona '{nome}' removida.", "aviso")
    return redirect(url_for("configuracoes.index"))


# ---------------------------------------------------------------------------
# Calendário Global — CRUD de eventos/feriados
# ---------------------------------------------------------------------------

_TIPO_EVENTO_MAP = {
    "NATIONAL_HOLIDAY": TipoEvento.NATIONAL_HOLIDAY,
    "LOCAL_HOLIDAY": TipoEvento.LOCAL_HOLIDAY,
    "MAINTENANCE": TipoEvento.MAINTENANCE,
    "HIGH_SEASON": TipoEvento.HIGH_SEASON,
}


@bp.route("/calendario/criar", methods=["POST"])
@_login_required
@_super_admin_required
def calendario_criar():
    """Cria um novo evento global no calendário do sistema.

    Form fields:
        titulo (str): Nome descritivo do evento.
        data_evento (str): Data no formato YYYY-MM-DD.
        tipo (str): Tipo do evento (NATIONAL_HOLIDAY, LOCAL_HOLIDAY, etc.).
        afeta_precificacao (str): '1' se afeta precificação.
        afeta_folha (str): '1' se afeta folha de pagamento.
        recorrente_anual (str): '1' se o evento é recorrente anual.

    Returns:
        Redirect para configuracoes.index.
    """
    from datetime import date as date_type

    from app.services.calendar_service import CalendarService

    try:
        titulo = request.form["titulo"].strip()
        data_str = request.form["data_evento"]
        data_evento = date_type.fromisoformat(data_str)
        tipo_str = request.form["tipo"]
        tipo = _TIPO_EVENTO_MAP.get(tipo_str)
        if not tipo:
            raise ValueError(f"Tipo inválido: {tipo_str}")
    except (KeyError, ValueError) as exc:
        flash(f"Dados inválidos: {exc}", "erro")
        return redirect(url_for("configuracoes.index"))

    afeta_precificacao = request.form.get("afeta_precificacao") == "1"
    afeta_folha = request.form.get("afeta_folha") == "1"
    recorrente_anual = request.form.get("recorrente_anual") == "1"

    try:
        CalendarService.criar(
            titulo=titulo,
            data_evento=data_evento,
            tipo=tipo,
            afeta_precificacao=afeta_precificacao,
            afeta_folha=afeta_folha,
            recorrente_anual=recorrente_anual,
            ator_id=_get_ator_id(),
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("configuracoes.index"))

    flash(f"Evento '{titulo}' criado com sucesso.", "sucesso")
    return redirect(url_for("configuracoes.index"))


@bp.route("/calendario/<int:event_id>/editar", methods=["POST"])
@_login_required
@_super_admin_required
def calendario_editar(event_id: int):
    """Atualiza um evento existente no calendário do sistema.

    Args:
        event_id: PK do GlobalEvent a editar.

    Form fields:
        titulo (str): Novo nome do evento.
        data_evento (str): Nova data no formato YYYY-MM-DD.
        tipo (str): Novo tipo do evento.
        afeta_precificacao (str): '1' se afeta precificação.
        afeta_folha (str): '1' se afeta folha de pagamento.
        recorrente_anual (str): '1' se o evento é recorrente anual.

    Returns:
        Redirect para configuracoes.index.
    """
    from datetime import date as date_type

    from app.services.calendar_service import CalendarService

    try:
        titulo = request.form["titulo"].strip()
        data_str = request.form["data_evento"]
        data_evento = date_type.fromisoformat(data_str)
        tipo_str = request.form["tipo"]
        tipo = _TIPO_EVENTO_MAP.get(tipo_str)
        if not tipo:
            raise ValueError(f"Tipo inválido: {tipo_str}")
    except (KeyError, ValueError) as exc:
        flash(f"Dados inválidos: {exc}", "erro")
        return redirect(url_for("configuracoes.index"))

    afeta_precificacao = request.form.get("afeta_precificacao") == "1"
    afeta_folha = request.form.get("afeta_folha") == "1"
    recorrente_anual = request.form.get("recorrente_anual") == "1"

    try:
        CalendarService.atualizar(
            event_id=event_id,
            titulo=titulo,
            data_evento=data_evento,
            tipo=tipo,
            afeta_precificacao=afeta_precificacao,
            afeta_folha=afeta_folha,
            recorrente_anual=recorrente_anual,
            ator_id=_get_ator_id(),
        )
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("configuracoes.index"))

    flash(f"Evento '{titulo}' atualizado.", "sucesso")
    return redirect(url_for("configuracoes.index"))


@bp.route("/calendario/<int:event_id>/deletar", methods=["POST"])
@_login_required
@_super_admin_required
def calendario_deletar(event_id: int):
    """Remove um evento do calendário do sistema.

    Args:
        event_id: PK do GlobalEvent a remover.

    Returns:
        Redirect para configuracoes.index.
    """
    from app.services.calendar_service import CalendarService

    try:
        CalendarService.deletar(event_id=event_id, ator_id=_get_ator_id())
    except ValueError as exc:
        flash(str(exc), "erro")
        return redirect(url_for("configuracoes.index"))

    flash("Evento removido.", "aviso")
    return redirect(url_for("configuracoes.index"))
