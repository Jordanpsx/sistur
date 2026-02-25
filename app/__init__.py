# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

from flask import Flask, redirect, url_for
from flask_migrate import Migrate

from app.config import config
from app.extensions import db

migrate = Migrate()


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
    migrate.init_app(app, db)

    # Branding context processor — makes {{ company_name }} and {{ company_logo }}
    # available in every Jinja2 template without manual passing.
    @app.context_processor
    def inject_branding():
        return {
            "company_name": app.config["COMPANY_NAME"],
            "company_logo": app.config["COMPANY_LOGO"],
        }

    # Root route — serve the login page at /
    @app.route("/")
    def index():
        return redirect(url_for("portal.login"))

    # Import models so SQLAlchemy registers all tables before create_all()
    with app.app_context():
        from app.core import models  # noqa: F401
        from app.models import funcionario  # noqa: F401
        from app.models import role  # noqa: F401
        from app.models import ponto  # noqa: F401
        from app.models import estoque  # noqa: F401

    # Blueprints
    from app.blueprints.portal.routes import bp as portal_bp
    app.register_blueprint(portal_bp, url_prefix="/portal")

    from app.blueprints.rh.routes import bp as rh_bp
    app.register_blueprint(rh_bp, url_prefix="/rh")

    from app.blueprints.ponto.routes import bp as ponto_bp
    app.register_blueprint(ponto_bp, url_prefix="/ponto")

    from app.blueprints.estoque.routes import bp as estoque_bp
    app.register_blueprint(estoque_bp, url_prefix="/estoque")

    from app.blueprints.restaurante.routes import bp as restaurante_bp
    app.register_blueprint(restaurante_bp, url_prefix="/restaurante")

    from app.blueprints.admin.routes import bp as admin_bp
    app.register_blueprint(admin_bp, url_prefix="/admin")

    # CLI commands
    from app.cli import register_commands
    register_commands(app)

    return app
