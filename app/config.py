# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

import os


class Config:
    SECRET_KEY = os.environ.get("SECRET_KEY", "change-me-in-production")
    SQLALCHEMY_TRACK_MODIFICATIONS = False
    SESSION_COOKIE_HTTPONLY = True
    SESSION_COOKIE_SAMESITE = "Lax"
    PERMANENT_SESSION_LIFETIME = 60 * 60 * 8  # 8 hours — matches legacy behavior

    # White-label branding — set via environment variables
    COMPANY_NAME = os.environ.get("COMPANY_NAME", "SISTUR")
    COMPANY_LOGO = os.environ.get("COMPANY_LOGO", "")  # URL; empty = SVG fallback


class DevelopmentConfig(Config):
    DEBUG = True
    SQLALCHEMY_DATABASE_URI = os.environ.get(
        "DATABASE_URL",
        "mysql+mysqlconnector://root:root@localhost/sistur_dev",
    )


class ProductionConfig(Config):
    DEBUG = False
    SQLALCHEMY_DATABASE_URI = os.environ.get("DATABASE_URL")


config = {
    "development": DevelopmentConfig,
    "production": ProductionConfig,
    "default": DevelopmentConfig,
}
