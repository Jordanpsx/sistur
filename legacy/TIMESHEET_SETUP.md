# 🕐 Sistema de Ponto Eletrônico SISTUR - Guia de Setup (v1.4.0)

## 📋 Pré-requisitos

- WordPress instalado e funcionando
- Plugin SISTUR ativado
- Acesso ao painel administrativo do WordPress
- Acesso SSH ao servidor (para configurar cron externo - recomendado)

---

## 🚀 Passo 1: Atualizar Plugin

### 1.1 Desativar e Reativar o Plugin

1. Acesse: **WordPress Admin → Plugins → Plugins Instalados**
2. Localize "SISTUR - Sistema de Turismo"
3. Clique em **"Desativar"**
4. Aguarde a desativação
5. Clique em **"Ativar"**

✅ **O que acontece:**
- Tabelas novas são criadas (`wp_sistur_contract_types`, `wp_sistur_settings`)
- Colunas novas são adicionadas nas tabelas existentes
- Tipos de contrato padrão são inseridos
- Configurações iniciais são criadas
- Chave secreta do cron é gerada automaticamente

---

## 🔑 Passo 2: Gerar Tokens QR para Funcionários

### 2.1 Via Console JavaScript (Mais Rápido)

1. Acesse: **WordPress Admin → SISTUR → Funcionários**
2. Abra o **Console do Navegador** (F12 → Console)
3. Cole e execute este código:

```javascript
jQuery.post(ajaxurl, {
    action: 'sistur_generate_tokens_bulk',
    nonce: sisturQRCode.nonce
}, function(response) {
    if (response.success) {
        alert(response.data.message);
        console.log('Resultado:', response.data.result);
        location.reload(); // Recarregar para ver tokens gerados
    } else {
        alert('Erro: ' + response.data.message);
    }
});
```

✅ **Resultado esperado:**
```
Tokens gerados: 15 de 15 (Falhas: 0)
```

### 2.2 Via Código PHP (Alternativa)

Crie um arquivo temporário `generate-tokens.php` na raiz do WordPress:

```php
<?php
require_once('wp-load.php');

// Verificar se é admin
if (!current_user_can('manage_options')) {
    die('Acesso negado');
}

$qrcode = SISTUR_QRCode::get_instance();
$result = $qrcode->generate_tokens_for_all_employees();

echo "<h2>Resultado:</h2>";
echo "<p>Total de funcionários sem token: " . $result['total'] . "</p>";
echo "<p>Tokens gerados com sucesso: " . $result['generated'] . "</p>";
echo "<p>Falhas: " . $result['failed'] . "</p>";
```

Acesse: `https://seusite.com/generate-tokens.php`

⚠️ **IMPORTANTE**: Deletar o arquivo após usar!

---

## 📄 Passo 3: Gerar QR Codes para Impressão

### 3.1 Gerar QR Code Individual

1. Acesse: **WordPress Admin → SISTUR → Funcionários**
2. Clique no funcionário desejado
3. Na seção "QR Code", clique em **"Gerar QR Code"**
4. Clique em **"Baixar QR Code"** para salvar a imagem
5. Imprima em tamanho A4 ou crachá

### 3.2 Gerar QR Codes em Lote (Recomendado)

Use este script PHP temporário `generate-all-qrcodes.php`:

```php
<?php
require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Acesso negado');
}

global $wpdb;
$table = $wpdb->prefix . 'sistur_employees';
$employees = $wpdb->get_results("SELECT id, name FROM $table WHERE status = 1");

$qrcode = SISTUR_QRCode::get_instance();

echo "<h1>QR Codes Gerados</h1>";
echo "<style>
    .qr-card {
        display: inline-block;
        margin: 20px;
        padding: 20px;
        border: 2px solid #333;
        text-align: center;
        page-break-inside: avoid;
    }
    @media print {
        .qr-card { page-break-after: always; }
    }
</style>";

foreach ($employees as $emp) {
    $qr_url = $qrcode->generate_qrcode($emp->id, 300);

    echo "<div class='qr-card'>";
    echo "<h2>" . esc_html($emp->name) . "</h2>";
    echo "<img src='" . esc_url($qr_url) . "' style='width: 300px; height: 300px;' />";
    echo "<p>ID: " . $emp->id . "</p>";
    echo "</div>";
}

echo "<script>window.print();</script>";
```

