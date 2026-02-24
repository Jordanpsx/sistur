# Módulo CMV / Gestão de Estoque

> **Versão:** 2.17.0
> **Última atualização:** 2026-02-19
> **Pacote:** SISTUR → Stock

Documentação técnica do módulo de Custo de Mercadoria Vendida (CMV) e Gestão de Estoque do plugin SISTUR.

---

## Visão Geral

O módulo CMV/Estoque é um sistema completo para gerenciamento de estoque de restaurante/estabelecimento, com:

- **Controle por lotes** com rastreabilidade
- **FIFO automático** para saídas (consome lotes mais antigos primeiro)
- **Custo médio ponderado** calculado automaticamente
- **Conversão de unidades** (ex: caixa → unidade, kg → g)
- **Fichas técnicas / Receitas** com cálculo de CMV
- **Fator de rendimento** para perdas de cocção
- **Conteúdo por embalagem** (v2.3) - Mantém estoque físico mas calcula volume total
- **Hierarquia de recursos** (v2.4) - Agrupa produtos por insumo genérico (ex: "Arroz" → marcas)
- **Fluxo de produção** (v2.5) - Produz pratos, consome ingredientes, adiciona ao estoque
- **Tipos múltiplos** (v2.6) - Produto pode ser Insumo + Produzido simultaneamente
- **Rastreamento de Perdas** (v2.7) - Registro detalhado de quebras/vencimentos com impacto no CMV
- **Inventário Cego** (v2.8) - Contagem física sem viés para auditoria de estoque
- **Relatório de Gestão** (v2.9) - Dashboard DRE com cálculo de CMV por período e alertas visuais
- **CMV Dashboard no Portal** (v2.9.1) - Dashboard visual na Área do Funcionário com gráficos interativos
- **FEFO por Validade** (v2.9.2) - Alertas visuais de itens próximos do vencimento e consumo automático por validade
- **Hierarquia de Locais/Setores** (v2.10) - Locais com setores filhos e inventário cego por escopo
- **Inventário Cego no Portal** (v2.10) - Modal de inventário cego integrado ao Portal do Colaborador com seleção de local/setor
- **Detalhes do Lote com Hierarquia** (v2.10.1) - Visualização completa do caminho do local (Pai - Filho) nas listagens de lotes\r\n- **Wizard Setor-a-Setor** (v2.15) - Modo wizard automático no inventário cego para locais com setores, com progresso visual e navegação guiada
- **Entrada em Lote** (v2.16) - Modal de entrada multi-produto: local selecionado uma vez, produtos em linhas de tabela com adição automática de nova linha
- **Avisos de Custo Genérico** (v2.16) - Painel de alertas para produtos RESOURCE com `content_quantity` faltando, conversão de unidade ausente ou desvio >20% entre custo ao vivo e histórico
- **Cadastro Relacional de Fornecedores** (v2.17) - Tabela `sistur_suppliers`, FK `supplier_id` em `inventory_batches`, migração automática de `acquisition_location`, CRUD via REST, searchable select no formulário de entrada

---

## CMV Dashboard - Área do Funcionário (v2.9.3)

> **Novo em v2.9.3:** Dashboard visual de CMV agora é um **espelho completo** do Admin WordPress, com todas as funcionalidades de relatório.

### Funcionalidades

1. **Compass Gauge (CMV%)**
   - Indicador visual semicircular mostrando CMV% vs meta de 30%
   - Zonas de cor: Verde (<30%), Amarelo (30-40%), Vermelho (>40%)
   - Agulha animada que atualiza com o período selecionado

2. **Cards de Resumo**
   - 💰 **Receita** - Total de vendas no período
   - 📦 **CMV** - Custo da mercadoria vendida
   - 📈 **Resultado Bruto** - Lucro bruto (Receita - CMV)
   - ⚠️ **Perdas** - Total de quebras/vencimentos

3. **Gráficos Interativos (Chart.js)**
   - **Bar Chart:** Compras (Custo) vs Vendas (Receita)
   - **Pie Chart:** Categorias de Perdas (Vencido, Erro de Produção, Dano Físico, etc.)

4. **Composição do CMV** (v2.9.3 - espelho do Admin)
   - (+) Estoque Inicial
   - (+) Compras no Período
   - (-) Estoque Final
   - (=) CMV
   - Perdas no Período (quando houver)

5. **Vendas por Categoria** (v2.9.3 - espelho do Admin)
   - Tabela com Categoria, Receita, Custo e Margem
   - Cores indicativas de margem (verde ≥70%, azul ≥50%, amarelo <50%)

6. **Filtro por Período**
   - Inputs de data início/fim
   - Botões rápidos: Semana, Mês, Trimestre
   - Relatório gerado via AJAX sem recarregar página

### APIs Consumidas

| Endpoint | Dados Retornados |
|----------|------------------|
| `GET /stock/reports/dre` | Receita, CMV, Resultado Bruto, CMV%, `cmv_breakdown`, `by_category` |
| `GET /stock/losses/summary` | Perdas por categoria, top produtos |

### Arquivo de Componente

