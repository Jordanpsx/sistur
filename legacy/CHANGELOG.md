# Changelog - SISTUR

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

## [2.6.1] - 2025-12-29

### 🐛 **Correções de Bugs**

- **Conversão mL → L**: Corrigido cálculo de estoque agregado em "Tipos de Produtos" para aplicar fator de conversão de unidades
- **Produção multi-tipo**: Corrigido erro 400 ao produzir produtos que são RAW + MANUFACTURED (ex: vinagrete)
- **Vírgula decimal BR**: Adicionada função `normalizeDecimal()` para converter formato brasileiro (6,50) para padrão (6.50)
- **Custo zero na média**: Lotes com `cost_price = 0` são ignorados no cálculo de custo médio

### ✨ Melhorias

- **Confirmação de custo zero**: Prompt ao tentar inserir movimento com custo R$ 0,00

---

## [2.6.0] - 2025-12-29

### 🏷️ **Suporte a Tipos Múltiplos de Produto**

Produtos agora podem ter múltiplos tipos simultaneamente (ex: Insumo + Produzido).

---

### ✨ Novos Recursos

#### **1. Coluna `type` migrada para SET**
- Antes: `ENUM('RAW', 'RESALE', 'MANUFACTURED', 'RESOURCE')`
- Depois: `SET('RAW', 'RESALE', 'MANUFACTURED', 'RESOURCE')`
- Permite combinações como `RAW,MANUFACTURED`

#### **2. Novo Filtro de API: `has_type`**
- `GET /products?has_type=RAW` - Produtos que **contêm** o tipo RAW
- Usa `FIND_IN_SET()` internamente para SET columns
- Busca de ingredientes agora usa `has_type=RAW`

#### **3. Interface de Checkboxes**
- Formulário de produto: dropdown substituído por checkboxes
- Permite selecionar: ☐ Insumo | ☐ Produzido | ☐ Revenda
- Dica visual explica combinações

#### **4. Caso de Uso: Pré-Preparo**
- Ex: **Vinagrete** = Produzido (tem receita) + Insumo (pode ser ingrediente)
- Botão "Produzir" aparece para qualquer produto com MANUFACTURED
- Aparece na busca de ingredientes se tiver RAW

---

## [2.5.0] - 2025-12-29

### 🏭 **Fluxo de Produção para Produtos MANUFACTURED**

Esta versão adiciona a capacidade de produzir pratos/produtos, consumindo ingredientes e adicionando ao estoque.

---

### ✨ Novos Recursos

#### **1. Método `produce()` no Recipe Manager**
- Consome ingredientes via FIFO ao produzir
- Cria lote do produto final com custo calculado
- Registra movimentações `PRODUCTION_IN` e `PRODUCTION_OUT`

#### **2. API REST de Produção**
- `POST /sistur/v1/recipes/{id}/produce` - Produz quantidade especificada
- Parâmetros: `quantity`, `notes`
- Retorna detalhes: custo unitário, total, lote criado, novo estoque

#### **3. Interface de Produção**
- Botão verde 🔨 "Produzir" para produtos tipo MANUFACTURED
- Modal mostrando receita (ingredientes necessários)
- Campo de quantidade e observações
- Feedback de sucesso com custos calculados

#### **4. Cálculo de Custo Médio Ponderado**
- Custo do produto produzido = Σ(custo_ingrediente × quantidade)
- Usa média ponderada: `SUM(cost × qty) / SUM(qty)`
- Atualiza `cost_price` e `average_cost` do produto

---

## [2.4.0] - 2025-12-29

### 📦 **Hierarquia de Recursos (RESOURCE)**

Esta versão adiciona suporte para agrupar produtos específicos sob um insumo genérico.

---

### ✨ Novos Recursos

#### **1. Novo Tipo de Produto: RESOURCE**
- Representa insumo genérico (ex: "Arroz Branco")
- Agrupa múltiplas marcas/produtos (filhos)
- Estoque agregado calculado automaticamente

#### **2. Página "Tipos de Produtos"**
- Nova página em **Restaurante → Tipos de Produtos**
- Lista recursos com contagem de filhos e estoque agregado
- Modal para criar/editar recursos

#### **3. Vinculação de Produtos**
- Campo `resource_parent_id` em produtos
- Dropdown para vincular a insumo genérico
- Cálculo de estoque considera `content_quantity`

#### **4. Baixa de Estoque Distribuída**
- Receitas podem usar RESOURCE como ingrediente
- Sistema consome de todas as marcas via FIFO
- Conversão automática de unidades

### 🗄️ Alterações no Banco de Dados

- `sistur_products.resource_parent_id` - FK para RESOURCE pai
- `sistur_products.type` - Novo valor `'RESOURCE'`

---

## [1.4.0] - 2025-11-14

### 🎯 **Sistema Avançado de Ponto Eletrônico (Core Timesheet)**

Esta versão introduz um sistema completo de processamento automático de ponto eletrônico baseado em arquitetura profissional de dados.

---

### ✨ Novos Recursos

