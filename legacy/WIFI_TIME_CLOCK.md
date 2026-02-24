# Registro de Ponto com Validação Wi-Fi

## Visão Geral

Esta funcionalidade permite que os funcionários registrem seu ponto através do site, com validação de rede Wi-Fi para garantir que o registro seja feito apenas em locais autorizados (dentro do ambiente de trabalho).

## Características Principais

### 1. **Validação de Rede Wi-Fi**
- Os funcionários só podem registrar ponto quando conectados a redes Wi-Fi previamente autorizadas
- Impede registros de ponto fora do ambiente de trabalho
- Suporte para múltiplas redes Wi-Fi (diferentes filiais, andares, etc.)

### 2. **Interface Simplificada**
- UX/UI otimizada para usuários com limitações tecnológicas
- Telas grandes, botões intuitivos e feedback visual claro
- Design responsivo para desktop e mobile
- Animações suaves para melhor experiência

### 3. **Fluxo Direto**
- Login simples (CPF + Senha)
- Após login, tela direta de registro de ponto
- Sem menus complexos ou opções desnecessárias

## Instalação e Configuração

### Passo 1: Ativar o Plugin

Se o plugin já estiver instalado, apenas reative-o para criar as novas tabelas e páginas:

```
WordPress Admin > Plugins > SISTUR > Desativar
WordPress Admin > Plugins > SISTUR > Ativar
```

Isso criará automaticamente:
- Tabela `wp_sistur_wifi_networks` no banco de dados
- Página `/registrar-ponto/` com shortcode `[sistur_registrar_ponto]`
- Atualizará a página `/painel-funcionario/` para usar a nova interface

### Passo 2: Configurar Redes Wi-Fi Autorizadas

1. Acesse **WordPress Admin > RH > Redes Wi-Fi**

2. Clique em **"Adicionar Nova Rede"**

3. Preencha os dados:
   - **Nome da Rede**: Nome identificador interno (ex: "Wi-Fi Escritório Principal")
   - **SSID**: Nome exato da rede Wi-Fi conforme aparece nos dispositivos (ex: "MinhaEmpresa-WiFi")
     - ⚠️ **IMPORTANTE**: É case-sensitive (diferencia maiúsculas e minúsculas)
   - **BSSID (Opcional)**: Endereço MAC do roteador para maior segurança
   - **Descrição**: Informações sobre localização ou uso desta rede
   - **Status**: Marque para ativar a rede

4. Clique em **"Salvar Rede"**

### Exemplo de Configuração

```
Nome da Rede: Wi-Fi Matriz - 1º Andar
SSID: EMPRESA-MATRIZ-1
BSSID: AA:BB:CC:DD:EE:FF
Descrição: Rede do primeiro andar da matriz - Rua ABC, 123
Status: ✓ Ativa
```

## Como os Funcionários Usam

### 1. **Acessar a Página de Login**

O funcionário acessa: `https://seusite.com/login-funcionario/`

- Interface limpa com foco no essencial
- Campos grandes e fáceis de identificar
- CPF formatado automaticamente enquanto digita

### 2. **Fazer Login**

- Digite o CPF (será formatado automaticamente)
- Digite a senha
- Clique em **"Entrar"**

### 3. **Registrar Ponto**

Após o login, o funcionário é direcionado automaticamente para a tela de registro:

#### **Elementos da Tela:**

1. **Relógio Digital Grande**: Mostra hora e data atual em tempo real

2. **Status da Rede Wi-Fi**:
   - 🔍 **Amarelo**: Verificando rede...
   - ✅ **Verde**: Rede autorizada (pode registrar)
   - ❌ **Vermelho**: Rede não autorizada (não pode registrar)

3. **Botão "Registrar Ponto"**:
   - Grande, centralizado e intuitivo
   - Mostra qual tipo de registro será feito (Entrada, Saída, etc.)
   - Desabilitado se não estiver em rede autorizada

4. **Registros de Hoje**: Lista com todos os pontos já registrados no dia

### 4. **Fluxo de Validação Wi-Fi**

#### Navegadores Desktop/Mobile:
1. Sistema solicita que o usuário informe o nome da rede Wi-Fi
2. Usuário digita o SSID da rede
3. Sistema valida com o backend
4. Se autorizado, libera o botão de registro

#### Aplicativo Mobile (futuro):
- Detecção automática da rede via API nativa do dispositivo

### 5. **Confirmação de Registro**

Após clicar em "Registrar Ponto":
- Feedback visual imediato (botão muda de cor)
- Mensagem de sucesso clara
- Página recarrega automaticamente mostrando o novo registro

## Páginas Criadas

### 1. `/login-funcionario/`
- Shortcode: `[sistur_login_funcionario]`
- Interface simplificada de login
- Validação de CPF em tempo real
- Redirecionamento automático após login

### 2. `/painel-funcionario/` ou `/registrar-ponto/`
- Shortcode: `[sistur_registrar_ponto]`
- Interface de registro de ponto
- Validação de Wi-Fi integrada
- Visualização de registros do dia

## Gerenciamento Administrativo

### Visualizar Redes Cadastradas

**WordPress Admin > RH > Redes Wi-Fi**

A interface administrativa mostra:
- Lista de todas as redes cadastradas
- Nome, SSID, BSSID, Descrição
- Status (Ativa/Inativa)
- Ações: Editar / Excluir

### Editar Rede

1. Clique em **"Editar"** na rede desejada
2. Modifique os campos necessários
3. Clique em **"Salvar Rede"**

### Desativar Rede Temporariamente

Em vez de excluir, você pode desativar:
1. Clique em **"Editar"**
2. Desmarque **"Rede ativa"**
3. Salve