Acesse `https://seusite.com/generate-all-qrcodes.php` e imprima.

---

## ⏰ Passo 4: Configurar Processamento Automático (Cron)

### 4.1 Obter a Chave Secreta

Execute no MySQL/phpMyAdmin:

```sql
SELECT setting_value
FROM wp_sistur_settings
WHERE setting_key = 'cron_secret_key';
```

**Exemplo de resultado:**
```
K7xR2mQ9pL4vN8wT3fY6hJ1zC5bD0sA8gE
```

### 4.2 Configurar Cron Externo (Método Recomendado)

#### No servidor Linux/Ubuntu:

1. Conecte via SSH
2. Edite o crontab:

```bash
crontab -e
```

3. Adicione esta linha (substituir CHAVE e URL):

```bash
0 1 * * * curl -s "https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA" > /dev/null 2>&1
```

**Explicação:**
- `0 1 * * *` = Todo dia à 01:00
- `-s` = Silent (não exibir progresso)
- `> /dev/null 2>&1` = Descartar output

4. Salvar e sair (Ctrl+X, Y, Enter)

5. Verificar se foi agendado:

```bash
crontab -l
```

#### Via cPanel (Hospedagem Compartilhada):

1. Acesse: **cPanel → Cron Jobs**
2. Adicionar novo cron job:
   - **Minuto:** 0
   - **Hora:** 1
   - **Dia:** *
   - **Mês:** *
   - **Dia da Semana:** *
   - **Comando:**

   ```bash
   curl -s "https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA"
   ```

3. Salvar

#### Via Serviço Externo (Alternativa):

Use serviços como:
- **EasyCron** (https://www.easycron.com)
- **UptimeRobot** (https://uptimerobot.com)
- **Cron-Job.org** (https://cron-job.org)

Configure para chamar:
```
https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA
```

A cada 1 hora ou diariamente às 01:00.

### 4.3 Testar Processamento Manual

Execute no navegador (substituir a chave):

```
https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA
```

**Resposta esperada:**
```json
{
  "success": true,
  "message": "Processamento iniciado com sucesso."
}
```

---

## 🔧 Passo 5: Configurar Ajustes (Opcional)

### 5.1 Alterar Tolerância de Ponto

Execute no MySQL:

```sql
-- Alterar tolerância para 10 minutos por batida
UPDATE wp_sistur_settings
SET setting_value = '10'
WHERE setting_key = 'tolerance_minutes_per_punch';

-- Alterar tipo de tolerância
UPDATE wp_sistur_settings
SET setting_value = 'DAILY_ACCUMULATED'
WHERE setting_key = 'tolerance_type';
```

### 5.2 Alterar Horário de Processamento

```sql
-- Processar às 02:00 ao invés de 01:00
UPDATE wp_sistur_settings
SET setting_value = '02:00'
WHERE setting_key = 'processing_time';
```

⚠️ **Importante:** Se alterar, atualizar o cron também!

### 5.3 Alterar Tamanho do Lote

```sql
-- Processar 100 funcionários por lote (padrão: 50)
UPDATE wp_sistur_settings
SET setting_value = '100'
WHERE setting_key = 'processing_batch_size';
```

---

## 🖥️ Passo 6: Configurar Kiosk de Ponto

### 6.1 Requisitos do Kiosk

- **Hardware:** Tablet Android/iPad ou PC com webcam
- **Software:** Navegador moderno (Chrome, Firefox, Edge)
- **Localização:** Sala do supervisor (supervisão física)

### 6.2 Criar Página de Kiosk

Crie um arquivo HTML simples `kiosk.html`:

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SISTUR - Ponto Eletrônico</title>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background: #f0f0f0;
        }
        #reader {
            width: 500px;
            margin: 0 auto;
            border: 2px solid #333;
        }
        .success {
            color: green;
            font-size: 24px;
            font-weight: bold;
        }
        .error {
            color: red;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <h1>🕐 SISTUR - Registro de Ponto</h1>
    <p>Aproxime seu QR Code da câmera</p>

    <div id="reader"></div>
    <div id="result"></div>

    <script>
        const siteUrl = 'https://seusite.com'; // ALTERAR PARA SEU SITE

        function onScanSuccess(decodedText) {
            // Parar escaneamento temporariamente
            html5QrcodeScanner.pause();

            // Enviar para API
            fetch(`${siteUrl}/wp-json/sistur/v1/punch`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ token: decodedText })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('result').innerHTML = `
                        <div class="success">
                            ✅ Ponto registrado!<br>
                            ${data.data.employee_name}<br>
                            ${data.data.punch_time}
                        </div>
                    `;

                    // Limpar após 3 segundos e retomar
                    setTimeout(() => {
                        document.getElementById('result').innerHTML = '';
                        html5QrcodeScanner.resume();
                    }, 3000);
                } else {
                    document.getElementById('result').innerHTML = `
                        <div class="error">❌ ${data.message}</div>
                    `;
                    setTimeout(() => {
                        document.getElementById('result').innerHTML = '';
                        html5QrcodeScanner.resume();
                    }, 3000);
                }
            })
            .catch(error => {
                document.getElementById('result').innerHTML = `
                    <div class="error">❌ Erro de conexão</div>
                `;
                setTimeout(() => {
                    document.getElementById('result').innerHTML = '';
                    html5QrcodeScanner.resume();
                }, 3000);
            });
        }

        const html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            {
                fps: 10,
                qrbox: { width: 250, height: 250 }
            }
        );

        html5QrcodeScanner.render(onScanSuccess);
    </script>