#### **1. Sistema de Tokens QR UUID**
- **Tokens únicos UUID v4** para cada funcionário
- QR codes agora contêm apenas o token (mais seguro)
- Geração automática de tokens para funcionários existentes
- API AJAX para geração em lote: `sistur_generate_tokens_bulk`

#### **2. API REST Completa de Ponto**
- `POST /wp-json/sistur/v1/punch` - Registrar batida via QR code (kiosk)
- `GET /wp-json/sistur/v1/timesheet/{userId}/{date}` - Obter folha de ponto do dia
- `GET /wp-json/sistur/v1/balance/{userId}` - Obter banco de horas total
- `GET /wp-json/sistur/v1/cron/process` - Endpoint para cron externo (híbrido)

#### **3. Processamento Automático de Batidas (Algoritmo 1-2-3-4)**
- **Algoritmo de Paridade**: Valida se o funcionário bateu exatamente 4 pontos (entrada, início almoço, fim almoço, saída)
- **Cálculo automático** de minutos trabalhados e saldo diário
- **Sistema de tolerância** configurável (por batida ou acumulado diário)
- **Processamento em lotes** para escalabilidade (50 funcionários por batch)
- **Job noturno** (WP-Cron + endpoint externo) executa às 01:00 por padrão

#### **4. Sistema de Revisão e Ajustes**
- Dias com batidas incompletas são **automaticamente marcados para revisão** (`needs_review = true`)
- Campo `supervisor_notes` para observações do supervisor
- Rastreamento de quem revisou e quando (`reviewed_by`, `reviewed_at`)
- Suporte a **ajuste manual** de banco de horas (`bank_minutes_adjustment`)
- Cálculo final: `saldo_final = saldo_calculado + ajuste_manual`

#### **5. Tipos de Contrato**
- Nova tabela `wp_sistur_contract_types` para definir regimes de trabalho
- 3 tipos padrão criados automaticamente:
  - Mensalista 8h/dia (480 min/dia, 44h semanais)
  - Mensalista 6h/dia (360 min/dia, 36h semanais)
  - Mensalista 4h/dia (240 min/dia, 24h semanais)
- Funcionários podem ser vinculados a um tipo de contrato

#### **6. Sistema de Configurações**
- Nova tabela `wp_sistur_settings` para configurações centralizadas
- Configurações padrão:
  - `tolerance_minutes_per_punch`: 5 minutos (tolerância por batida)
  - `tolerance_type`: PER_PUNCH ou DAILY_ACCUMULATED
  - `cron_secret_key`: Chave secreta para autenticação do cron
  - `auto_processing_enabled`: Habilitar/desabilitar processamento automático
  - `processing_batch_size`: 50 funcionários por lote
  - `processing_time`: "01:00" (horário de execução)

---

### 🗄️ Alterações no Banco de Dados

#### **Tabela: `wp_sistur_employees`**
- ✅ `token_qr` (varchar 36, UNIQUE) - Token UUID para QR code
- ✅ `contract_type_id` (FK) - Referência ao tipo de contrato
- ✅ `perfil_localizacao` (ENUM: INTERNO, EXTERNO) - Método de coleta (kiosk vs. mobile)

#### **Tabela: `wp_sistur_time_entries`**
- ✅ `source` - Novos valores: KIOSK, MOBILE_APP, MANUAL_AJUSTE
- ✅ `processing_status` (ENUM: PENDENTE, PROCESSADO) - Status de processamento

#### **Tabela: `wp_sistur_time_days`**
- ✅ `needs_review` (boolean) - Marca dias que precisam revisão
- ✅ `supervisor_notes` (text) - Observações do supervisor
- ✅ `reviewed_at` (datetime) - Quando foi revisado
- ✅ `reviewed_by` (bigint) - Quem revisou (user ID)
- ✅ `minutos_trabalhados` (int) - Total de minutos trabalhados no dia
- ✅ `saldo_calculado_minutos` (int) - Saldo calculado automaticamente
- ✅ `saldo_final_minutos` (int) - Saldo final (calculado + ajuste manual)

#### **Nova Tabela: `wp_sistur_contract_types`**
```sql
id                                  INT PRIMARY KEY
descricao                           VARCHAR(255) - Ex: "Mensalista 8h/dia"
carga_horaria_diaria_minutos        INT - Ex: 480 (8 horas)
carga_horaria_semanal_minutos       INT - Ex: 2640 (44 horas)
intervalo_minimo_almoco_minutos     INT - Ex: 60 (reservado para lógica futura)
```

#### **Nova Tabela: `wp_sistur_settings`**
```sql
id              INT PRIMARY KEY
setting_key     VARCHAR(100) UNIQUE
setting_value   TEXT
setting_type    ENUM('string', 'integer', 'boolean', 'json')
description     TEXT
```

---

### 🔧 Melhorias Técnicas

#### **Arquitetura**
- Nova classe `SISTUR_Punch_Processing` - Core de processamento
- Padrão Singleton mantido em todas as classes
- Separação clara de responsabilidades (MVC-like)

#### **Performance**
- Processamento em lotes (evita timeouts PHP)
- Índices adicionados em todos os novos campos
- Queries otimizadas com `LIMIT` e `OFFSET`
- Transients para gerenciar progresso de batches

