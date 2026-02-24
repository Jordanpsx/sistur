# 🔧 Ferramentas de Debug - Criação de Funcionários em Produção

Este documento descreve como usar as ferramentas de debug criadas para diagnosticar problemas na criação de funcionários em produção.

## 📍 Onde Acessar

Acesse o painel administrativo do WordPress em produção:

```
/wp-admin/admin.php?page=sistur-diagnostics
```

Ou navegue pelo menu:
**SISTUR → 🔧 Diagnóstico**

## 🛠️ Ferramentas Disponíveis

### 1. Validar Ambiente de Produção

**Localização:** Seção "👥 Diagnóstico de Criação de Funcionários"

**O que faz:**
- Verifica se a tabela `wp_sistur_employees` existe
- Verifica se o arquivo `login-funcionario-new.php` está presente
- Verifica se a função `sistur_validate_cpf()` está disponível
- Verifica se a classe `SISTUR_QRCode` existe
- Verifica permissões do usuário
- Verifica estrutura da tabela (colunas necessárias)
- Verifica índices e CPFs duplicados
- Mostra último erro SQL (se houver)

**Como usar:**
1. Clique no botão **"🔍 Validar Ambiente de Produção"**
2. Aguarde a verificação completa
3. Analise os resultados na tabela exibida
4. Se houver problemas, siga as recomendações apresentadas

**Interpretação dos Resultados:**
- ✓ Verde = OK, funcionando corretamente
- ⚠ Laranja = Aviso, não crítico mas requer atenção
- ✗ Vermelho = Erro crítico, precisa ser corrigido

---

### 2. Testar Criação de Funcionário (Simulação)

**Localização:** Seção "👥 Diagnóstico de Criação de Funcionários"

**O que faz:**
- Simula todo o processo de criação de funcionário
- **NÃO cria nenhum funcionário de verdade**
- Executa todas as validações sem inserir dados
- Mostra logs detalhados de cada etapa

**Como usar:**
1. Clique no botão **"🧪 Testar Criação de Funcionário"**
2. Aguarde a simulação completa
3. Analise o log em estilo terminal (fundo preto, texto verde)
4. Verifique se o teste passou ou falhou

**O que o teste verifica:**
- [1/10] Tabela de funcionários existe
- [2/10] Arquivo de validação de CPF existe
- [3/10] Função de validação de CPF funciona
- [4/10] Verificação de CPF duplicado
- [5/10] Preparação de dados (sanitização)
- [6/10] Query SQL que seria executada
- [7/10] Classe de QR Code disponível
- [8/10] Permissões do usuário
- [9/10] Informações do ambiente
- [10/10] Resumo final

---

## 📊 Logs Detalhados em Produção

### Como Visualizar os Logs

Os logs de produção são salvos no arquivo de error log do WordPress. Para visualizá-los:

#### Opção 1: Via FTP/cPanel
1. Acesse seu servidor via FTP ou cPanel
2. Navegue até a pasta raiz do WordPress
3. Procure pelo arquivo `wp-content/debug.log`
4. Baixe e abra em um editor de texto

#### Opção 2: Via SSH
```bash
tail -f wp-content/debug.log | grep "SISTUR DEBUG"
```

#### Opção 3: Via Plugin
Use um plugin como "WP Log Viewer" para visualizar logs diretamente no admin

### Formato dos Logs

Todos os logs de criação de funcionários seguem este padrão:

```
========== SISTUR DEBUG: ajax_save_employee INICIADO ==========
SISTUR DEBUG: Timestamp: 2024-01-15 14:30:45
SISTUR DEBUG: User ID: 1
SISTUR DEBUG: User IP: 192.168.1.100
SISTUR DEBUG: POST data recebido: Array(...)
SISTUR DEBUG: ✓ Nonce validado com sucesso
SISTUR DEBUG: ✓ Permissões validadas com sucesso
SISTUR DEBUG: Tabela: wp_sistur_employees
SISTUR DEBUG: ✓ Tabela existe
SISTUR DEBUG: Dados sanitizados:
SISTUR DEBUG:   - ID: 0
SISTUR DEBUG:   - Nome: João Silva
SISTUR DEBUG:   - Email: joao@exemplo.com
SISTUR DEBUG:   - CPF: 12345678901
SISTUR DEBUG:   - Senha fornecida: SIM
SISTUR DEBUG: ✓ Nome validado
SISTUR DEBUG: Iniciando validação de CPF...
SISTUR DEBUG: ✓ Função sistur_validate_cpf carregada
SISTUR DEBUG: ✓ CPF validado: 12345678901
SISTUR DEBUG: ✓ Senha fornecida para novo funcionário
SISTUR DEBUG: Verificando CPF duplicado...
SISTUR DEBUG: ✓ CPF não duplicado
SISTUR DEBUG: Executando INSERT para novo funcionário
SISTUR DEBUG: Resultado do INSERT: 1
SISTUR DEBUG: Insert ID: 123
SISTUR DEBUG: ✓ INSERT executado com sucesso!
SISTUR DEBUG: Funcionário ID 123 criado com sucesso
========== SISTUR DEBUG: ajax_save_employee FINALIZADO COM SUCESSO ==========
```

### Erros Comuns e Como Identificá-los

