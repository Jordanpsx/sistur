# 📱 Opções de Geração de QR Code - SISTUR

O sistema SISTUR agora oferece **3 métodos** de geração de QR codes, escolhidos automaticamente conforme disponibilidade:

---

## 🎯 Comparação das Opções

| Método | Composer | Internet | Qualidade | Deploy | Recomendado |
|--------|----------|----------|-----------|--------|-------------|
| **1. endroid/qr-code** | ✅ Sim | ❌ Não | ⭐⭐⭐⭐⭐ | Complexo | Produção |
| **2. API Externa** | ❌ Não | ✅ Sim | ⭐⭐⭐⭐ | Simples | Desenvolvimento |
| **3. JavaScript** | ❌ Não | ❌ Não | ⭐⭐⭐ | Médio | Alternativa |

---

## 📋 Detalhamento das Opções

### **Opção 1: endroid/qr-code (Composer)** ⭐ **RECOMENDADO PARA PRODUÇÃO**

**Como funciona:**
- Biblioteca PHP profissional instalada via Composer
- Geração 100% offline no servidor
- Melhor qualidade e customização

**Prós:**
- ✅ Não depende de internet
- ✅ Mais rápido (geração local)
- ✅ Melhor qualidade de imagem
- ✅ Controle total sobre geração
- ✅ Sem limites de requisições

**Contras:**
- ❌ Requer Composer instalado
- ❌ Requer acesso SSH (geralmente)
- ❌ Deploy mais complexo

**Quando usar:**
- Servidor de produção com SSH
- Hospedagem VPS/Cloud
- Projetos profissionais

**Como ativar:**
```bash
cd /caminho/para/wp-content/plugins/sistur
composer install --no-dev
```

---

### **Opção 2: API Externa (Fallback)** ✅ **JÁ ATIVO**

**Como funciona:**
- Usa APIs gratuitas e confiáveis (qrserver.com, goqr.me)
- Download da imagem gerada pela API
- Fallback automático entre APIs

**Prós:**
- ✅ Funciona SEM Composer
- ✅ Funciona SEM SSH
- ✅ Zero configuração
- ✅ Deploy simples (Git push)
- ✅ Compatível com qualquer hospedagem PHP

**Contras:**
- ❌ Requer conexão internet
- ❌ Dependente de serviço externo
- ❌ Limite de requisições (improvável atingir)
- ❌ Ligeiramente mais lento

**Quando usar:**
- Hospedagem compartilhada sem SSH
- Desenvolvimento local (XAMPP/WAMP)
- Testes rápidos
- Quando Composer não está disponível

**Como ativar:**
- **Já está ativo!** Funciona automaticamente se Composer não estiver instalado

**APIs utilizadas:**
- Primária: https://api.qrserver.com/
- Fallback: https://api.goqr.me/
- Ambas: Gratuitas, confiáveis, sem autenticação

---

### **Opção 3: JavaScript (Cliente)** 🔄 **EM DESENVOLVIMENTO**

**Como funciona:**
- Biblioteca JavaScript gera QR code no navegador
- Usuário baixa imagem gerada localmente

**Prós:**
- ✅ Zero dependências servidor
- ✅ Não usa internet (após carregar página)
- ✅ Carga zero no servidor

**Contras:**
- ❌ Não salva no servidor
- ❌ Usuário precisa baixar manualmente
- ❌ Menos integrado

**Status:** Não implementado (pode ser adicionado se necessário)

---

## 🚀 Sistema Automático de Fallback

O sistema detecta automaticamente qual método usar:

```
┌─────────────────────────────────────────┐
│  Tentativa 1: endroid/qr-code           │
│  ✓ Composer instalado?                  │
│  └─> SIM: Usar (melhor qualidade)       │
│  └─> NÃO: Próxima opção ↓               │
├─────────────────────────────────────────┤
│  Tentativa 2: API Externa               │
│  ✓ Servidor tem internet?               │
│  └─> SIM: Usar (funciona bem)           │
│  └─> NÃO: Erro (aviso ao admin)         │
└─────────────────────────────────────────┘
```

---

## 📊 Indicadores na Interface

Quando você acessa **RH > QR Codes dos Funcionários**, vê:

### **Verde** - Modo Offline (Composer)
```
✓ Geração de QR Codes: Modo Offline (Composer) - Funcionando perfeitamente!
```
**Significa:** Usando endroid/qr-code (melhor opção)

### **Azul** - Modo Fallback (API)
```
⚠ Geração de QR Codes: Modo Fallback
  Usando API externa para gerar QR codes (sem Composer)
  ✓ Funcionando normalmente
  ⚠ Requer conexão com internet
  💡 Para geração offline, instale: composer install --no-dev
```
**Significa:** Usando API externa (funciona, mas recomenda-se Composer)

### **Vermelho** - Erro
```
✗ Erro Crítico: Geração de QR Codes Desabilitada
  Nenhum método de geração disponível!
```
**Significa:** Algo deu errado (arquivo faltando)

---

## 🎓 Qual Escolher?

### Para Produção Profissional:
➡️ **Use Opção 1 (Composer)**
- Melhor performance
- Mais confiável
- Não depende de terceiros

### Para Desenvolvimento/Testes:
➡️ **Use Opção 2 (API - já ativa)**
- Mais simples
- Funciona imediatamente
- Sem configuração

### Para Hospedagem Compartilhada Básica:
➡️ **Use Opção 2 (API - já ativa)**
- Única opção viável sem SSH
- Funciona perfeitamente para uso moderado

---

## ❓ FAQ

### "Minha hospedagem não tem SSH, vai funcionar?"
**SIM!** A Opção 2 (API) funciona sem SSH. Está ativa automaticamente.

### "Preciso fazer alguma configuração?"
**NÃO!** O sistema escolhe automaticamente o melhor método disponível.

### "E se a API externa sair do ar?"
O sistema tenta 2 APIs diferentes. Se ambas falharem, mostra erro detalhado nos logs.

### "Posso forçar usar sempre a API externa?"
Sim, basta não instalar o Composer. O sistema detecta e usa automaticamente.

### "Quantos QR codes posso gerar?"
- **Composer:** Ilimitado
- **API:** ~1000/dia por API (improvável atingir em uso normal)

---

## 🔧 Mudando Entre Métodos

### Para ATIVAR Opção 1 (Composer):
```bash
cd /caminho/para/wp-content/plugins/sistur
composer install --no-dev
```

### Para ATIVAR Opção 2 (API):
**Já está ativa!** Funciona automaticamente se Opção 1 não estiver disponível.

### Para DESATIVAR Opção 1 (voltar para API):
```bash
cd /caminho/para/wp-content/plugins/sistur
rm -rf vendor/
```

---

## 📝 Logs e Debugging

Os erros são registrados em:
- WordPress: `wp-content/debug.log`
- Mensagens típicas:
  - `SISTUR QR Code Error (endroid): ...` - Erro na Opção 1
  - `SISTUR QR Code Error: Falha ao gerar QR code via API externa` - Erro na Opção 2

---

## ✅ Conclusão

**Para a maioria dos usuários:**
- A **Opção 2 (API)** que já está ativa é suficiente
- Funciona bem, é confiável e não requer configuração
- Recomenda-se migrar para Opção 1 em produção quando possível

**Desenvolvido por:** WebFluence
**Versão:** 1.4.2
**Última atualização:** 21 de Novembro de 2025
