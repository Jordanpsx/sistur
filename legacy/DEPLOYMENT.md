# 🚀 Guia de Deploy - SISTUR

## 📦 Instalação de Dependências

Este plugin utiliza a biblioteca **endroid/qr-code** para geração de QR codes. Para que o sistema funcione corretamente, você precisa instalar as dependências do Composer.

### Pré-requisitos

- PHP >= 7.2
- Composer instalado no servidor
- Extensão GD do PHP habilitada

### Instalação

#### 1. Identifique o diretório do plugin

O plugin mostrará um aviso vermelho na tela de QR Codes com o caminho exato. Caso não veja o aviso:

**Ambientes comuns:**
- XAMPP (Windows): `C:\xampp\htdocs\wordpress\wp-content\plugins\sistur`
- XAMPP (Linux): `/opt/lampp/htdocs/wordpress/wp-content/plugins/sistur`
- WAMP: `C:\wamp\www\wordpress\wp-content\plugins\sistur`
- MAMP: `/Applications/MAMP/htdocs/wordpress/wp-content/plugins/sistur`
- Linux padrão: `/var/www/html/wordpress/wp-content/plugins/sistur`
- Docker: Varia conforme volume montado

#### 2. Instale as dependências

```bash
# Navegue até o diretório do plugin
cd /caminho/para/wp-content/plugins/sistur

# Instale as dependências (sem dev)
composer install --no-dev

# Verifique se a pasta vendor/ foi criada
ls -la vendor/
```

#### 3. Verificação

Após a instalação, recarregue a página de QR Codes no WordPress. O aviso vermelho deve desaparecer e os botões de geração devem funcionar.

### Desenvolvimento Local (Git + WordPress Local)

Se você está desenvolvendo localmente e tem o código em um repositório Git separado do WordPress:

**Opção 1: Copiar vendor/ manualmente**
```bash
# Do repositório Git para o WordPress
cp -r /caminho/repo/sistur2/vendor/ /caminho/wordpress/wp-content/plugins/sistur/
cp /caminho/repo/sistur2/composer.json /caminho/wordpress/wp-content/plugins/sistur/
```

**Opção 2: Usar script de sincronização**
```bash
# Executar o script automático
bash /caminho/repo/sistur2/sync-vendor.sh

# Ou especificar caminho manualmente
bash /caminho/repo/sistur2/sync-vendor.sh /caminho/wordpress/wp-content/plugins/sistur
```

**Opção 3: Link simbólico (Linux/Mac)**
```bash
# Criar link do repo para o WordPress
ln -s /caminho/repo/sistur2 /caminho/wordpress/wp-content/plugins/sistur
```

### Permissões

Certifique-se de que o diretório de uploads do WordPress tem permissão de escrita:

```bash
# Verificar permissões
ls -la wp-content/uploads/sistur/qrcodes/

# Ajustar permissões se necessário
chmod 755 wp-content/uploads/sistur/qrcodes/
```

## ✅ Verificação da Instalação

Após a instalação, você pode verificar se tudo está funcionando:

1. Acesse o painel administrativo do WordPress
2. Vá em **RH > QR Codes dos Funcionários**
3. Clique em **Gerar Todos os QR Codes**
4. Os QR codes devem ser gerados com sucesso

## 🔧 Mudanças Recentes

### v1.4.1 - Correção de Geração de QR Codes

**Problema Corrigido:**
- O sistema estava usando a Google Charts API (descontinuada em 2015)
- Geração de QR codes falhava com erro de download

**Solução Implementada:**
- Substituída API externa por biblioteca PHP local (endroid/qr-code v4)
- Geração 100% local e confiável
- Melhor performance e independência de APIs externas

**Segurança:**
- Adicionada validação robusta de tokens UUID v4
- Sistema de logging de tentativas falhas
- Proteção contra SQL injection, XSS e outros ataques

## 📝 Arquivos Importantes

- `composer.json` - Definição de dependências
- `composer.lock` - Versões exatas das dependências (não commitado)
- `vendor/` - Bibliotecas instaladas (não commitado)
- `.gitignore` - Arquivos excluídos do Git

## 🔒 Segurança

O diretório `vendor/` e o arquivo `composer.lock` estão no `.gitignore` e não devem ser commitados ao repositório. Sempre execute `composer install` em cada ambiente.

## 🆘 Solução de Problemas

### Erro: "Biblioteca endroid/qr-code não encontrada"

**Solução:**
```bash
composer install --no-dev
```

### Erro: "Diretório não é gravável"

**Solução:**
```bash
chmod 755 wp-content/uploads/sistur/qrcodes/
chown www-data:www-data wp-content/uploads/sistur/qrcodes/
```

### QR codes não são gerados

**Verifique:**
1. Composer install foi executado?
2. Extensão GD do PHP está habilitada?
3. Permissões do diretório de uploads estão corretas?
4. Verifique os logs: `wp-content/debug.log`

## 📞 Suporte

Para problemas ou dúvidas:
- Verifique o arquivo `debug.log` do WordPress
- Consulte a documentação do plugin
- Entre em contato com o suporte técnico

---

**Desenvolvido por:** WebFluence
**Versão:** 1.4.1
**Última atualização:** 19 de Novembro de 2025
