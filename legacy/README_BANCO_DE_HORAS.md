# SISTEMA DE ABATIMENTO DE BANCO DE HORAS - SISTUR

## 📋 VISÃO GERAL

Sistema completo para gerenciar abatimento de banco de horas com duas modalidades:
1. **Compensação em Folga** - Permite conceder folgas aos funcionários usando saldo do banco de horas
2. **Pagamento em Dinheiro** - Permite pagar horas extras calculando valores baseados em tipo de dia (útil, fim de semana, feriado)

---

## 🚀 INSTALAÇÃO

### Passo 1: Executar Script SQL

Execute o script SQL para criar as tabelas necessárias:

```bash
mysql -u seu_usuario -p seu_banco_de_dados < sql/create-timebank-tables.sql
```

Ou via phpMyAdmin:
1. Acesse phpMyAdmin
2. Selecione o banco de dados do WordPress
3. Clique em "SQL"
4. Cole o conteúdo do arquivo `sql/create-timebank-tables.sql`
5. Clique em "Executar"

**⚠️ IMPORTANTE:** Se o prefixo do seu WordPress for diferente de `wp_`, edite o arquivo SQL antes de executar.

### Passo 2: Verificar Instalação

Verifique se as tabelas foram criadas:

```sql
SHOW TABLES LIKE '%sistur_timebank%';
SHOW TABLES LIKE '%sistur_holidays%';
```

Você deve ver:
- `wp_sistur_holidays` (24 feriados inseridos)
- `wp_sistur_timebank_deductions`
- `wp_sistur_employee_payment_config`

### Passo 3: Verificar Permissões

```sql
SELECT * FROM wp_sistur_permissions WHERE name LIKE '%folga%' OR name LIKE '%horas_extra%';
```

Você deve ver 4 novas permissões:
- `dar_folga`
- `pagar_horas_extra`
- `aprovar_abatimento_banco`
- `gerenciar_feriados`

### Passo 4: Ativar o Sistema

O sistema já está ativo! Os arquivos foram integrados automaticamente:
- ✅ `includes/class-sistur-timebank-manager.php` - Classe principal
- ✅ `assets/js/timebank-manager.js` - JavaScript frontend
- ✅ `admin/views/timebank/modals.php` - Modais HTML
- ✅ `admin/views/timebank/pending-deductions.php` - Página administrativa
- ✅ `sistur.php` - Integração concluída

---

## ⚙️ CONFIGURAÇÃO INICIAL

### 1. Configurar Pagamento por Funcionário

Antes de pagar horas extras, cada funcionário precisa ter sua configuração de pagamento:

1. Acesse **SISTUR > Funcionários**
2. Clique em um funcionário
3. Clique em **"⚙ Configurar Pagamento"**
4. Preencha:
   - **Salário Base**: Salário mensal
   - **Valor Hora Base**: Calculado automaticamente ou manual
   - **Multiplicadores**:
     - Dias Úteis: 1.50 (50% CLT)
     - Fins de Semana: 2.00 (100%)
     - Feriados: 2.50 (150%)
5. Clique em **"Salvar Configuração"**

### 2. Gerenciar Feriados

O sistema já vem com feriados nacionais de 2025 e 2026. Para adicionar mais:

1. Acesse qualquer página do SISTUR
2. Clique em **"⚙ Gerenciar Feriados"**
3. Clique em **"+ Adicionar Feriado"**
4. Preencha:
   - Data
   - Descrição
   - Tipo (Nacional/Estadual/Municipal)
   - Multiplicador (ex: 2.00 = 200% = 100% adicional)
5. Salvar

---

## 💼 COMO USAR

### Cenário 1: Dar Folga a um Funcionário

**Situação:** João tem 16 horas de saldo no banco de horas e deseja tirar 1 dia de folga (8h).

1. Acesse **SISTUR > Time Tracking** ou **Funcionários**
2. Clique em **"+ Novo Abatimento"** ou no botão junto ao funcionário
3. Selecione o **Funcionário**: João
4. O sistema exibe: **Saldo Atual: +16h 00min**
5. Selecione **Tipo**: Compensação em Folga
6. Escolha **Quantidade**: Parcial
7. Informe **Minutos**: 480 (8 horas)
8. Preencha:
   - **Data Início**: 01/12/2025
   - **Data Fim**: (deixe vazio para 1 dia)
   - **Descrição**: "Folga compensatória"
9. Clique em **"Registrar Abatimento"**

✅ **Resultado:**
- Abatimento criado com status **PENDENTE**
- Aguardando aprovação do supervisor