Redes inativas não são válidas para registro de ponto.

### Excluir Rede

1. Clique em **"Excluir"**
2. Confirme a exclusão

⚠️ **Cuidado**: Esta ação não pode ser desfeita.

## Segurança

### Validação SSID
- O SSID é validado no servidor (backend)
- Impossível burlar via JavaScript do navegador
- Case-sensitive para evitar redes falsas

### Validação BSSID (Opcional)
- Adiciona camada extra de segurança
- Valida o endereço MAC do roteador
- Impede redes com mesmo SSID mas roteadores diferentes

### Limitações Técnicas

#### Navegadores Web:
- **Limitação**: Navegadores não permitem acesso ao SSID da rede Wi-Fi por questões de privacidade
- **Solução Atual**: Usuário informa manualmente o nome da rede
- **Solução Futura**: Aplicativo mobile nativo com acesso à API de rede

#### Recomendações:
1. **Para máxima segurança**: Use BSSID além do SSID
2. **Para praticidade**: Use apenas SSID e instrua funcionários sobre o nome correto
3. **Alternativa**: Combine com validação de IP (desenvolvimento futuro)

## Shortcodes Disponíveis

### `[sistur_login_funcionario]`
Interface de login simplificada para funcionários.

**Uso:**
```
Crie uma página e adicione apenas: [sistur_login_funcionario]
```

### `[sistur_registrar_ponto]`
Interface de registro de ponto com validação Wi-Fi.

**Uso:**
```
Crie uma página e adicione apenas: [sistur_registrar_ponto]
```

### `[sistur_painel_funcionario]`
Painel completo do funcionário (versão original, mais complexa).

**Uso:**
```
Crie uma página e adicione apenas: [sistur_painel_funcionario]
```

## APIs AJAX

### Backend (WordPress AJAX)

#### `sistur_save_wifi_network`
Salva ou atualiza uma rede Wi-Fi (Admin)

**Parâmetros:**
- `id` (opcional): ID da rede para edição
- `network_name`: Nome identificador
- `network_ssid`: SSID da rede
- `network_bssid`: MAC do roteador (opcional)
- `description`: Descrição
- `status`: 0 ou 1
- `nonce`: Token de segurança

#### `sistur_delete_wifi_network`
Deleta uma rede Wi-Fi (Admin)

**Parâmetros:**
- `id`: ID da rede
- `nonce`: Token de segurança

#### `sistur_get_wifi_networks`
Obtém lista de redes Wi-Fi (Admin)

**Parâmetros:**
- `nonce`: Token de segurança

#### `sistur_validate_wifi_network`
Valida se uma rede está autorizada (Público)

**Parâmetros:**
- `ssid`: SSID da rede
- `bssid`: MAC do roteador (opcional)

## Banco de Dados

### Tabela: `wp_sistur_wifi_networks`

```sql
CREATE TABLE wp_sistur_wifi_networks (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    network_name varchar(255) NOT NULL,
    network_ssid varchar(255) NOT NULL,
    network_bssid varchar(17) DEFAULT NULL,
    description text DEFAULT NULL,
    status tinyint(1) DEFAULT 1,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY network_ssid (network_ssid),
    KEY status (status)
);
```

**Campos:**
- `id`: ID único da rede
- `network_name`: Nome identificador interno
- `network_ssid`: SSID da rede Wi-Fi
- `network_bssid`: Endereço MAC do roteador (opcional)
- `description`: Descrição/localização da rede
- `status`: 1 = ativa, 0 = inativa
- `created_at`: Data de criação
- `updated_at`: Data da última atualização

## Troubleshooting

### Problema: "Rede não autorizada" mesmo estando na rede correta

**Soluções:**
1. Verifique se o SSID foi digitado exatamente como aparece no dispositivo (case-sensitive)
2. Verifique se a rede está marcada como "Ativa" no admin
3. Limpe o cache do navegador
4. Verifique se há caracteres especiais ou espaços no SSID

### Problema: Botão "Registrar Ponto" permanece desabilitado

**Soluções:**
1. Verifique o status da rede Wi-Fi na tela
2. Se aparecer "Verificando rede...", aguarde alguns segundos
3. Se aparecer erro, informe o SSID correto quando solicitado
4. Recarregue a página

### Problema: Modal de SSID não aparece

**Soluções:**
1. Verifique se há redes Wi-Fi cadastradas no sistema
2. Verifique se JavaScript está habilitado no navegador
3. Verifique console do navegador para erros (F12)

## Melhorias Futuras

### Versão 1.5 (Planejado)
- [ ] Aplicativo mobile nativo com detecção automática de Wi-Fi
- [ ] Validação por IP além de Wi-Fi
- [ ] Geolocalização como validação adicional
- [ ] Notificações push para lembretes de ponto
- [ ] Reconhecimento facial (biometria)

### Versão 1.6 (Planejado)
- [ ] Dashboard de analytics de registros
- [ ] Relatórios de tentativas de registro fora da rede
- [ ] Integração com Bluetooth Beacons
- [ ] Modo offline com sincronização posterior

## Suporte

Para dúvidas ou problemas:
1. Verifique esta documentação
2. Consulte os logs do WordPress (WP_DEBUG)
3. Entre em contato com o suporte técnico

## Changelog

### Versão 1.4.0 (Atual)
- ✅ Adicionada validação de rede Wi-Fi
- ✅ Interface simplificada de login
- ✅ Interface simplificada de registro de ponto
- ✅ Gerenciamento de redes Wi-Fi no admin
- ✅ Design responsivo e acessível
- ✅ Feedback visual aprimorado

---

**Desenvolvido para SISTUR - Sistema de Turismo**
**Versão da Documentação: 1.0**
**Data: 2025**
