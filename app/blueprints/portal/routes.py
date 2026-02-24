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
from app.extensions import db
from app.models.funcionario import Funcionario, validar_cpf

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
    Employee dashboard.

    Returns the authenticated employee's basic data and current
    Banco de Horas balance.  The balance is a stub (0) until the
    BancoDeHoras module is fully ported.
    """
    funcionario = _get_funcionario_sessao()
    return render_template("portal/dashboard.html", funcionario=funcionario)


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
