# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

from flask import Flask

from app.config import config
from app.extensions import db


def create_app(config_name: str = "default") -> Flask:
    """
    Flask application factory.

    Args:
        config_name: One of 'development', 'production', or 'default'.
    """
    app = Flask(__name__)
    app.config.from_object(config[config_name])

    # Extensions
    db.init_app(app)

    # Branding context processor — injects {{ company_name }} and {{ company_logo }}
    # into every Jinja2 template automatically, no manual passing required.
    @app.context_processor
    def inject_branding():
        return {
            "company_name": app.config["COMPANY_NAME"],
            "company_logo": app.config["COMPANY_LOGO"],
        }

    # Register all models with SQLAlchemy so create_all() picks them up
    with app.app_context():
        from app.core import models  # noqa: F401 — User, Area, UserAreaPermission, AuditLog
        from app.models import funcionario  # noqa: F401 — Funcionario

    # Blueprints
    from app.blueprints.portal.routes import bp as portal_bp
    app.register_blueprint(portal_bp, url_prefix="/portal")

    return app
