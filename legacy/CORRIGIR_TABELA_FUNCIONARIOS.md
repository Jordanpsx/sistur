# 🔧 Como Corrigir Tabela de Funcionários Incompleta

## 🚨 Problema Identificado

A tabela `wp_sistur_employees` existe mas está com estrutura **ANTIGA/INCOMPLETA**, faltando colunas essenciais como:
- `cpf` ⚠️ **CRÍTICA**
- `password` ⚠️ **CRÍTICA**
- `role_id` ⚠️ **CRÍTICA**
- `matricula`
- `ctps`, `ctps_uf`, `cbo`
- `token_qr`, `contract_type_id`
- E outras...

**Erro SQL resultante:**
```
Unknown column 'cpf' in 'SELECT'
```

---

## ✅ Solução Automática (RECOMENDADO)

### Passo 1: Acessar Diagnóstico
1. Faça login no WordPress admin em produção
2. Navegue para: **SISTUR → 🔧 Diagnóstico**
3. Ou acesse diretamente: `/wp-admin/admin.php?page=sistur-diagnostics`

### Passo 2: Validar Ambiente
1. Na seção **"👥 Diagnóstico de Criação de Funcionários"**
2. Clique em **"🔍 Validar Ambiente de Produção"**
3. Aguarde a verificação completa

### Passo 3: Verificar Resultados
Na tabela de resultados, procure pela linha **"Estrutura da tabela"**:

**Se estiver VERMELHO (✗ Incompleta):**
```
Estrutura da tabela
✗ Incompleta
Faltam 12 coluna(s): cpf, matricula, password, role_id, token_qr...
⚠ CRÍTICAS: cpf, password, role_id
Use o botão 'Adicionar Colunas Faltantes' abaixo
```

### Passo 4: Corrigir Automaticamente
1. Role a página até a seção **"3. Corrigir Estrutura da Tabela"**
2. Clique no botão **"🔧 Adicionar Colunas Faltantes"**
3. Confirme a operação
4. Aguarde a conclusão (geralmente leva 2-5 segundos)

### Passo 5: Verificar Correção
1. O sistema executará a validação automaticamente após 2 segundos
2. Verifique se agora aparece **"✓ OK"** na linha "Estrutura da tabela"
3. Todas as colunas devem estar presentes

### Passo 6: Testar Criação
1. Clique em **"🧪 Testar Criação de Funcionário"**
2. Verifique se o teste passa (✓ SUCESSO)
3. Tente criar um funcionário real na interface normal

---

## 🔍 O Que a Ferramenta Faz

A ferramenta automática:

### ✅ Detecta
- Quais colunas existem
- Quais colunas estão faltando
- Prioridade de cada coluna (crítica ou não)

### ✅ Adiciona
- **Todas** as colunas faltantes via `ALTER TABLE`
- Índices necessários (cpf, token_qr, role_id, etc.)
- Valores padrão apropriados
- Constraints e relacionamentos

### ✅ Preserva
- **100% dos dados existentes** (não apaga nada)
- Estrutura original da tabela
- Funcionários já cadastrados
- Relacionamentos com outras tabelas

---

## 📋 Log de Exemplo

Quando você clicar em "Adicionar Colunas Faltantes", verá um log detalhado:

```
Verificando estrutura da tabela wp_sistur_employees...

Colunas existentes: id, name, email, phone, department_id, position, photo, bio, hire_date, status

✓ Coluna OK: id
❌ Coluna FALTANTE: user_id
   Adicionando...
   ✓ Coluna adicionada com sucesso!
   ✓ Índice adicionado!

❌ Coluna FALTANTE: cpf
   Adicionando...
   ✓ Coluna adicionada com sucesso!
   ✓ Índice adicionado!

❌ Coluna FALTANTE: password
   Adicionando...
   ✓ Coluna adicionada com sucesso!

❌ Coluna FALTANTE: role_id
   Adicionando...
   ✓ Coluna adicionada com sucesso!
   ✓ Índice adicionado!

[... continua para todas as colunas ...]

✓ Correção Concluída com Sucesso!
Colunas adicionadas: 12
A estrutura da tabela foi atualizada. Todas as funcionalidades devem funcionar agora.
```

---

## 🛠️ Solução Manual (Alternativa)

Se preferir fazer manualmente via SQL:

### Via PhpMyAdmin
1. Acesse PhpMyAdmin
2. Selecione o banco de dados do WordPress
3. Encontre a tabela `wp_sistur_employees`
4. Clique na aba "SQL"
5. Execute o script abaixo

