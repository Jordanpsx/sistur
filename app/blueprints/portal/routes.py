# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Portal do Colaborador — Employee Portal routes.

Routes
------
GET  /portal/login      → login form
POST /portal/login      → CPF authentication
GET  /portal/dashboard  → employee dashboard (Banco de Horas placeholder)
POST /portal/logout     → end session
"""

from __future__ import annotations

import functools

from flask import Blueprint, abort, flash, redirect, render_template, request, session, url_for

from app.core.audit import AuditService
from app.core.permissions import has_permission
from app.extensions import db
from app.models.funcionario import Funcionario, validar_cpf
from app.services.configuracao_service import ConfiguracaoService

bp = Blueprint("portal", __name__)


# ---------------------------------------------------------------------------
# Session guard
# ---------------------------------------------------------------------------

def login_required(fn):
    """Redirect to login if no active session."""
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        if not session.get("funcionario_id"):
            return redirect(url_for("portal.login"))
        return fn(*args, **kwargs)
    return wrapper


def _get_funcionario_sessao() -> Funcionario:
    """Load the authenticated Funcionario from session or abort 401."""
    funcionario_id = session.get("funcionario_id")
    if not funcionario_id:
        abort(401)
    funcionario = db.session.get(Funcionario, funcionario_id)
    if not funcionario or not funcionario.ativo:
        session.clear()
        abort(401)
    return funcionario


# ---------------------------------------------------------------------------
# Login
# ---------------------------------------------------------------------------

@bp.route("/login", methods=["GET", "POST"])
def login():
    """
    GET  → render login.html template
    POST → authenticate via CPF, create session, audit log
    """
    if request.method == "GET":
        # If already authenticated, go straight to dashboard
        if session.get("funcionario_id"):
            return redirect(url_for("portal.dashboard"))
        return render_template("auth/login.html")

    # --- POST: authenticate ---
    cpf_raw: str = (request.form.get("cpf") or "").strip()

    # 1. Validate CPF format and algorithm
    try:
        cpf_limpo = validar_cpf(cpf_raw)
    except ValueError as exc:
        # Audit failed attempt (no user identified → guest)
        AuditService.log_login(None, success=False)
        flash(str(exc), "erro")
        return render_template("auth/login.html"), 400

    # 2. Look up active employee
    funcionario = (
        db.session.query(Funcionario)
        .filter_by(cpf=cpf_limpo, ativo=True)
        .first()
    )

    if funcionario is None:
        # Audit failed attempt — CPF not found or inactive
        AuditService.log_login(None, success=False)
        flash("CPF não encontrado ou funcionário inativo.", "erro")
        return render_template("auth/login.html"), 401

    # 3. Create session
    session.clear()
    session["funcionario_id"] = funcionario.id
    session.permanent = True  # honour PERMANENT_SESSION_LIFETIME from config

    # 4. Audit — Antigravity Rule #1
    AuditService.log_login(funcionario.id, success=True)

    return redirect(url_for("portal.dashboard"))


# ---------------------------------------------------------------------------
# Dashboard
# ---------------------------------------------------------------------------

@bp.route("/dashboard", methods=["GET"])
@login_required
def dashboard():
    """
    Painel principal do colaborador.

    Passa ao template um dicionário 'perms' com as permissões do funcionário
    logado, permitindo que o template exiba ou oculte seções dinamicamente.
    Funcionários sem role atribuído recebem perms vazias (somente dashboard).

    Returns:
        Renderização de portal/dashboard.html com o funcionário autenticado
        e seu mapa de permissões por módulo.
    """
    funcionario = _get_funcionario_sessao()
    fid = funcionario.id

    perms = {
        "dashboard": {
            "view": has_permission(fid, "dashboard", "view"),
        },
        "funcionarios": {
            "view":      has_permission(fid, "funcionarios", "view"),
            "create":    has_permission(fid, "funcionarios", "create"),
            "edit":      has_permission(fid, "funcionarios", "edit"),
            "desativar": has_permission(fid, "funcionarios", "desativar"),
        },
        "ponto": {
            "view":   has_permission(fid, "ponto", "view"),
            "create": has_permission(fid, "ponto", "create"),
        },
        "estoque": {
            "view":    has_permission(fid, "estoque", "view"),
            "create":  has_permission(fid, "estoque", "create"),
            "edit":    has_permission(fid, "estoque", "edit"),
            "delete":  has_permission(fid, "estoque", "delete"),
        },
        "restaurante": {
            "view": has_permission(fid, "restaurante", "view"),
        },
        "audit": {
            "view":     has_permission(fid, "audit", "view"),
            "view_all": has_permission(fid, "audit", "view_all"),
        },
        "avisos": {
            "view":             has_permission(fid, "avisos", "view"),
            "receber_alertas":  has_permission(fid, "avisos", "receber_alertas"),
        },
        "reservas": {
            "view":   has_permission(fid, "reservas", "view"),
            "create": has_permission(fid, "reservas", "create"),
            "edit":   has_permission(fid, "reservas", "edit"),
            "delete": has_permission(fid, "reservas", "delete"),
        },
    }

    modulos_ativos = {
        "ponto":       ConfiguracaoService.is_module_enabled("ponto"),
        "estoque":     ConfiguracaoService.is_module_enabled("estoque"),
        "restaurante": ConfiguracaoService.is_module_enabled("restaurante"),
        "financeiro":  ConfiguracaoService.is_module_enabled("financeiro"),
        "reservas":    ConfiguracaoService.is_module_enabled("reservas"),
    }

    is_super_admin = bool(funcionario.role and funcionario.role.is_super_admin)

    return render_template(
        "portal/dashboard.html",
        funcionario=funcionario,
        perms=perms,
        modulos_ativos=modulos_ativos,
        is_super_admin=is_super_admin,
    )


# ---------------------------------------------------------------------------
# Logout
# ---------------------------------------------------------------------------

@bp.route("/logout", methods=["POST"])
def logout():
    """End the session and audit the logout event."""
    funcionario_id = session.get("funcionario_id")

    if funcionario_id:
        # Antigravity Rule #1 — log before clearing
        AuditService.log_logout(funcionario_id)

    session.clear()
    return redirect(url_for("portal.login"))