### Cenário 2: Aprovar Folga

1. Acesse **SISTUR > Banco de Horas**
2. Veja lista de **Abatimentos Pendentes**
3. Clique em **"✓ Aprovar"** no registro do João
4. Informe observação (opcional)
5. Confirme

✅ **Resultado:**
- Abatimento aprovado
- 480 minutos ABATIDOS do banco de horas
- Novo saldo do João: 8h 00min

### Cenário 3: Pagar Horas Extras

**Situação:** Maria tem 24 horas no banco e quer receber em dinheiro.

1. Clique em **"+ Novo Abatimento"**
2. Selecione **Funcionário**: Maria
3. Saldo exibido: **+24h 00min**
4. Selecione **Tipo**: Pagamento em Dinheiro
5. Escolha **Quantidade**: Integral (todas as 24h)
6. (Opcional) Defina período de referência: 01/11/2025 a 30/11/2025
7. Clique em **"🔍 Calcular Prévia"**

**Sistema analisa:**
```
┌────────────────┬──────────┬────────────┐
│ Tipo           │ Horas    │ Valor      │
├────────────────┼──────────┼────────────┤
│ Dias Úteis     │ 12.00h   │ R$ 450,00  │
│ Fins de Semana │ 10.00h   │ R$ 500,00  │
│ Feriados       │ 2.00h    │ R$ 125,00  │
├────────────────┼──────────┼────────────┤
│ TOTAL          │ 24.00h   │ R$ 1.075,00│
└────────────────┴──────────┴────────────┘
```

8. Confirme valores
9. Clique em **"Registrar Abatimento"**

✅ **Resultado:**
- Abatimento criado com status **PENDENTE**
- Registro de pagamento vinculado
- Aguardando aprovação

### Cenário 4: Aprovar Pagamento

1. Acesse **SISTUR > Banco de Horas**
2. Veja abatimento da Maria (R$ 1.075,00)
3. Revise cálculo detalhado
4. Clique em **"✓ Aprovar"**
5. Confirme

✅ **Resultado:**
- 24 horas ABATIDAS do banco
- Pagamento aprovado e registrado
- RH pode processar pagamento

---

## 🔐 PERMISSÕES

### Configurar Permissões por Função

Acesse **SISTUR > Configurações > Permissões**

**Gerente de RH:** (tem todas)
- ✅ `dar_folga`
- ✅ `pagar_horas_extra`
- ✅ `aprovar_abatimento_banco`
- ✅ `gerenciar_feriados`

**Supervisor:**
- ✅ `dar_folga`
- ✅ `aprovar_abatimento_banco`
- ❌ `pagar_horas_extra` (não tem)
- ❌ `gerenciar_feriados` (não tem)

**Funcionário:**
- ❌ Não tem acesso

---

## 📊 RELATÓRIOS E DASHBOARDS

### Dashboard de Banco de Horas

Acesse **SISTUR > Banco de Horas** para ver:

1. **Cards de Resumo:**
   - 🟠 Pendentes de Aprovação
   - 🟢 Aprovados (Mês Atual)
   - 🔴 Rejeitados (Mês Atual)
   - 🔵 Total Pago (Mês Atual)

2. **Filtros:**
   - Por Status (Pendente/Aprovado/Rejeitado)
   - Por Tipo (Folga/Pagamento)

3. **Listagem Completa:**
   - Funcionário
   - Tipo
   - Horas
   - Valor (se pagamento)
   - Data Solicitação
   - Status
   - Ações

---

## 🧮 COMO O CÁLCULO FUNCIONA

### Exemplo Prático

**Funcionário:** Carlos
**Saldo:** 1500 minutos (25 horas)
**Configuração:**
- Salário Base: R$ 3.300,00/mês
- Valor Hora: R$ 15,00 (calculado: 3300 / 220h)
- Multiplicador Dias Úteis: 1.50
- Multiplicador Fins de Semana: 2.00
- Multiplicador Feriados: 2.50

**Período Analisado:** 01/11 a 30/11

**Sistema busca dias com saldo positivo:**
```
┌────────────┬──────────┬─────────┬──────────────┐
│ Data       │ Minutos  │ Tipo    │ Valor/Hora   │
├────────────┼──────────┼─────────┼──────────────┤
│ 05/11 (Ter)│ 120 min  │ Útil    │ R$ 22,50     │
│ 09/11 (Sáb)│ 240 min  │ FDS     │ R$ 30,00     │
│ 15/11 (Sex)│ 60 min   │ Feriado │ R$ 37,50     │
│ 18/11 (Seg)│ 180 min  │ Útil    │ R$ 22,50     │
│ ... até completar 1500 minutos                  │
└────────────┴──────────┴─────────┴──────────────┘
```

