# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

"""
Portal do Colaborador — Employee Portal routes.

GET  /portal/login      → login form (branded HTML)
POST /portal/login      → CPF authentication
GET  /portal/dashboard  → employee dashboard (Banco de Horas placeholder)
POST /portal/logout     → end session
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

from app.core.audit import AuditService
from app.extensions import db
from app.models.funcionario import Funcionario, validar_cpf

bp = Blueprint("portal", __name__)


# ---------------------------------------------------------------------------
# Session guard
# ---------------------------------------------------------------------------

def login_required(fn):
    @functools.wraps(fn)
    def wrapper(*args, **kwargs):
        if not session.get("funcionario_id"):
            return redirect(url_for("portal.login"))
        return fn(*args, **kwargs)
    return wrapper


def _get_funcionario_sessao() -> Funcionario:
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
    if request.method == "GET":
        if session.get("funcionario_id"):
            return redirect(url_for("portal.dashboard"))
        return render_template("auth/login.html")

    cpf_raw: str = (request.form.get("cpf") or "").strip()

    try:
        cpf_limpo = validar_cpf(cpf_raw)
    except ValueError as exc:
        AuditService.log_login(None, success=False)
        flash(str(exc), "erro")
        return render_template("auth/login.html"), 400

    funcionario = (
        db.session.query(Funcionario)
        .filter_by(cpf=cpf_limpo, ativo=True)
        .first()
    )

    if funcionario is None:
        AuditService.log_login(None, success=False)
        flash("CPF não encontrado ou funcionário inativo.", "erro")
        return render_template("auth/login.html"), 401

    session.clear()
    session["funcionario_id"] = funcionario.id
    session.permanent = True

    # Antigravity Rule #1
    AuditService.log_login(funcionario.id, success=True)

    return redirect(url_for("portal.dashboard"))


# ---------------------------------------------------------------------------
# Dashboard
# ---------------------------------------------------------------------------

@bp.route("/dashboard", methods=["GET"])
@login_required
def dashboard():
    funcionario = _get_funcionario_sessao()
    return jsonify(
        {
            "funcionario_id": funcionario.id,
            "nome": funcionario.nome,
            "cpf": funcionario.cpf_formatado(),
            "cargo": funcionario.cargo,
            "area": funcionario.area.name if funcionario.area else None,
            "banco_de_horas": {
                "saldo_minutos": funcionario.saldo_banco_horas(),
                "saldo_formatado": funcionario.saldo_banco_horas_formatado(),
            },
        }
    )


# ---------------------------------------------------------------------------
# Logout
# ---------------------------------------------------------------------------

@bp.route("/logout", methods=["POST"])
def logout():
    funcionario_id = session.get("funcionario_id")
    if funcionario_id:
        AuditService.log_logout(funcionario_id)
    session.clear()
    return redirect(url_for("portal.login"))
