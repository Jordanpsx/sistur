# 🔧 Sistema de Diagnóstico e Correção - Produção

## 📌 Resumo Executivo

Sistema completo de ferramentas para diagnosticar e corrigir problemas na criação de funcionários em produção.

**Status:** ✅ **PRONTO PARA USO EM PRODUÇÃO**

---

## 🎯 Problema Resolvido

**Situação:** Tabela `wp_sistur_employees` existe mas com estrutura antiga/incompleta
- ❌ Falta coluna `cpf` → Erro: "Unknown column 'cpf' in 'SELECT'"
- ❌ Falta coluna `password` → Impossível criar funcionários com login
- ❌ Falta coluna `role_id` → Impossível atribuir permissões
- ❌ Outras 9+ colunas faltantes

**Solução:** Ferramenta automática que detecta e adiciona todas as colunas faltantes

---

## 🚀 Acesso Rápido

```
URL: /wp-admin/admin.php?page=sistur-diagnostics
Menu: SISTUR → 🔧 Diagnóstico
```

---

## 🛠️ Ferramentas Disponíveis

### 1️⃣ Validar Ambiente de Produção 🔍
**O que faz:**
- Verifica 10 pontos críticos do sistema
- Detecta colunas faltantes na tabela
- Identifica arquivos ausentes
- Verifica permissões e configurações

**Como usar:**
1. Clique em "🔍 Validar Ambiente de Produção"
2. Aguarde a análise completa
3. Verifique a tabela de resultados
4. Preste atenção aos itens vermelhos (✗)

**Resultado esperado:**
- ✓ Verde = OK
- ⚠ Laranja = Aviso (não crítico)
- ✗ Vermelho = Erro (precisa corrigir)

---

### 2️⃣ Testar Criação de Funcionário 🧪
**O que faz:**
- Simula a criação completa de um funcionário
- **NÃO grava nada no banco** (apenas teste)
- Mostra log detalhado de todas as 10 etapas
- Identifica exatamente onde falha

**Como usar:**
1. Clique em "🧪 Testar Criação de Funcionário"
2. Aguarde a simulação
3. Analise o log em estilo terminal
4. Verifique se passou (✓) ou falhou (✗)

**Resultado esperado:**
```
✓✓✓ SUCESSO! Todos os requisitos foram atendidos.
✓ Em produção, o funcionário SERIA criado com sucesso.
```

---

### 3️⃣ Adicionar Colunas Faltantes 🔧
**O que faz:**
- Detecta automaticamente quais colunas estão faltando
- Adiciona TODAS as colunas via ALTER TABLE
- Cria índices necessários
- **100% SEGURO** - não apaga nada

**Como usar:**
1. Execute "Validar Ambiente" primeiro
2. Se aparecer colunas faltantes, clique em "🔧 Adicionar Colunas Faltantes"
3. Confirme a operação
4. Aguarde a conclusão (2-5 segundos)
5. Sistema re-valida automaticamente

**Resultado esperado:**
```
✓ Correção Concluída com Sucesso!
Colunas adicionadas: 12
A estrutura da tabela foi atualizada.
```

---

## 📋 Guia Passo a Passo

### Cenário: "Não consigo criar funcionários"

```
PASSO 1: Acessar Diagnóstico
↓
PASSO 2: Clicar "Validar Ambiente"
↓
PASSO 3: Verificar resultados
        │
        ├─ Se tudo VERDE → Testar criação real
        │
        └─ Se VERMELHO (colunas faltantes) →
           │
           PASSO 4: Clicar "Adicionar Colunas Faltantes"
           ↓
           PASSO 5: Confirmar operação
           ↓
           PASSO 6: Aguardar conclusão
           ↓
           PASSO 7: Verificar re-validação automática
           ↓
           PASSO 8: Tudo deve estar VERDE agora
           ↓
           PASSO 9: Testar criação real de funcionário

✅ SUCESSO!
```

---

## 📊 Estrutura Completa da Tabela

A ferramenta garante que estas **24 colunas** existam:

### Colunas Principais
- `id` - Identificador único
- `user_id` - Link com usuário WordPress
- `name` - Nome completo
- `email` - E-mail
- `phone` - Telefone
- `status` - Status (ativo/inativo)

### Colunas Críticas ⚠️
- `cpf` - CPF (único, indexado)
- `password` - Senha hash
- `role_id` - Papel/função (permissões)

### Documentação
- `matricula` - Matrícula
- `ctps` - Carteira de Trabalho
- `ctps_uf` - UF da CTPS
- `cbo` - Código CBO

### Departamento e Cargo
- `department_id` - Departamento
- `position` - Cargo
- `contract_type_id` - Tipo de contrato

### QR Code e Ponto
- `token_qr` - Token UUID (único)
- `perfil_localizacao` - INTERNO/EXTERNO
- `time_expected_minutes` - Horas esperadas (padrão: 480 = 8h)
- `lunch_minutes` - Almoço (padrão: 60 = 1h)