**Cálculo:**
- **Dias Úteis:** 720 min = 12h × R$ 22,50 = R$ 270,00
- **Fins de Semana:** 600 min = 10h × R$ 30,00 = R$ 300,00
- **Feriados:** 180 min = 3h × R$ 37,50 = R$ 112,50

**TOTAL A PAGAR:** R$ 682,50

---

## 📁 ESTRUTURA DE ARQUIVOS

```
sistur2/
├── includes/
│   └── class-sistur-timebank-manager.php    # Classe principal (backend)
├── assets/
│   └── js/
│       └── timebank-manager.js              # JavaScript (frontend)
├── admin/
│   └── views/
│       └── timebank/
│           ├── modals.php                   # Modais HTML
│           └── pending-deductions.php       # Página administrativa
├── sql/
│   └── create-timebank-tables.sql           # Script de criação das tabelas
├── DOCUMENTACAO_ABATIMENTO_BANCO_HORAS.md   # Documentação técnica completa
└── README_BANCO_DE_HORAS.md                 # Este arquivo
```

---

## 🔧 TROUBLESHOOTING

### Problema: "Configuração de pagamento não encontrada"

**Solução:** Configure os valores de pagamento do funcionário primeiro (veja seção Configuração Inicial).

### Problema: "Saldo insuficiente"

**Solução:** Verifique o saldo real do funcionário em **Time Tracking > Folha de Ponto**.

### Problema: Modal não abre

**Solução:**
1. Limpe cache do navegador (Ctrl+Shift+Del)
2. Verifique console do navegador (F12) para erros JavaScript
3. Verifique se o arquivo `timebank-manager.js` foi carregado

### Problema: Prévia de pagamento mostra R$ 0,00

**Solução:**
1. Verifique se o funcionário tem configuração de pagamento
2. Verifique se há dias com saldo positivo no período selecionado
3. Confirme que `valor_hora_base` não está zerado

---

## 📚 DOCUMENTAÇÃO ADICIONAL

Para detalhes técnicos completos, consulte:
- **`DOCUMENTACAO_ABATIMENTO_BANCO_HORAS.md`** - Documentação técnica completa com:
  - Modelagem de banco de dados detalhada
  - Estrutura das funções PHP
  - Fluxogramas de validação
  - Exemplos de código
  - API REST disponível

---

## 🎯 ROADMAP FUTURO

### Melhorias Planejadas

- [ ] **Relatórios Exportáveis:** PDF e Excel
- [ ] **Notificações:** Email quando houver abatimento pendente
- [ ] **Aprovação em Massa:** Aprovar múltiplos abatimentos de uma vez
- [ ] **Integração Contábil:** Exportar para sistemas de folha de pagamento
- [ ] **Regras Automáticas:** Auto-aprovar valores abaixo de X reais
- [ ] **App Mobile:** Funcionários solicitarem folgas pelo celular
- [ ] **Dashboard Analítico:** Gráficos de evolução do banco de horas

---

## 🆘 SUPORTE

Em caso de dúvidas ou problemas:

1. Consulte a **documentação técnica** em `DOCUMENTACAO_ABATIMENTO_BANCO_HORAS.md`
2. Verifique os **logs de auditoria** em **SISTUR > Auditoria**
3. Entre em contato com o administrador do sistema

---

## 📝 CHANGELOG

### Versão 1.5.0 (2025-11-28)
- ✨ **NOVO:** Sistema completo de abatimento de banco de horas
- ✨ **NOVO:** Compensação em folga
- ✨ **NOVO:** Pagamento de horas extras com cálculo automático
- ✨ **NOVO:** Sistema de aprovação de abatimentos
- ✨ **NOVO:** Gestão de feriados e adicionais
- ✨ **NOVO:** Configuração de pagamento por funcionário
- ✨ **NOVO:** Dashboard de abatimentos
- ✨ **NOVO:** 4 novas permissões específicas
- 📊 **MELHORIA:** Cálculo diferenciado por tipo de dia (útil/FDS/feriado)
- 📊 **MELHORIA:** Auditoria completa de todas as operações

---

## 📜 LICENÇA

Este sistema é parte do plugin SISTUR e segue a mesma licença GPL-2.0+

---

**Desenvolvido com ❤️ para o SISTUR - Sistema de Turismo**
