#!/bin/bash

echo "=== SISTUR: Sincronizar dependências do Composer ==="
echo ""

# Cores para output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Diretório atual (repositório Git)
REPO_DIR="/home/user/sistur2"

# Possíveis locais do WordPress
WP_PATHS=(
    "/var/www/html/wordpress/wp-content/plugins/sistur"
    "/var/www/html/wp-content/plugins/sistur"
    "/usr/share/nginx/html/wordpress/wp-content/plugins/sistur"
    "$HOME/public_html/wordpress/wp-content/plugins/sistur"
    "$HOME/public_html/wp-content/plugins/sistur"
    "/opt/lampp/htdocs/wordpress/wp-content/plugins/sistur"
    "/Applications/MAMP/htdocs/wordpress/wp-content/plugins/sistur"
)

# Verificar se vendor existe no repositório
if [ ! -d "$REPO_DIR/vendor" ]; then
    echo -e "${RED}✗ Pasta vendor/ não encontrada em $REPO_DIR${NC}"
    echo "Execute primeiro: cd $REPO_DIR && composer install --no-dev"
    exit 1
fi

echo -e "${GREEN}✓ Pasta vendor/ encontrada no repositório${NC}"
echo ""

# Procurar WordPress
WP_PLUGIN_DIR=""
for path in "${WP_PATHS[@]}"; do
    if [ -f "$path/sistur.php" ]; then
        WP_PLUGIN_DIR="$path"
        echo -e "${GREEN}✓ Plugin SISTUR encontrado em: $path${NC}"
        break
    fi
done

# Se não encontrou nos caminhos comuns, buscar
if [ -z "$WP_PLUGIN_DIR" ]; then
    echo -e "${YELLOW}⚠ Plugin não encontrado nos caminhos comuns${NC}"
    echo "Procurando WordPress no sistema..."

    # Buscar sistur.php
    FOUND=$(find /var/www /opt /home /usr/share -name "sistur.php" -path "*/wp-content/plugins/sistur/*" 2>/dev/null | head -1)

    if [ -n "$FOUND" ]; then
        WP_PLUGIN_DIR=$(dirname "$FOUND")
        echo -e "${GREEN}✓ Plugin encontrado em: $WP_PLUGIN_DIR${NC}"
    else
        echo -e "${RED}✗ Não foi possível localizar o plugin no WordPress${NC}"
        echo ""
        echo "Por favor, especifique o caminho manualmente:"
        echo "  bash $0 /caminho/para/wordpress/wp-content/plugins/sistur"
        exit 1
    fi
fi

# Permitir override por argumento
if [ -n "$1" ]; then
    if [ -f "$1/sistur.php" ]; then
        WP_PLUGIN_DIR="$1"
        echo -e "${YELLOW}⚠ Usando caminho fornecido: $WP_PLUGIN_DIR${NC}"
    else
        echo -e "${RED}✗ Caminho inválido: $1${NC}"
        exit 1
    fi
fi

echo ""
echo "=== Sincronizando vendor/ ==="
echo "De: $REPO_DIR/vendor/"
echo "Para: $WP_PLUGIN_DIR/vendor/"
echo ""

# Criar backup se já existir
if [ -d "$WP_PLUGIN_DIR/vendor" ]; then
    BACKUP="$WP_PLUGIN_DIR/vendor.backup.$(date +%Y%m%d_%H%M%S)"
    echo -e "${YELLOW}⚠ vendor/ já existe, criando backup: $BACKUP${NC}"
    mv "$WP_PLUGIN_DIR/vendor" "$BACKUP"
fi

# Copiar vendor/
echo "Copiando arquivos..."
cp -r "$REPO_DIR/vendor" "$WP_PLUGIN_DIR/"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ vendor/ copiado com sucesso!${NC}"

    # Verificar se endroid existe
    if [ -d "$WP_PLUGIN_DIR/vendor/endroid" ]; then
        echo -e "${GREEN}✓ Biblioteca endroid/qr-code verificada${NC}"
    else
        echo -e "${RED}✗ Biblioteca endroid/qr-code não encontrada!${NC}"
    fi

    # Copiar também composer.json e composer.lock
    cp "$REPO_DIR/composer.json" "$WP_PLUGIN_DIR/"
    if [ -f "$REPO_DIR/composer.lock" ]; then
        cp "$REPO_DIR/composer.lock" "$WP_PLUGIN_DIR/"
    fi

    echo ""
    echo -e "${GREEN}=== CONCLUÍDO ===${NC}"
    echo "Agora teste no WordPress:"
    echo "1. Acesse: RH > QR Codes dos Funcionários"
    echo "2. Clique em 'Gerar Todos os QR Codes'"
    echo "3. Os QR codes devem ser gerados com sucesso"
else
    echo -e "${RED}✗ Erro ao copiar vendor/${NC}"
    exit 1
fi
