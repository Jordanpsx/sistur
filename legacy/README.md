# SISTUR - Sistema de Turismo

Plugin WordPress completo para gerenciamento de operações turísticas.

## 📋 Visão Geral

O SISTUR é um plugin WordPress abrangente que oferece funcionalidades completas para gestão de operações turísticas, incluindo:

- **Gestão de RH**: Funcionários, departamentos e pagamentos
- **Controle de Ponto**: Registro eletrônico com histórico completo
- **QR Codes**: Geração de códigos únicos para funcionários
- **Gestão de Inventário**: Produtos, categorias e movimentações de estoque
- **Gestão de Leads**: Captura de contatos via formulários Elementor
- **Dashboards**: Visão geral completa do sistema

## 🚀 Características Principais

### Autenticação de Funcionários
- Login via CPF (sem necessidade de senha)
- Sessões seguras com HTTPOnly e SameSite
- Validação automática de CPF
- Expiração de sessão em 8 horas

### Gestão de Funcionários
- CRUD completo de funcionários e departamentos
- Upload de fotos
- Gerenciamento de carga horária
- Histórico completo de pagamentos
- Integração com sistema de ponto

### Ponto Eletrônico
- Registro de entrada, saída e intervalos
- Folha de ponto mensal
- Cálculo automático de horas trabalhadas
- Diferentes status (presente, ausência, atestado, etc.)
- Interface pública para registro

### QR Code
- Geração automática de QR Codes por funcionário
- Dados criptografados
- Download disponível
- Tamanho customizável

### Gestão de Inventário
- Controle de produtos e categorias
- Movimentações de estoque (entrada, saída, ajuste)
- Alertas de estoque baixo
- Relatórios de movimentação

### Gestão de Leads
- Captura automática via formulários Elementor
- Gerenciamento de status (novo, contatado)
- Estatísticas em tempo real
- Filtros avançados

## 📦 Instalação

1. Faça upload do plugin para `/wp-content/plugins/sistur/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Configure as opções em SISTUR > Configurações

### Requisitos

- WordPress 5.0 ou superior
- PHP 7.2 ou superior
- MySQL 5.7+ ou MariaDB 10.3+

### Dependências Opcionais

- Elementor Pro (para captura de leads via formulários)
- endroid/qr-code (para geração de QR Codes - instalado via Composer)

## 🔧 Configuração

### Instalação via Composer

```bash
cd wp-content/plugins/sistur
composer install
```

### Páginas Criadas Automaticamente

O plugin cria automaticamente duas páginas:

- `/login-funcionario/` - Login de funcionários
- `/painel-funcionario/` - Painel do funcionário

## 📚 Uso

### Shortcodes Disponíveis

```php
// Login de funcionário
[sistur_login_funcionario]

// Painel do funcionário
[sistur_painel_funcionario]

// Folha de ponto
[sistur_folha_ponto]

// QR Code do funcionário
[sistur_employee_qrcode]
[sistur_employee_qrcode size="300"]

// Inventário público
[sistur_inventory]
[sistur_inventory view="grid"]
[sistur_inventory category="equipamentos"]

// Debug de horário
[sistur_debug_horario]
[sistur_debug_timezone]
```

### Rotas AJAX

#### Funcionários
```javascript
// Salvar funcionário
action: 'save_employee'

// Obter funcionário
action: 'get_employee'

// Deletar funcionário
action: 'delete_employee'
```

#### Ponto Eletrônico
```javascript
// Obter folha de ponto
action: 'sistur_time_get_sheet'

// Salvar registro
action: 'sistur_time_save_entry'

// Registro público
action: 'sistur_time_public_clock'
```

#### Leads
```javascript
// Obter leads
action: 'sistur_get_leads'

// Atualizar status
action: 'sistur_update_lead_status'

// Obter estatísticas
action: 'sistur_get_leads_stats'
```

## 🗄️ Estrutura do Banco de Dados

### Tabelas Principais

- `wp_sistur_employees` - Funcionários
- `wp_sistur_departments` - Departamentos
- `wp_sistur_time_entries` - Registros de ponto
- `wp_sistur_time_days` - Status diário
- `wp_sistur_payment_records` - Pagamentos
- `wp_sistur_leads` - Leads
- `wp_sistur_products` - Produtos
- `wp_sistur_product_categories` - Categorias
- `wp_sistur_inventory_movements` - Movimentações

## 🎨 Personalização

### Hooks Disponíveis

```php
// Após captura de lead via Elementor
do_action('sistur_elementor_form_submitted', $record, $handler);

// Após login de funcionário
do_action('sistur_employee_logged_in', $employee_data);

// Após registro de ponto
do_action('sistur_time_entry_saved', $entry_id, $employee_id);
```

### Filtros Disponíveis

```php
// Modificar URL de login
apply_filters('sistur_login_url', $url);

// Modificar dados de sessão
apply_filters('sistur_session_data', $data);

// Modificar configurações de ponto
apply_filters('sistur_time_tracking_settings', $settings);
```

## 🔒 Segurança

O plugin implementa as seguintes medidas de segurança:

- ✅ Validação de CPF com algoritmo correto
- ✅ Nonce verification em todos os AJAX
- ✅ Prepared statements para queries SQL
- ✅ Escape de dados de saída (esc_html, esc_url, etc.)
- ✅ Sessões seguras com HTTPOnly e SameSite
- ✅ Verificação de permissões (current_user_can)
- ✅ Sanitização de entradas de usuário

## 📝 Changelog

### Versão 1.1.0 (2025-01-13)
- Implementação inicial completa
- Sistema de autenticação de funcionários
- Módulos de RH, ponto, inventário e leads
- Dashboards administrativos
- Templates públicos
- Integração com Elementor

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor, abra uma issue ou pull request.

## 📄 Licença

GPL-2.0+

## 👥 Autores

- **WebFluence** - [https://webfluence.com.br/girassol/](https://webfluence.com.br/girassol/)

## 📞 Suporte

Para suporte, entre em contato através do site oficial ou abra uma issue no repositório.

## 🌟 Agradecimentos

- Equipe WordPress
- Comunidade Elementor
- Contribuidores do projeto endroid/qr-code

---

**Desenvolvido com ❤️ pela WebFluence**