#### **Segurança**
- Tokens UUID únicos e não previsíveis
- Validação de tokens no endpoint `/punch`
- Chave secreta para endpoint de cron
- Verificação de perfil de localização (INTERNO vs. EXTERNO)

#### **Confiabilidade**
- **Sistema híbrido de cron**: WP-Cron + endpoint externo
- Fallback automático se processamento falhar
- Logs automáticos em caso de erro
- Retry logic para batches incompletos

---

### 📋 Fluxo Completo Implementado

```
1. COLETA (Kiosk)
   └─ Funcionário aproxima QR code
   └─ POST /wp-json/sistur/v1/punch (token UUID)
   └─ Inserido em time_entries com status PENDENTE

2. PROCESSAMENTO NOTURNO (01:00 AM)
   └─ WP-Cron ou Cron Externo dispara
   └─ Buscar funcionários com batidas PENDENTE do dia anterior
   └─ Processar em lotes de 50

3. ALGORITMO 1-2-3-4
   └─ Se != 4 batidas → Marcar needs_review = TRUE
   └─ Se == 4 batidas:
       ├─ punch_1 = ENTRADA
       ├─ punch_2 = INICIO_ALMOCO
       ├─ punch_3 = FIM_ALMOCO
       └─ punch_4 = SAIDA
   └─ Calcular: (punch_2 - punch_1) + (punch_4 - punch_3) = minutos_trabalhados
   └─ Aplicar tolerância se necessário
   └─ Calcular saldo: minutos_trabalhados - carga_esperada
   └─ Inserir/Atualizar em time_days
   └─ Marcar batidas como PROCESSADO

4. REVISÃO (Se necessário)
   └─ Supervisor acessa folha de ponto
   └─ Vê dias com needs_review = TRUE
   └─ Adiciona supervisor_notes
   └─ Pode fazer ajuste manual (bank_minutes_adjustment)
   └─ Sistema recalcula saldo_final

5. CONSULTA
   └─ GET /wp-json/sistur/v1/balance/{userId}
   └─ Retorna banco de horas total (soma de saldo_final de dias completos)
```

---

### 🚀 Como Usar

#### **Ativar o Sistema**
1. Desativar e reativar o plugin para executar migrações do banco
2. Gerar tokens para funcionários existentes:
   ```javascript
   // No console do admin
   jQuery.post(ajaxurl, {
       action: 'sistur_generate_tokens_bulk',
       nonce: sisturQRCode.nonce
   }, function(response) {
       console.log(response.data.message);
   });
   ```

#### **Configurar Cron Externo (Recomendado)**
Adicionar ao crontab do servidor:
```bash
0 1 * * * curl -s "https://seusite.com/wp-json/sistur/v1/cron/process?key=SUA_CHAVE_SECRETA" > /dev/null 2>&1
```

A chave secreta é gerada automaticamente e pode ser obtida em:
```sql
SELECT setting_value FROM wp_sistur_settings WHERE setting_key = 'cron_secret_key';
```

#### **Gerar QR Code para Funcionário**
```php
// Via código
$qrcode_instance = SISTUR_QRCode::get_instance();
$qrcode_url = $qrcode_instance->generate_qrcode($employee_id, 300);

// Via shortcode
[sistur_employee_qrcode employee_id="123" size="300"]
```

---

### 🔄 Compatibilidade

- ✅ **Sem dados legados**: Sistema novo, nenhuma versão anterior foi usada em produção
- ✅ **Migrações automáticas**: Campos adicionados sem quebrar estrutura existente
- ✅ **Fallbacks**: `time_expected_minutes` usado se contrato não definido
- ✅ **Índices**: Adicionados automaticamente nas migrações

---

### 📝 Notas de Desenvolvimento

#### **MVP Implementado**
Esta versão implementa o **MVP (Minimum Viable Product)** com foco em:
- ✅ Turno diurno padrão (1-2-3-4)
- ✅ Algoritmo de paridade simples
- ✅ Tolerância básica
- ✅ Sistema de revisão manual

#### **Funcionalidades Futuras** (v1.5.0+)
- [ ] Turnos noturnos (batidas em dias diferentes)
- [ ] Horistas (sem carga horária fixa)
- [ ] Turnos customizáveis pelo admin
- [ ] Reconhecimento facial (substituir/complementar QR)
- [ ] App mobile para perfil EXTERNO
- [ ] Geolocalização para batidas externas
- [ ] Dashboard de BI com gráficos de produtividade
- [ ] Notificações automáticas (supervisor para dias pendentes)
- [ ] Integração com folha de pagamento

---

### 🐛 Correções de Bugs

Nenhum bug corrigido (primeira versão do sistema).

---

### 📦 Dependências

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.2+
- Extensão PHP: `random_bytes()` (disponível desde PHP 7.0)
- Opcional: Biblioteca `endroid/qr-code` para QR codes melhores

---

### 👥 Créditos

**Desenvolvido por**: WebFluence
**Cliente**: Girassol Turismo
**Versão**: 1.4.0
**Data**: Novembro 2025

---

### 📄 Licença

GPL-2.0+ - Veja LICENSE para mais detalhes.