### Script SQL Completo
```sql
-- Adicionar colunas faltantes na tabela de funcionários

-- user_id - Link com usuários WordPress
ALTER TABLE wp_sistur_employees 
ADD COLUMN user_id bigint(20) DEFAULT NULL AFTER id,
ADD UNIQUE KEY user_id (user_id);

-- CPF (CRÍTICO)
ALTER TABLE wp_sistur_employees 
ADD COLUMN cpf varchar(14) DEFAULT NULL AFTER hire_date,
ADD UNIQUE KEY cpf_unique (cpf),
ADD KEY cpf (cpf);

-- Matrícula e documentos
ALTER TABLE wp_sistur_employees 
ADD COLUMN matricula varchar(50) DEFAULT NULL AFTER cpf,
ADD COLUMN ctps varchar(20) DEFAULT NULL AFTER matricula,
ADD COLUMN ctps_uf varchar(2) DEFAULT NULL AFTER ctps,
ADD COLUMN cbo varchar(10) DEFAULT NULL AFTER ctps_uf,
ADD KEY matricula (matricula);

-- Password (CRÍTICO)
ALTER TABLE wp_sistur_employees 
ADD COLUMN password varchar(255) DEFAULT NULL AFTER matricula;

-- Role ID (CRÍTICO)
ALTER TABLE wp_sistur_employees 
ADD COLUMN role_id mediumint(9) DEFAULT NULL AFTER password,
ADD KEY role_id (role_id);

-- Token QR Code
ALTER TABLE wp_sistur_employees 
ADD COLUMN token_qr varchar(36) DEFAULT NULL AFTER password,
ADD UNIQUE KEY token_qr (token_qr),
ADD KEY token_qr_idx (token_qr);

-- Tipo de contrato e localização
ALTER TABLE wp_sistur_employees 
ADD COLUMN contract_type_id mediumint(9) DEFAULT NULL AFTER token_qr,
ADD COLUMN perfil_localizacao enum('INTERNO','EXTERNO') DEFAULT 'INTERNO' AFTER contract_type_id,
ADD KEY contract_type_id (contract_type_id);

-- Tempo esperado e almoço
ALTER TABLE wp_sistur_employees 
ADD COLUMN time_expected_minutes smallint DEFAULT 480 AFTER perfil_localizacao,
ADD COLUMN lunch_minutes smallint DEFAULT 60 AFTER time_expected_minutes;

-- Timestamps
ALTER TABLE wp_sistur_employees 
ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP AFTER lunch_minutes,
ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
```

---

## ⚠️ Avisos Importantes

### ✅ Seguro
- Esta operação **NÃO apaga dados**
- Apenas **ADICIONA** colunas
- É **100% reversível** (pode remover colunas depois se quiser)
- Não afeta funcionários já cadastrados

### ⚠️ Atenção
- Faça backup do banco antes (boa prática)
- Execute em horário de baixo tráfego se possível
- A operação pode levar alguns segundos em tabelas grandes
- Não interrompa o processo no meio

### 🚫 NÃO Fazer
- **NÃO** use `DROP TABLE` (vai perder todos os dados!)
- **NÃO** delete a tabela manualmente
- **NÃO** use `TRUNCATE` (apaga todos os registros!)

---

## 🔍 Verificação Pós-Correção

Após corrigir, verifique:

1. **Validar Ambiente** novamente
   - Estrutura da tabela: ✓ OK
   - Todas as colunas existem

2. **Testar Criação** (simulação)
   - Teste deve passar: ✓ SUCESSO

3. **Criar funcionário real**
   - Acesse: SISTUR → Funcionários → Adicionar Novo
   - Preencha os dados
   - Clique em Salvar
   - Deve aparecer: "Funcionário criado com sucesso!"

4. **Verificar logs** (se ainda falhar)
   - Verifique `wp-content/debug.log`
   - Procure por `SISTUR DEBUG ERRO`

---

## 📞 Suporte

Se após seguir todos os passos o problema persistir:

1. **Capture evidências:**
   - Screenshot da validação de ambiente
   - Screenshot do log de correção
   - Últimas 50 linhas do debug.log

2. **Informações do ambiente:**
   - Versão WordPress
   - Versão PHP
   - Provedor de hospedagem
   - Versão do plugin SISTUR

3. **Tente primeiro:**
   - Desativar e reativar o plugin
   - Limpar cache do navegador
   - Testar com outro navegador
   - Testar com outro usuário admin

---

## 🎯 Resumo Rápido

```
1. Acesse: /wp-admin/admin.php?page=sistur-diagnostics
2. Clique: "Validar Ambiente de Produção"
3. Se aparecer colunas faltantes, clique: "Adicionar Colunas Faltantes"
4. Confirme e aguarde
5. Verifique se ficou verde (✓ OK)
6. Teste criar um funcionário
7. Pronto! ✓
```

---

**Última atualização:** 2024-11-25  
**Versão do documento:** 1.0