</body>
</html>
```

**Salvar e acessar:** `https://seusite.com/kiosk.html`

### 6.3 Modo Quiosque no Navegador

**Chrome (Android/Desktop):**
```
chrome://flags/#enable-kiosk-mode
```

**Firefox:**
Instalar extensão "Kiosk Mode"

---

## ✅ Passo 7: Testar o Sistema

### 7.1 Teste de Batida

1. Abra `kiosk.html` no navegador
2. Aproxime QR code de um funcionário
3. Verificar mensagem de sucesso
4. Repetir 4 vezes (simular: entrada, início almoço, fim almoço, saída)

### 7.2 Verificar Batidas no Banco

```sql
SELECT * FROM wp_sistur_time_entries
WHERE shift_date = CURDATE()
ORDER BY punch_time DESC;
```

### 7.3 Executar Processamento Manual

```
https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA
```

### 7.4 Verificar Processamento

```sql
SELECT * FROM wp_sistur_time_days
WHERE shift_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY);
```

**Verificar:**
- ✅ `minutos_trabalhados` está calculado
- ✅ `saldo_calculado_minutos` está correto
- ✅ `needs_review = 0` (se tiver 4 batidas)

---

## 🎯 Passo 8: Uso Diário

### Fluxo Normal:

1. **Manhã:** Funcionários batem ponto no kiosk
2. **01:00 AM:** Cron processa automaticamente o dia anterior
3. **Manhã seguinte:** Supervisor verifica dias com `needs_review = 1`
4. **Revisar:** Supervisor adiciona observações e ajusta se necessário

### Visualizar Saldo de um Funcionário:

```
https://seusite.com/wp-json/sistur/v1/balance/123
```

Substituir `123` pelo ID do funcionário.

---

## 🐛 Troubleshooting

### Problema: Tokens não foram gerados

**Solução:**
```sql
-- Verificar se coluna existe
SHOW COLUMNS FROM wp_sistur_employees LIKE 'token_qr';

-- Se não existir, executar migração
ALTER TABLE wp_sistur_employees
ADD COLUMN token_qr varchar(36) DEFAULT NULL UNIQUE AFTER password,
ADD KEY token_qr (token_qr);
```

### Problema: Cron não está executando

**Solução:**
1. Testar manualmente o endpoint
2. Verificar logs do servidor: `/var/log/syslog` ou `/var/log/cron`
3. Usar WP-Cron como fallback (menos confiável):
   - O plugin já registra automaticamente
   - Verificar: `wp cron event list` (WP-CLI)

### Problema: Processamento retorna erro 403

**Solução:**
Chave secreta incorreta. Verificar:
```sql
SELECT setting_value FROM wp_sistur_settings WHERE setting_key = 'cron_secret_key';
```

---

## 📚 Documentação Adicional

- **CHANGELOG.md** - Histórico completo de mudanças
- **README.md** - Visão geral do plugin
- **PERMISSIONS_SYSTEM.md** - Sistema de permissões

---

## 📞 Suporte

**Desenvolvido por:** WebFluence
**Email:** suporte@webfluence.com.br
**Versão:** 1.4.0

---

✅ **Setup Completo!** O sistema está pronto para uso.