### Adicionais
- `photo` - URL da foto
- `bio` - Biografia
- `hire_date` - Data de admissão
- `created_at` - Data de criação
- `updated_at` - Última atualização

---

## 🔍 Logs Detalhados

Todos os processos geram logs automáticos em `wp-content/debug.log`:

### Formato dos Logs
```
========== SISTUR DEBUG: ajax_save_employee INICIADO ==========
SISTUR DEBUG: Timestamp: 2024-11-25 12:00:00
SISTUR DEBUG: User ID: 1
SISTUR DEBUG: Tabela: wp_sistur_employees
SISTUR DEBUG: ✓ Tabela existe
SISTUR DEBUG: ✓ Nonce validado com sucesso
SISTUR DEBUG: ✓ Permissões validadas com sucesso
SISTUR DEBUG: ✓ Nome validado
SISTUR DEBUG: ✓ CPF validado: 12345678901
SISTUR DEBUG: ✓ Senha fornecida para novo funcionário
SISTUR DEBUG: ✓ CPF não duplicado
SISTUR DEBUG: Executando INSERT para novo funcionário
SISTUR DEBUG: ✓ INSERT executado com sucesso!
SISTUR DEBUG: Funcionário ID 123 criado com sucesso
========== SISTUR DEBUG: ajax_save_employee FINALIZADO COM SUCESSO ==========
```

### Como Visualizar
```bash
# Via SSH
tail -100 wp-content/debug.log | grep "SISTUR DEBUG"

# Via FTP
Baixe: wp-content/debug.log
Abra em editor de texto
Procure por: "SISTUR DEBUG"
```

---

## ⚠️ Avisos de Segurança

### ✅ Operações Seguras
- Validar ambiente
- Testar criação (simulação)
- Adicionar colunas faltantes

### ⚠️ Fazer com Cuidado
- Sempre faça backup do banco antes
- Execute em horário de baixo tráfego
- Teste primeiro em staging se disponível

### 🚫 NUNCA Fazer
- `DROP TABLE` (apaga tudo!)
- `TRUNCATE` (apaga todos os registros!)
- Deletar colunas manualmente sem saber
- Modificar estrutura sem backup

---

## 🐛 Resolução de Problemas

### Problema: "Nonce inválido"
**Causa:** Sessão expirada  
**Solução:** Recarregue a página (Ctrl+F5)

### Problema: "Permissão negada"
**Causa:** Usuário não é administrador  
**Solução:** Faça login com conta admin

### Problema: "Tabela não existe"
**Causa:** Plugin não foi ativado corretamente  
**Solução:** Desative e reative o plugin

### Problema: "CPF duplicado"
**Causa:** CPF já existe no sistema  
**Solução:** Verifique funcionários existentes

### Problema: "Arquivo de validação não encontrado"
**Causa:** Arquivo `login-funcionario-new.php` não foi enviado  
**Solução:** Re-envie todos os arquivos do plugin via FTP

### Problema: Mesmo após corrigir, continua dando erro
**Causa:** Cache  
**Solução:**
1. Limpe cache do navegador (Ctrl+Shift+Del)
2. Limpe cache do WordPress (plugin de cache)
3. Limpe cache do servidor (se houver)
4. Teste em janela anônima

---

## 📚 Documentos Relacionados

- `DEBUG_CRIACAO_FUNCIONARIOS.md` - Logs e troubleshooting
- `CORRIGIR_TABELA_FUNCIONARIOS.md` - Guia completo de correção
- `INSTRUCOES_DEBUG.md` - Instruções gerais de debug

---

## 📞 Checklist de Suporte

Antes de pedir ajuda, verifique:

- [ ] Executei "Validar Ambiente"?
- [ ] Executei "Adicionar Colunas Faltantes"?
- [ ] Executei "Testar Criação"?
- [ ] Verifiquei os logs em debug.log?
- [ ] Limpei cache do navegador?
- [ ] Testei com outro navegador?
- [ ] Fiz backup do banco de dados?
- [ ] Tentei desativar/reativar o plugin?

Se sim para tudo acima e ainda não funciona, colete:
1. Screenshot da validação de ambiente
2. Screenshot do teste de criação
3. Últimas 100 linhas do debug.log (filtradas por SISTUR)
4. Versão WordPress, PHP, provedor

---

## 🎉 Sucesso!

Após seguir os passos:

✅ Todas as colunas existem  
✅ Validação passa  
✅ Teste de criação passa  
✅ Consegue criar funcionários normalmente  
✅ Logs mostram sucesso  
✅ Sem erros SQL  

**Parabéns! Sistema está 100% funcional em produção! 🚀**

---

**Versão:** 1.0  
**Data:** 2024-11-25  
**Autor:** Sistema SISTUR  
**Status:** ✅ Produção