[module-restaurante.php](file:///c:/xampp/htdocs/trabalho/wordpress/wp-content/plugins/sistur2/templates/components/module-restaurante.php)

### Permissões Necessárias

| Permissão | Acesso |
|-----------|--------|
| `restaurant.view` | Visualizar aba Restaurante |
| `cmv.view` | Visualizar dashboard CMV |
| `cmv.edit` | Botões de ação para admin

---

## Conteúdo por Embalagem (v2.3)

> **Novo em v2.3:** O sistema agora suporta manter estoque em unidades físicas (latas, garrafas, caixas) enquanto calcula e exibe volume total (Litros, Kg) no dashboard.

### Problema Resolvido

Anteriormente, converter tudo na entrada perdia a contagem física das embalagens. Agora:

- **Estoque é salvo na unidade física** (ex: 5 Latas)
- **Cada produto pode ter conteúdo definido** (ex: 0.75 Litros por lata)
- **Dashboard mostra volume total** por categoria (ex: "7.25 Litros")

### Novas Colunas na Tabela `sistur_products`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `content_quantity` | `DECIMAL(10,3)` | Quantidade de conteúdo por embalagem (ex: 0.75, 1.0, 350) |
| `content_unit_id` | `INT(10) UNSIGNED` | FK para `sistur_units` (ex: Litros, Gramas) |

### Fórmula de Cálculo

```
Volume Total = Σ (Quantidade em Estoque × content_quantity)
```

**Exemplo:**
- 5 Latas de 1L → 5 × 1.0 = 5.0L
- 3 Garrafas de 750ml → 3 × 0.75 = 2.25L
- **Total na categoria: 7.25 Litros**

### Uso no Código

```php
// Salvar produto com conteúdo
$data = [
    'name' => 'Óleo de Girassol',
    'content_quantity' => 0.9,      // 900ml
    'content_unit_id' => 3          // ID da unidade "Litros"
];

// Query para volume por categoria
SELECT c.name, SUM(p.current_stock * p.content_quantity) as total_volume
FROM sistur_products p
JOIN sistur_product_categories c ON p.category_id = c.id
WHERE p.content_quantity IS NOT NULL
GROUP BY c.id;
---

## Hierarquia de RESOURCE (v2.4)

> **Novo em v2.4:** O sistema suporta agrupar produtos específicos (marcas) sob um insumo genérico.

### Conceito

Um **RESOURCE** é um insumo genérico (ex: "Arroz Branco") que agrupa várias marcas/produtos:

```
Arroz Branco (RESOURCE)
   ├── Arroz Tio João (RAW) - 100 pct × 5kg = 500kg
   ├── Arroz Camil (RAW) - 50 pct × 1kg = 50kg
   └── Arroz Blue Ville (RAW) - 200 pct × 5kg = 1000kg
       ──────────────────────────────────────────
       Estoque Total Agregado: 1550 kg
```

### Novas Colunas na Tabela `sistur_products`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `resource_parent_id` | `INT(10) UNSIGNED` | FK para produto RESOURCE pai |
| `type` | `SET(...)` | Tipo(s) do produto - ver seção [Tipos Múltiplos (v2.6)](#tipos-múltiplos-v26) |

### Página de Gestão

Acessível via **Restaurante → Tipos de Produtos**, exibe:
- Lista de insumos genéricos (RESOURCE)
- Quantidade de marcas vinculadas
- Estoque total agregado (convertido para unidade base)
- Custo médio ponderado

### Baixa de Estoque via RESOURCE

Quando uma receita usa "Arroz Branco" (RESOURCE):
1. Sistema identifica que é RESOURCE
2. Busca todos os produtos filhos (marcas)
3. Consome via FIFO distributivo entre as marcas
4. Converte usando `content_quantity` e `content_unit_id`

---

## Fluxo de Produção (v2.5)

> **Novo em v2.5:** Produzir um prato (MANUFACTURED) consome ingredientes e adiciona ao estoque.

### Fluxo Completo

```
1. Usuário clica botão "Produzir" (🔨) em produto MANUFACTURED
2. Modal abre mostrando receita (ingredientes necessários)
3. Usuário define quantidade a produzir
4. Sistema executa:
   a. Verifica estoque de cada ingrediente
   b. Consome ingredientes via FIFO (registra PRODUCTION_OUT)
   c. Calcula custo: Σ(custo_ingrediente × quantidade)
   d. Cria lote do produto produzido (registra PRODUCTION_IN)
   e. Atualiza cached_stock e average_cost
5. Produto produzido aparece no estoque
```

---

## Prato Base - Auto-produção (v2.9.4)

> **Novo em v2.9.4:** Pratos Base são produtos produzidos que podem servir de ingrediente para outros pratos. Quando necessários, são auto-produzidos automaticamente.

### Conceito

Um **Prato Base** (tipo `BASE`) é um item intermediário:

```
Churrasco com Vinagrete
├── Carne (RESOURCE) ─ consome do estoque
├── Vinagrete (BASE) ─ auto-produz se necessário
│   ├── Vinagre (RESOURCE)
│   └── Azeite (RESOURCE)
└── Tempero (RESOURCE) ─ consome do estoque
```

### Como Funciona

1. **Validação Recursiva:** Antes de produzir o prato final, o sistema percorre TODA a árvore de ingredientes
2. **Detecção de Falta:** Se um Prato BASE não tem estoque suficiente, calcula quanto produzir
3. **Pré-verificação:** Verifica se há ingredientes para os Pratos BASE ANTES de consumir qualquer coisa
4. **Execução Ordenada:** Produz os Pratos BASE primeiro, depois o prato final

### Proteção contra Loops

O sistema detecta e bloqueia dependências circulares:
```
❌ Vinagrete usa Molho que usa Vinagrete → Erro: Dependência circular
```

### Tipos de Produto Disponíveis

| Tipo | Ícone | Pode ter Receita | Pode ser Ingrediente | Auto-produz |
|------|-------|------------------|---------------------|-------------|
| `RESOURCE` | 📦 | ❌ | ✅ (via filhos) | ❌ |
| `RAW` | 🥬 | ❌ | ❌ (Removidos da lista de composição v2.17.1) | ❌ |
| `MANUFACTURED` | 🍳 | ✅ | ❌ | ❌ |
| `BASE` | 🍲 | ✅ | ✅ | ✅ |
| `RESALE` | 🛒 | ❌ | ❌ | ❌ |

### Exemplo de Uso

1. Criar "Vinagrete" como tipo `BASE` com receita (Vinagre + Azeite)
2. Criar "Churrasco" como tipo `MANUFACTURED` com receita (Carne + Vinagrete)
3. Ao produzir Churrasco sem estoque de Vinagrete:
   - Sistema auto-produz Vinagrete (consome Vinagre + Azeite)
   - Depois produz Churrasco (consome Vinagrete + Carne)

### Cálculo de Custo

O custo do produto produzido é a soma ponderada dos ingredientes:

```
Custo Unitário = Σ (custo_médio_ingrediente × quantidade_consumida) / quantidade_produzida

Exemplo - Produzir 5 unidades de Risoto:
- Arroz: 0.5kg × R$ 5,00/kg = R$ 2,50
- Caldo: 0.2L × R$ 10,00/L = R$ 2,00
- Queijo: 0.1kg × R$ 40,00/kg = R$ 4,00
───────────────────────────────────────
Total por Risoto: R$ 8,50
Lote criado: 5 unidades @ R$ 8,50 cada
```

### API

```
POST /sistur/v1/recipes/{product_id}/produce
Body: { "quantity": 5, "notes": "Produção para evento" }
```

### Resposta

```json
{
  "success": true,
  "product_name": "Risoto",
  "quantity_produced": 5,
  "unit_cost": 8.50,
  "total_cost": 42.50,
  "batch_code": "PROD-20251229-123456-15",
  "new_stock": 5
}
```

---

## Tipos Múltiplos (v2.6)

> **Novo em v2.6:** Produtos podem ter múltiplos tipos simultaneamente (ex: Insumo + Produzido).

### Caso de Uso: Pré-Preparo

**Problema:** Vinagrete é *produzido* (tem receita: tomate + cebola) mas também é *ingrediente* de outros pratos (ex: feijoada).

**Solução:** Permitir tipos combinados:
- ☑️ Insumo (RAW) - Pode ser usado como ingrediente
- ☑️ Produzido (MANUFACTURED) - Possui receita/composição

### Schema do Banco

O campo `type` foi alterado de `ENUM` para `SET`:

```sql
-- Antes (v2.5)
type ENUM('RAW', 'RESALE', 'MANUFACTURED', 'RESOURCE')

-- Depois (v2.6)
type SET('RAW', 'RESALE', 'MANUFACTURED', 'RESOURCE')

-- Exemplos de valores
'RAW'                 -- Apenas insumo
'MANUFACTURED'        -- Apenas produzido  
'RAW,MANUFACTURED'    -- Pré-preparo (ambos)
```

### Novo Parâmetro de API: `has_type`

```
GET /products?has_type=RAW       -- Produtos que CONTÊM tipo RAW
GET /products?type=RAW           -- Produtos com tipo EXATO (match completo)
GET /products?exclude_type=RESOURCE  -- Exclui produtos com RESOURCE
```

Internamente usa `FIND_IN_SET()`:
```sql
WHERE FIND_IN_SET('RAW', type) > 0
```

### Interface

- Formulário de produto: **Checkboxes** substituem dropdown
- Busca de ingredientes: Filtra por `has_type=RAW`
- Botão "Produzir": Aparece se produto contém MANUFACTURED

### Regras de Negócio

| Tipo(s) | Pode ser Ingrediente | Tem Receita | Botão Produzir |
|---------|---------------------|-------------|----------------|
| RAW | ✅ | ❌ | ❌ |
| MANUFACTURED | ❌ | ✅ | ✅ |
| RAW,MANUFACTURED | ✅ | ✅ | ✅ |
| RAW,MANUFACTURED | ✅ | ✅ | ✅ |
| RESALE | ❌ | ❌ | ❌ |

---

## Rastreamento de Perdas (v2.7)

> **Novo em v2.7:** Sistema dedicado para registrar e categorizar perdas de estoque, impactando corretamente o CMV.

### Conceito

Diferente de um ajuste simples (`ADJUST`), uma perda (`LOSS`) representa um custo operacional que deve ser contabilizado no CMV. O sistema agora permite:

1. **Categorizar a perda:**
   - 📅 **Vencido** (`expired`)
   - ⚙️ **Erro de Produção** (`production_error`)
   - 💥 **Dano Físico** (`damaged`)
   - 📝 **Outro** (`other`)

2. **Registrar custo real:** O sistema usa FIFO para determinar exatamente qual lote foi perdido e qual era seu custo de aquisição.

### Tabela Dedicada: `sistur_inventory_losses`

Além da transação padrão, os detalhes são salvos nesta nova tabela:

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `product_id` | `INT` | Produto que sofreu perda |
| `batch_id` | `INT` | Lote específico consumido (via FIFO) |
| `quantity` | `DECIMAL` | Quantidade perdida |
| `reason` | `ENUM` | Motivo da perda (expired, etc) |
| `reason_details` | `TEXT` | Detalhes opcionais |
| `cost_at_time` | `DECIMAL` | Custo unitário do lote no momento |
| `total_loss_value` | `DECIMAL` | Valor total (qty * cost) |

### Impacto no CMV

Cada perda gera duas ações:
1. **Baixa no Estoque:** Reduz a quantidade física disponível (`process_exit`).
2. **Registro Financeiro:** O valor da perda (`total_loss_value`) entra no cálculo de CMV como "Perda de Inventário", separada do CMV de Vendas.

### Interface

- Modal dedicado com campos de Quantidade, Motivo e Detalhes.
- Acesso rápido via botão de alerta (⚠️) na tabela de produtos.

---

## Inventário Cego / Blind Count (v2.8 + v2.10 + v2.15)

> **Novo em v2.8:** Sistema de contagem física de estoque sem visualização dos valores teóricos para auditoria imparcial.  
> **Atualizado em v2.10:** Suporte a escopo por Local e Setor + integração no Portal do Colaborador.  
> **Atualizado em v2.15:** Modo Wizard setor-a-setor com progresso visual e navegação guiada.

### Conceito

O Inventário Cego permite realizar uma contagem física do estoque sem que o operador veja os valores registrados no sistema. Isso elimina o viés de "ajustar" a contagem para bater com o teórico.

### Fluxo de Operação

```
1. Administrador inicia nova sessão de inventário
   - Pode selecionar Local e/ou Setor específico (v2.10)
   - Ou contar todos os locais
2. Sistema captura estoque teórico dos produtos no escopo selecionado (snapshot)
3. Operador insere quantidades físicas SEM ver o teórico
4. Ao finalizar, sistema calcula divergências:
   a. Negativas → Cria registro de perda (reason: inventory_divergence)
   b. Positivas → Cria ajuste de entrada (ADJUST)
5. Relatório mostra impacto financeiro total com escopo do inventário
```

### Escopo por Local/Setor (v2.10)

Ao iniciar uma sessão, o operador pode filtrar por:
- **Todos os locais** - Conta todos os produtos ativos
- **Local específico** - Conta apenas produtos com lotes no local e seus setores
- **Setor específico** - Conta apenas produtos com lotes naquele setor

### Modo Wizard Setor-a-Setor (v2.15)

Quando o operador seleciona um **Local que possui setores filhos** sem especificar um setor, o sistema ativa automaticamente o **modo wizard**:

```
1. Sistema detecta setores filhos do local selecionado
2. Inicializa sessão com wizard_mode=1 e sectors_list=[ids dos setores]
3. UI exibe barra de progresso e dots para cada setor
4. Operador conta itens do Setor 1 → clica "Próximo Setor"
5. Auto-save dos itens do setor atual
6. Sistema avança para Setor 2 (carrega novos itens)
7. ... repete até último setor
8. Ao concluir último setor:
   a. Barra de progresso vai para 100%
   b. Botão "Finalizar e Processar" aparece
   c. Tabela exibe TODOS os itens para revisão final
9. Submit processa divergências normalmente
```

#### Características do Wizard

| Característica | Comportamento |
|----------------|---------------|
| **Direção** | Forward-only (sem navegação para trás) |
| **Auto-save** | Salva itens do setor atual antes de avançar |
| **Itens sob demanda** | Itens gerados por setor na primeira visita |
| **Progress indicator** | Barra de progresso + dots com tooltip do nome |
| **Itens inesperados** | Adicionados ao setor atual |
| **Revisão final** | Após completar todos os setores, exibe todos os itens |

### Novas Tabelas

#### `sistur_blind_inventory_sessions`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | `INT` | ID da sessão |
| `user_id` | `BIGINT` | Usuário que iniciou |
| `status` | `ENUM` | in_progress, completed, cancelled |
| `location_id` | `INT` | **(v2.10)** FK para local (NULL = todos) |
| `sector_id` | `INT` | **(v2.10)** FK para setor (NULL = todos do local) |
| `wizard_mode` | `TINYINT(1)` | **(v2.15)** 1 = modo wizard ativo |
| `current_sector_index` | `INT` | **(v2.15)** Índice do setor atual (0-based) |
| `sectors_list` | `TEXT` | **(v2.15)** JSON array de IDs dos setores |
| `started_at` | `DATETIME` | Início da contagem |
| `completed_at` | `DATETIME` | Fim da contagem |
| `total_divergence_value` | `DECIMAL` | Impacto financeiro total |

#### `sistur_blind_inventory_items`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `session_id` | `INT` | FK para sessão |
| `product_id` | `INT` | Produto contado |
| `theoretical_qty` | `DECIMAL` | Estoque teórico no momento |
| `physical_qty` | `DECIMAL` | Quantidade física informada |
| `divergence` | `DECIMAL` | Diferença (físico - teórico) |
| `unit_cost` | `DECIMAL` | Custo unitário |
| `divergence_value` | `DECIMAL` | Impacto financeiro |
| `loss_id` | `INT` | FK se criou perda |
| `transaction_id` | `BIGINT` | FK se criou ajuste |

### Interface

**Admin:** Menu **Restaurante → Inventário Cego** (`blind-count.php`)

**Portal do Colaborador (v2.10):** Botão "Inventário Cego" no `module-estoque.php`
- Modal com 3 views: Sessões, Contagem, Relatório
- Seletor de Local/Setor ao criar nova sessão
- Auto-save ao digitar quantidades
- Filtro de produtos por nome
- Relatório com cards de resumo e tabela de divergências

---

## Entrada de Estoque com Setores (v2.10)

> **Atualizado em v2.10:** O registro de entrada agora exige a seleção hierárquica de Local e Setor.

### Fluxo de Registro

1. **Local de Armazenamento:** Usuário seleciona o local pai (ex: "Estoque Seco", "Freezer").
2. **Setor:**
   - Se o local tem setores, um segundo dropdown aparece automaticamente.
   - **A seleção do setor torna-se obrigatória.**
   - O sistema registra o lote vinculado ao ID do setor.
   - Se o local não tem setores, o segundo dropdown permanece oculto e o lote é vinculado ao local pai.

### Implementação Técnica

- **Frontend:** `module-estoque.php` usa dados JSON embutidos (`data-sectors`) para popular o select de setores sem requisições adicionais.
- **Backend:** O valor enviado como `location_id` é o ID final (do setor se houver, ou do pai se não houver). O backend (`register_entry`) não precisa distinguir, pois ambos são locais válidos na tabela `sistur_storage_locations`.

---

## Entrada em Lote (v2.16)

> **Novo em v2.16:** O modal "Registrar Entrada" foi substituído por um formulário de entrada multi-produto.

### Fluxo

1. Operador seleciona **Local de Armazenamento** (com cascata de Setor, se existir) uma única vez no topo do formulário.
2. Seleciona o **Fornecedor** via searchable select (ver seção [Fornecedores (v2.17)](#cadastro-relacional-de-fornecedores-v217)).
3. Preenche quantas linhas de produto desejar — nova linha em branco aparece automaticamente ao preencher a última.
4. Clica em "Registrar N Entradas" — o botão exibe a contagem de linhas válidas.
5. Backend (`ajax_save_bulk_movement`) itera sobre as entradas e chama `register_entry()` para cada uma, reutilizando `location_id`, `supplier_id` e `notes` globais.

### Campos Globais (topo do modal)

| Campo | Obrigatório | Detalhe |
|-------|-------------|---------|
| Local de Armazenamento | Sim | Dropdown + cascata de setor |
| Fornecedor | Não | Searchable select com `supplier_id` oculto |
| Observação | Não | Nota aplicada a todas as entradas do lote |

### Campos por Linha de Produto

| Campo | Obrigatório | Detalhe |
|-------|-------------|---------|
| Produto | Sim | Select dos produtos ativos |
| Qtd | Sim | Quantidade na unidade do produto |
| Custo Unit. (R$) | Não | Custo de aquisição por unidade |
| Validade | Não | `YYYY-MM-DD` |
| [×] | — | Remover linha (mínimo 1 linha fica sempre) |

### AJAX Handler

```
POST wp-admin/admin-ajax.php
action: sistur_save_bulk_movement
nonce: {nonce}
location_id: {int}
supplier_id: {int|""}
notes: {string}
entries: JSON.stringify([{product_id, quantity, unit_price, expiry_date}])
```

Registrado em `class-sistur-inventory.php` como `ajax_save_bulk_movement()` com hooks `wp_ajax_*` e `wp_ajax_nopriv_*`.

---

## Avisos de Custo Genérico (v2.16)

> **Novo em v2.16:** Painel de alertas visuais na aba de produtos genéricos (RESOURCE) para detectar problemas silenciosos no cálculo de custo ao vivo.

### Cálculo de Custo ao Vivo para RESOURCE

Produtos do tipo RESOURCE não têm lotes próprios. O custo é calculado em tempo real a partir dos lotes ativos dos produtos filhos:

```
Custo Médio RESOURCE =
  SUM(batch.quantity × batch.cost_price)
  ─────────────────────────────────────────────────────────────────
  SUM(batch.quantity × COALESCE(child.content_quantity, 1)
      × COALESCE(conversion_factor, 1))
```

**Risco implícito:** `COALESCE(child.content_quantity, 1)` silencia a falta de `content_quantity`, tratando o fator como 1. O painel de avisos expõe esses casos.

### Tipos de Alerta

| Cor | Ícone | Condição |
|-----|-------|----------|
| 🟡 Amarelo | ⚠️ | Produto filho sem `content_quantity` definida (fator silenciado como 1) |
| 🔴 Vermelho | 🚨 | Unidade do filho diferente do pai e nenhuma conversão cadastrada |
| 🔵 Azul | ℹ️ | Custo ao vivo difere >20% do custo médio histórico do mesmo produto |

Os alertas são calculados em PHP no carregamento da página, após a query principal de `$products_stock`, e injetados como painéis HTML antes da tabela de genéricos. Células afetadas recebem ícones ⚠️/🚨 inline.

---

## Cadastro Relacional de Fornecedores (v2.17)

> **Novo em v2.17:** Substituição do campo texto livre "Local de Aquisição" por uma tabela relacional de fornecedores com CRUD completo e migração automática de dados históricos.

### Motivação

O campo `acquisition_location` (`varchar 255`) permitia valores duplicados e inconsistentes ("Supermercado Tático", "sup. tatico", "Sup.Tático"). A v2.17 cria uma entidade `sistur_suppliers` e vincula os lotes via FK `supplier_id`.

### Nova Tabela: `sistur_suppliers`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | PK |
| `name` | `VARCHAR(255) NOT NULL` | Nome do fornecedor |
| `tax_id` | `VARCHAR(18)` | CNPJ (opcional) |
| `contact_info` | `TEXT` | Telefone / e-mail (opcional) |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | Data de cadastro |

### Alteração em `inventory_batches`

Nova coluna adicionada via migração (idempotente com `SHOW COLUMNS`):

```sql
ADD COLUMN supplier_id INT(10) UNSIGNED DEFAULT NULL
    COMMENT 'FK para sistur_suppliers (v2.17.0)'
    AFTER acquisition_location,
ADD KEY supplier_id (supplier_id)
```

> O campo `acquisition_location` é mantido como `NULL`-able para compatibilidade retroativa. Lotes históricos sem `supplier_id` ainda exibem o texto original.

### Migração Automática

O método `migrate_acquisition_location_to_suppliers()` (idempotente) roda no `install()` e:

1. Busca todos os valores distintos de `acquisition_location` onde `supplier_id IS NULL`.
2. Para cada valor: insere em `sistur_suppliers` se ainda não existe (pelo `name`).
3. Atualiza `supplier_id` nos lotes correspondentes.

### REST API de Fornecedores

| Método | Rota | Permissão | Descrição |
|--------|------|-----------|-----------|
| `GET` | `/sistur/v1/stock/suppliers` | `check_view_permission` | Lista todos os fornecedores ordenados por nome |
| `POST` | `/sistur/v1/stock/suppliers` | `check_manage_settings_permission` | Cria fornecedor; valida campo `name`; audit log |
| `PUT` | `/sistur/v1/stock/suppliers/{id}` | `check_manage_settings_permission` | Atualiza; audit log |
| `DELETE` | `/sistur/v1/stock/suppliers/{id}` | `check_manage_settings_permission` | Exclui; retorna **409** se há lotes vinculados; audit log |

### Interface no Portal do Colaborador

**Botão "Fornecedores"** na barra de ações (ao lado de "Gerenciar Locais") → abre `#modal-suppliers`.

**Modal Gerenciar Fornecedores:**
- Formulário de adição inline (Nome*, CNPJ, Contato)
- Lista de cards com edição inline (formulário expande dentro do card) e exclusão com confirmação

**Searchable Select no modal de entrada:**
- `#entry-supplier-search`: input de texto com filtro em tempo real (sem Select2)
- `#entry-supplier-id`: hidden input com o ID selecionado (enviado no POST)
- `#supplier-dropdown`: dropdown absoluto filtrado do cache `$suppliersCache`
- Botão `+` ao lado do campo abre `#modal-suppliers` sem fechar o modal de entrada
- Cache recarregado via `loadSuppliers()` ao abrir o modal de entrada e ao fechar o modal de fornecedores

### Compatibilidade com Custo Médio

O campo `supplier_id` em `inventory_batches` **não afeta** o cálculo de custo médio. O método `recalculate_average_cost()` usa apenas `unit_cost × quantity` de `inventory_transactions`.

---

## Arquitetura

```
sistur.php (Orquestrador)
    │
    ├── includes/
    │   ├── class-sistur-inventory.php        # Inventário legado/básico
    │   ├── class-sistur-stock-api.php        # API REST principal
    │   ├── class-sistur-stock-installer.php  # Instalador de tabelas
    │   ├── class-sistur-recipe-manager.php   # Ficha técnica/CMV/Produção
    │   └── class-sistur-unit-converter.php   # Conversão de unidades
    │
    ├── admin/views/
    │   ├── stock-management.php              # UI de gestão
    │   ├── product-types.php                 # UI de RESOURCE (v2.4)
    │   └── stock-settings.php                # Configurações
    │
    └── assets/js/
        ├── stock-management.js               # Inclui produção (v2.5)
        └── stock-settings.js
```

---

## Classes Principais

### 1. `SISTUR_Stock_API` (class-sistur-stock-api.php)

**Propósito:** API REST para todas as operações de estoque.

| Método | Rota REST | Descrição |
|--------|-----------|-----------|
| `get_products()` | `GET /sistur/v1/stock/products` | Lista produtos com paginação |
| `create_product()` | `POST /sistur/v1/stock/products` | Cria novo produto |
| `update_product()` | `PUT /sistur/v1/stock/products/{id}` | Atualiza produto |
| `create_movement()` | `POST /sistur/v1/stock/movements` | Entrada/saída de estoque |
| `process_entry()` | *interno* | Cria novo lote na entrada |
| `process_exit()` | *interno* | Consome lotes via FIFO |
| `get_units()` | `GET /sistur/v1/stock/units` | Lista unidades de medida |
| `get_locations()` | `GET /sistur/v1/stock/locations` | Lista locais com setores (hierarquia pai/filho) |
| `create_location()` | `POST /sistur/v1/stock/locations` | Cria local ou setor (`parent_id` para setor) |
| `delete_location()` | `DELETE /sistur/v1/stock/locations/{id}` | Remove local/setor |
| `get_product_batches()` | `GET /sistur/v1/stock/batches/{id}` | Lista lotes de um produto |
| `get_recipe_cost()` | `GET /sistur/v1/recipes/{id}/cost` | Calcula CMV de uma receita |
| `deduct_recipe_stock()` | `POST /sistur/v1/recipes/{id}/deduct` | Baixa estoque da receita |

| `produce_product()` | `POST /sistur/v1/recipes/{id}/produce` | **(v2.5)** Produz prato, consome ingredientes e cria lote |
| `register_loss()` | `POST /sistur/v1/stock/losses` | **(v2.7)** Registra perda de estoque |
| `get_losses()` | `GET /sistur/v1/stock/losses` | **(v2.7)** Lista histórico de perdas |
| `start_blind_inventory()` | `POST /sistur/v1/stock/blind-inventory/start` | **(v2.8/2.10)** Inicia sessão (aceita `location_id`, `sector_id`) |
| `get_blind_inventory_session()` | `GET /sistur/v1/stock/blind-inventory/{id}` | **(v2.8/2.10)** Itens da sessão com info de local/setor |
| `get_blind_inventory_sessions()` | `GET /sistur/v1/stock/blind-inventory` | **(v2.8/2.10)** Lista sessões com nomes de local/setor |
| `update_blind_inventory_item()` | `PUT /sistur/v1/stock/blind-inventory/{id}/item/{pid}` | **(v2.8)** Atualiza quantidade física |
| `submit_blind_inventory()` | `POST /sistur/v1/stock/blind-inventory/{id}/submit` | **(v2.8)** Processa divergências |
| `get_blind_inventory_report()` | `GET /sistur/v1/stock/blind-inventory/{id}/report` | **(v2.8/2.10)** Relatório com escopo local/setor |
| `get_current_sector_items()` | `GET /sistur/v1/stock/blind-inventory/{id}/current-sector` | **(v2.15)** Itens do setor atual no wizard |
| `advance_to_next_sector()` | `POST /sistur/v1/stock/blind-inventory/{id}/advance` | **(v2.15)** Avança para próximo setor no wizard |
| `get_losses_summary()` | `GET /sistur/v1/stock/losses/summary` | **(v2.7)** Resumo de perdas para relatório |
| `get_suppliers()` | `GET /sistur/v1/stock/suppliers` | **(v2.17)** Lista fornecedores ordenados por nome |
| `create_supplier()` | `POST /sistur/v1/stock/suppliers` | **(v2.17)** Cria fornecedor; valida `name`; audit log |
| `update_supplier()` | `PUT /sistur/v1/stock/suppliers/{id}` | **(v2.17)** Atualiza fornecedor; audit log |
| `delete_supplier()` | `DELETE /sistur/v1/stock/suppliers/{id}` | **(v2.17)** Exclui; 409 se há lotes vinculados; audit log |

---

### 2. `SISTUR_Stock_Installer` (class-sistur-stock-installer.php)

**Propósito:** Criação e migração das tabelas do módulo.

#### Tabelas Criadas

| Tabela | Descrição |
|--------|-----------|
| `sistur_units` | Unidades de medida (kg, L, un, cx) |
| `sistur_unit_conversions` | Fatores de conversão entre unidades |
| `sistur_storage_locations` | Locais físicos com hierarquia (Local → Setores via `parent_id`) |
| `sistur_inventory_batches` | Lotes com validade e custo |
| `sistur_inventory_transactions` | Log imutável de movimentações |
| `sistur_product_supplier_links` | Matching de códigos NFe/XML |
| `sistur_recipes` | Ingredientes das fichas técnicas |
| `sistur_inventory_losses` | **(v2.7)** Registro de perdas e quebras |
| `sistur_blind_inventory_sessions` | **(v2.8)** Sessões de inventário cego |
| `sistur_blind_inventory_items` | **(v2.8)** Itens contados por sessão |
| `sistur_suppliers` | **(v2.17)** Fornecedores com CNPJ e contato |

#### Métodos Principais

| Método | Descrição |
|--------|-----------|
| `install()` | Instala/atualiza todas as tabelas |
| `uninstall()` | Remove tabelas (CUIDADO: apaga dados!) |
| `needs_upgrade()` | Verifica se precisa migração |
| `seed_default_units()` | Popula unidades padrão |
| `seed_default_locations()` | Popula locais padrão |

---

### 3. `Sistur_Recipe_Manager` (class-sistur-recipe-manager.php)

**Propósito:** Gerenciar fichas técnicas e calcular CMV dos pratos.

#### Métodos Principais

| Método | Descrição |
|--------|-----------|
| `calculate_plate_cost($product_id)` | Calcula custo total do prato baseado nos ingredientes |
| `deduct_recipe_stock($product_id, $qty)` | Baixa estoque de todos ingredientes (FIFO) |
| `get_recipe_ingredients($product_id)` | Lista ingredientes de uma receita |
| `add_ingredient($data)` | Adiciona ingrediente à receita |
| `update_ingredient($id, $data)` | Atualiza ingrediente |
| `remove_ingredient($id)` | Remove vínculo do ingrediente |
| `produce($product_id, $qty, $notes)` | **(v2.5)** Produz item MANUFACTURED (consome ingredientes + cria lote) |
| `get_child_products($resource_id)` | **(v2.4)** Lista produtos vinculados a um RESOURCE |
| `get_available_stock_from_children($id)` | **(v2.4)** Estoque agregado de produtos filhos |
| `process_resource_exit($id, $qty, ...)` | **(v2.4)** Baixa FIFO de produtos filhos de um RESOURCE |
| `update_product_cost_from_recipe($id)` | Atualiza custo do produto baseado na receita |

#### Fator de Rendimento

O sistema suporta **fator de rendimento/cocção** para calcular peso bruto vs líquido:

```
Fórmula: quantity_gross = quantity_net / yield_factor

Exemplos:
- Arroz: 2.5 (100g cru → 250g cozido)
- Carne: 0.8 (100g crua → 80g pronta)
- Legumes: 0.9 (perda por descasque)
```

---

### 4. `Sistur_Unit_Converter` (class-sistur-unit-converter.php)

**Propósito:** Converter valores entre unidades de medida.

#### Lógica de Conversão

1. Busca conversão **específica por produto** primeiro
2. Se não encontrar, busca conversão **global** (product_id = NULL)
3. Se não existir conversão, retorna **erro explícito**

#### Métodos Principais

| Método | Descrição |
|--------|-----------|
| `convert($value, $from, $to, $product_id)` | Converte valor entre unidades |
| `get_conversion_factor($from, $to, $product_id)` | Obtém fator de conversão |
| `has_conversion($from, $to, $product_id)` | Verifica se existe conversão |
| `validate_unit($unit_id)` | Valida unidade (impede criação "on-the-fly") |
| `convert_to_ingredient_base_unit()` | Converte da unidade da receita para unidade base do ingrediente |

#### Unidades Padrão do Sistema

O sistema vem com as seguintes unidades pré-cadastradas (`sistur_units`):

**Dimensionais (Conversíveis):**
- **Massa:** Quilograma (kg), Grama (g)
- **Volume:** Litro (L), Mililitro (mL)

**Unitárias (Indivisíveis):**
- Unidade (un)
- Caixa (cx)
- Pacote (pct)
- Peça (pc)
- Garrafa (gfa)
- Lata (lt)
- Dúzia (dz)

#### Regras de Conversão Padrão
- **Massa:** 1 kg = 1000 g
- **Volume:** 1 L = 1000 mL
- **Quantidade:** 1 dz = 12 un

> **Nota:** Conversões específicas (ex: 1 Garrafa de Vinho = 750 mL) devem ser cadastradas via banco de dados (`sistur_unit_conversions`) se necessário, mas o sistema suporta `content_quantity` para resolver a maioria desses casos (ver seção [Conteúdo por Embalagem](#conteúdo-por-embalagem-v23)).

---

### 5. `SISTUR_Inventory` (class-sistur-inventory.php)

**Propósito:** Sistema de inventário legado/simplificado (AJAX).

> ⚠️ **Nota:** Esta classe é a versão anterior do sistema. A nova API REST (`SISTUR_Stock_API`) é preferencial para novas funcionalidades.

#### Métodos AJAX

| Método | Ação AJAX | Descrição |
|--------|-----------|-----------|
| `ajax_save_product()` | `sistur_save_product` | Salvar produto |
| `ajax_delete_product()` | `sistur_delete_product` | Deletar produto |
| `ajax_save_movement()` | `sistur_save_movement` | Registrar movimentação |
| `ajax_record_sale()` | `sistur_record_sale` | Registrar venda (sem aprovação) |
| `ajax_request_loss()` | `sistur_request_loss` | Solicitar baixa por perda |
| `ajax_save_bulk_movement()` | `sistur_save_bulk_movement` | **(v2.16)** Entrada em lote: itera sobre `entries` JSON e chama `register_entry()` para cada item com `location_id`, `supplier_id` e `notes` globais |

---

## Fluxo de Dados

### Entrada de Estoque

```
1. Ajax recebe POST (action: sistur_save_bulk_movement) OU API recebe POST /stock/movements (type: IN)
2. register_entry($product_id, $quantity, $cost_price, $expiry_date, $location_id,
                   $reason, $employee_id, $batch_code, $acquisition_location,
                   $entry_date, $manufacturing_date, $supplier_id) cria lote em inventory_batches
3. log_transaction() registra no livro razão (inventory_transactions)
4. update_cached_stock() atualiza saldo cacheado
5. recalculate_average_cost() recalcula custo médio
```

### Saída de Estoque (FIFO)

```
1. API recebe POST /stock/movements (type: OUT/SALE/LOSS)
2. process_exit() busca lotes ordenados por created_at ASC
3. Consome lotes mais antigos primeiro
4. Atualiza remaining_quantity de cada lote
5. log_transaction() registra cada consumo
6. update_cached_stock() atualiza saldo
```

### Baixa de Receita

```
1. Requisição para deduct_recipe_stock($product_id, $qty)
2. Busca ingredientes em sistur_recipes
3. Para cada ingrediente:
   a. Converte quantidade para unidade base
   b. Aplica yield_factor (peso bruto)
   c. Processa saída via FIFO
4. Retorna custo total consumido
```

---

## Integração com sistur.php

### Carga das Dependências (load_dependencies)

```php
// Stock Management v2.1 - Gestão de Estoque Avançada
require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-stock-api.php';

// Unit Converter v2.2.1 - Conversão de Unidades
require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-unit-converter.php';

// Recipe/Technical Sheet v2.2 - Ficha Técnica
require_once SISTUR_PLUGIN_DIR . 'includes/class-sistur-recipe-manager.php';
```

### Inicialização (define_admin_hooks)

```php
// Stock Management v2.1 - API REST
SISTUR_Stock_API::get_instance();
```

### Menus Admin

- **Restaurante** → Gestão de Estoque (`sistur-stock-management`)
- **Restaurante** → Configurações (`sistur-stock-settings`)

---

## Tipos de Produto

| Tipo | Constante | Descrição |
|------|-----------|-----------|
| Insumo | `RAW` | Matéria-prima (ingredientes) |
| Revenda | `RESALE` | Produto para revenda direta |
| Produzido | `MANUFACTURED` | Prato/produto final (requer receita) |
| Genérico | `RESOURCE` | **(v2.4)** Insumo genérico que agrupa marcas (ex: "Arroz") |

---

## Tipos de Transação

| Tipo | Descrição |
|------|-----------|
| `IN` | Entrada (compra, devolução) |
| `OUT` | Saída genérica |
| `SALE` | Venda |
| `LOSS` | Perda/Quebra |
| `ADJUST` | Ajuste de inventário |
| `TRANSFER` | Transferência entre locais |
| `PRODUCTION_IN` | **(v2.5)** Entrada de produto produzido |
| `PRODUCTION_OUT` | **(v2.5)** Consumo de ingrediente para produção |

---

## Portal do Colaborador (v2.7)

> **Novo em v2.7:** As funcionalidades de CMV estão disponíveis no Portal do Colaborador para funcionários com permissões específicas.

### Permissões CMV

| Permissão | Descrição |
|-----------|-----------|
| `cmv.manage_products` | Criar, editar e excluir produtos no portal |
| `cmv.manage_recipes` | Criar e editar receitas/composições |
| `cmv.produce` | Executar produção de itens MANUFACTURED |
| `cmv.view_batches` | Visualizar lotes de produtos |
| `cmv.view_costs` | Ver custos e CMV de produtos |
| `cmv.manage_full` | Acesso completo (ativa todas as permissões acima) |

### Funcionalidades no Portal

- **Novo Produto**: Modal com tabs para dados básicos e composição
- **Editar Produto**: Botão de ação na tabela de produtos
- **Produzir**: Modal que mostra ingredientes necessários, disponibilidade e custo
- **Ver Lotes**: Modal com tabela de lotes (número, local, validade, quantidade, custo, status)
- **Tabela expandida**: Colunas de Tipo e Custo Médio (quando autorizado)
- **Badges visuais**: 🥬 Insumo, 🍳 Produzido, 🛒 Revenda

### Arquivo Principal

`templates/components/module-estoque.php` - Interface completa do módulo de estoque no portal

---

## Próximos Passos (TODO)

- [x] ~~Implementar relatórios de CMV por período~~ (v2.9)
- [x] ~~Dashboard visual de custos~~ (v2.9)
- [ ] Integração com importação de NFe XML
- [ ] Alertas de estoque mínimo
- [ ] Alertas de validade próxima

---

## Changelog do Módulo

| Versão | Data | Mudanças |
|--------|------|----------|
| 2.17.0 | 2026-02-19 | **Cadastro Relacional de Fornecedores:** nova tabela `sistur_suppliers` (name, tax_id/CNPJ, contact_info); coluna `supplier_id INT UNSIGNED NULL` adicionada a `inventory_batches` (após `acquisition_location`); migração automática idempotente de `acquisition_location` → `sistur_suppliers`; 4 endpoints REST CRUD (`/stock/suppliers`, `/stock/suppliers/{id}`); modal "Gerenciar Fornecedores" no Portal com CRUD inline; searchable select JS-puro (sem Select2) no modal de entrada em lote; botão "+" abre modal de fornecedores sem fechar o modal de entrada; `register_entry()` recebe `$supplier_id` como 12º parâmetro; `ajax_save_bulk_movement()` extrai e passa `supplier_id`; audit log em todos os mutadores |
| 2.16.0 | 2026-02-19 | **Entrada em Lote:** modal "Registrar Entrada" refatorado para multi-produto; campos globais (local de armazenamento, fornecedor, observação) + tabela de linhas (produto, qtd, custo, validade, remover); nova linha em branco adicionada automaticamente ao preencher a última; botão exibe contagem "Registrar N Entradas"; `ajax_save_bulk_movement()` em `class-sistur-inventory.php` com hooks `wp_ajax_*` e `wp_ajax_nopriv_*`. **Avisos de Custo Genérico:** painel de alertas para produtos RESOURCE com `content_quantity` nula (⚠️ amarelo), conversão de unidade ausente (🚨 vermelho) ou desvio de custo >20% entre ao vivo e histórico (ℹ️ azul); cálculo PHP após `$products_stock`; ícones inline nas células afetadas |
| 2.15.0 | 2026-02-13 | **Wizard Setor-a-Setor:** Modo wizard automático para inventário cego em locais com setores; barra de progresso e dots com tooltips; navegação forward-only entre setores; auto-save ao avançar; itens gerados sob demanda por setor; revisão final com todos os itens; 2 novos endpoints (`current-sector`, `advance`); 3 novas colunas na tabela de sessões (`wizard_mode`, `current_sector_index`, `sectors_list`) |
| 2.10.1 | 2026-02-10 | **Detalhes do Lote:** A lista de lotes agora exibe a hierarquia completa do local (ex: "Local Pai - Setor Filho") em vez de apenas o nome do último nível. |
| 2.10.0 | 2026-02-10 | **Hierarquia de Locais/Setores e Inventário Cego por Escopo:** Locais agora suportam setores filhos via `parent_id`; modal "Gerenciar Locais" com CRUD de setores inline; inventário cego aceita `location_id`/`sector_id` para filtrar produtos; sessões incluem `location_name`/`sector_name` na API; botão "Inventário Cego" no Portal do Colaborador com modal completo (3 views: sessões, contagem, relatório) e seletor de local/setor |
| 2.9.3 | 2026-02-05 | **CMV Portal Espelho Completo:** Portal Colaborar agora espelha Admin WordPress; adicionadas tabelas "Composição do CMV" (Estoque Inicial + Compras - Estoque Final = CMV) e "Vendas por Categoria" (Receita, Custo, Margem); dados sincronizados via mesma API REST |
| 2.9.1 | 2026-02-02 | **CMV Dashboard na Área do Funcionário:** Compass gauge SVG para CMV%, gráficos Chart.js (Compras vs Vendas, Categorias de Perdas), filtro por período com AJAX, integração com endpoints `/stock/reports/dre` e `/stock/losses/summary` |
| 2.9.0 | 2026-02-02 | **Relatório de Gestão CMV/DRE:** endpoints `/stock/reports/cmv` e `/stock/reports/dre`; dashboard com cards de Receita, CMV e Resultado Bruto; indicador CMV% com alertas de cor (verde <30%, amarelo 30-40%, vermelho >40%); breakdown de composição do CMV; análise por categoria |
| 2.8.0 | 2026-02-02 | **Inventário Cego:** tabelas `sistur_blind_inventory_sessions` e `sistur_blind_inventory_items`; 6 endpoints de API; interface de contagem sem viés; processamento automático de divergências como perdas ou ajustes; relatório de impacto financeiro |
| 2.7.0 | 2026-02-02 | **Rastreamento de Perdas:** tabela `sistur_inventory_losses`, API de perdas, modal de registro e baixa FIFO automática para quebras |
| 2.7.0 | 2026-02-04 | **Portal do Colaborador:** Funcionalidades CMV disponíveis no portal; 6 novas permissões; modais de produto, produção e lotes; tabela expandida com tipo e custo |
| 2.5.0 | 2025-12-29 | **Fluxo de Produção:** método `produce()` que consome ingredientes e cria lote do produto final; custo médio ponderado calculado automaticamente; botão "Produzir" na UI para MANUFACTURED |
| 2.4.0 | 2025-12-29 | **Hierarquia de RESOURCE:** tipo `RESOURCE` para insumos genéricos; página "Tipos de Produtos"; estoque agregado de filhos; baixa FIFO distribuída entre marcas |
| 2.3.0 | 2025-12-29 | **Conteúdo por Embalagem:** campos `content_quantity` e `content_unit_id` para manter estoque físico enquanto calcula volume total no dashboard |
| 2.2.1 | 2025-12 | Adicionado Unit Converter para conversões robustas |
| 2.2.0 | 2025-12 | Recipe Manager com fator de rendimento |
| 2.1.0 | 2025-12 | Stock API REST com FIFO e custo médio |
| 1.0.0 | 2025-xx | Inventário básico (legado) |
 |