#### Erro 1: Tabela não existe
```
SISTUR DEBUG ERRO CRÍTICO: Tabela não existe: wp_sistur_employees
```
**Solução:** Execute a ativação do plugin ou use "Recriar Tabelas Faltantes"

#### Erro 2: Arquivo de validação CPF não encontrado
```
SISTUR DEBUG ERRO CRÍTICO: Arquivo não encontrado: /path/to/includes/login-funcionario-new.php
```
**Solução:** Verifique se todos os arquivos do plugin foram enviados corretamente

#### Erro 3: Função de validação não disponível
```
SISTUR DEBUG ERRO: Função não foi carregada do arquivo
```
**Solução:** Verifique o conteúdo do arquivo `login-funcionario-new.php`

#### Erro 4: CPF inválido
```
SISTUR DEBUG ERRO: CPF inválido: 12345678900
```
**Solução:** Usuário digitou CPF inválido, pedir para corrigir

#### Erro 5: CPF duplicado
```
SISTUR DEBUG ERRO: CPF duplicado encontrado (ID existente: 45)
```
**Solução:** CPF já existe no sistema, verificar duplicata

#### Erro 6: Nonce inválido
```
SISTUR DEBUG ERRO: Nonce inválido. Nonce recebido: abc123
```
**Solução:** Sessão expirada, usuário precisa recarregar a página

#### Erro 7: Sem permissão
```
SISTUR DEBUG ERRO: Usuário sem permissão manage_options
```
**Solução:** Usuário não é administrador, verificar permissões

#### Erro 8: INSERT falhou
```
SISTUR DEBUG ERRO CRÍTICO: INSERT falhou!
SISTUR DEBUG: wpdb->last_error: [erro SQL detalhado]
```
**Solução:** Verificar o erro SQL específico para diagnosticar

---

## 🔍 Cenários de Uso

### Cenário 1: "Não consigo criar funcionários em produção"

**Passos:**
1. Acesse a página de diagnóstico
2. Execute "Validar Ambiente de Produção"
3. Verifique se há problemas críticos (✗ vermelho)
4. Corrija os problemas apontados
5. Execute "Testar Criação de Funcionário"
6. Se o teste passar, tente criar um funcionário real
7. Se ainda falhar, verifique os logs do error.log

### Cenário 2: "Funcionários criados no localhost, mas não na produção"

**Possíveis causas:**
1. Arquivo `login-funcionario-new.php` não foi enviado para produção
2. Permissões de arquivo diferentes
3. Configuração de banco de dados diferente
4. Plugin não foi reativado após deploy

**Solução:**
1. Execute "Validar Ambiente" em produção
2. Compare com localhost
3. Identifique as diferenças
4. Corrija os arquivos/configurações faltantes

### Cenário 3: "Erro intermitente ao criar funcionários"

**Possíveis causas:**
1. Race condition em CPF duplicado
2. Timeout de sessão/nonce
3. Problemas de performance do servidor

**Solução:**
1. Verifique os logs para identificar o padrão
2. Se for nonce: orientar usuários a recarregar antes de salvar
3. Se for CPF: verificar duplicatas no banco
4. Se for timeout: otimizar servidor ou aumentar limites

---

## 📝 Checklist de Troubleshooting

Use este checklist ao investigar problemas:

- [ ] Acessei a página de diagnóstico
- [ ] Executei "Validar Ambiente de Produção"
- [ ] Todos os itens estão verdes (✓)?
- [ ] Executei "Testar Criação de Funcionário"
- [ ] O teste passou?
- [ ] Verifiquei o arquivo debug.log
- [ ] Procurei por erros SISTUR DEBUG ERRO
- [ ] Identifiquei a causa raiz do problema
- [ ] Apliquei a correção necessária
- [ ] Testei novamente

---

## 🚨 Quando Entrar em Contato com Suporte

Se após usar todas as ferramentas o problema persistir, colete estas informações:

1. **Screenshot da página "Validar Ambiente de Produção"**
2. **Screenshot do "Testar Criação de Funcionário"**
3. **Últimas 100 linhas do debug.log** (filtrando por SISTUR)
4. **Informações do ambiente:**
   - Versão do WordPress
   - Versão do PHP
   - Versão do plugin SISTUR
   - Provedor de hospedagem

---

## ⚙️ Ativando WP_DEBUG Temporariamente

Para obter mais detalhes em produção (fazer apenas temporariamente):

1. Edite o arquivo `wp-config.php`
2. Localize a linha `define('WP_DEBUG', false);`
3. Altere para:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```
4. Tente criar o funcionário novamente
5. Verifique o arquivo `wp-content/debug.log`
6. **IMPORTANTE:** Desative o WP_DEBUG após investigar:
```php
define('WP_DEBUG', false);
```

---

## 📚 Recursos Adicionais

- **Logs do servidor:** Verificar logs do Apache/Nginx para erros PHP
- **PhpMyAdmin:** Verificar estrutura da tabela manualmente
- **Query Monitor:** Plugin que mostra queries SQL em tempo real
- **Error Log:** Sempre verificar `wp-content/debug.log` primeiro

---

**Última atualização:** 2024-11-25  
**Versão do documento:** 1.0
