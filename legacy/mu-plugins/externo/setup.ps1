# Script de Setup Rápido - Ambiente Python
# Execute este script após reiniciar o PowerShell

Write-Host "====================================" -ForegroundColor Cyan
Write-Host "  SETUP PYTHON - Sistema de Ponto" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""

# Passo 1: Verificar Python
Write-Host "[1/4] Verificando instalação do Python..." -ForegroundColor Yellow
try {
    $pythonVersion = python --version 2>&1
    Write-Host "✅ $pythonVersion encontrado!" -ForegroundColor Green
} catch {
    Write-Host "❌ Python não encontrado. Reinicie o PowerShell e tente novamente." -ForegroundColor Red
    exit 1
}

# Passo 2: Criar ambiente virtual
Write-Host ""
Write-Host "[2/4] Criando ambiente virtual (venv)..." -ForegroundColor Yellow
if (Test-Path "venv") {
    Write-Host "⚠️  venv já existe, pulando..." -ForegroundColor Yellow
} else {
    python -m venv venv
    Write-Host "✅ Ambiente virtual criado!" -ForegroundColor Green
}

# Passo 3: Ativar ambiente virtual
Write-Host ""
Write-Host "[3/4] Ativando ambiente virtual..." -ForegroundColor Yellow
& .\venv\Scripts\Activate.ps1
Write-Host "✅ Ambiente ativado!" -ForegroundColor Green

# Passo 4: Instalar dependências
Write-Host ""
Write-Host "[4/4] Instalando dependências (opencv-python, pyzbar, requests)..." -ForegroundColor Yellow
pip install -r requirements.txt
Write-Host "✅ Dependências instaladas!" -ForegroundColor Green

Write-Host ""
Write-Host "====================================" -ForegroundColor Cyan
Write-Host "  ✅ SETUP CONCLUÍDO COM SUCESSO!" -ForegroundColor Green
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Próximos passos:" -ForegroundColor Cyan
Write-Host "  1. Testar câmeras:  python camteste.py" -ForegroundColor White
Write-Host "  2. Rodar sistema:   python ponto.py" -ForegroundColor White
Write-Host ""
Write-Host "Para desativar o venv: deactivate" -ForegroundColor Gray
