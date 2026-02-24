# Documentação de Permissões e Ações

Este documento descreve as permissões e ações disponíveis nos módulos de **Estoque** e **CMV** (Custo de Mercadoria Vendida) do SISTUR2.

## Sistema de Permissões

O sistema utiliza uma arquitetura de **dupla verificação**:
1.  **Frontend (PHP):** Botões e interfaces são ocultos se o usuário não possuir a permissão.
2.  **Backend (REST API):** Todas as rotas verificam a permissão do usuário/funcionário antes de processar qualquer dado.

As permissões são gerenciadas através da classe `SISTUR_Permissions` e vinculadas ao ID do funcionário ou à `capability` do WordPress para administradores.

---

## Módulo: Estoque (Inventory)

As permissões de estoque controlam a movimentação física de produtos e a organização do armazém.

### Permissões Disponíveis:
-   `inventory.view`: Permite visualizar o saldo atual, lotes e histórico de movimentações.
-   `inventory.record_sale`: Permite registrar saídas por venda (🛒).
-   `inventory.request_loss`: Permite registrar perdas por quebra, vencimento ou dano (⚠️).
-   `inventory.manage`: Permite realizar entradas manuais, transferências entre locais, gerenciar locais de armazenamento e realizar inventário cego.

### Ações de Estoque:
-   **Registrar Venda:** Realiza a baixa dos produtos utilizando lógica FIFO.
-   **Solicitar Baixa:** Registra uma perda e executa a baixa via FIFO, vinculando a um motivo específico.
-   **Transferir:** Move saldo de um local para outro, gerando uma saída na origem e uma entrada no destino.
-   **Registrar Entrada:** Adiciona novos produtos ao estoque, criando novos lotes e recalculando o custo médio ponderado.
-   **Inventário Cego:** Permite a contagem de estoque sem visualização prévia do saldo teórico, gerando relatórios de divergência.

---

## Módulo: CMV (Custo de Mercadoria Vendida)

As permissões de CMV controlam a inteligência financeira, custos, produtos e produção.

### Permissões Disponíveis:
-   `cmv.manage_products`: Permite criar e editar as definições de produtos (unidade base, tipo, SKU).
-   `cmv.manage_recipes`: Permite gerenciar fichas técnicas (receitas/composições).
-   `cmv.produce`: Permite executar a ação de produção (transformação de insumos em produto final).
-   `cmv.manage_full`: Super-permissão que engloba todas as permissões de CMV.

### Ações de CMV:
-   **Novo Produto:** Cadastro de novos itens com definição de unidade de medida base e tipo (Insumo, Revenda, Produzido, Recurso).
-   **Produzir:** Consome ingredientes do estoque conforme a ficha técnica e gera um novo lote do produto final com custo calculado.
-   **Gestão de Fichas Técnicas:** Define a composição de pratos, rendimentos e fatores de cocção.

---

## Resumo Técnico (Backend)

| Módulo | Ação SISTUR | Rota API (Exemplo) | Callback REST |
| :--- | :--- | :--- | :--- |
| Estoque | `record_sale` | `/stock/movements` | `create_movement` (type: SALE) |
| Estoque | `request_loss` | `/stock/losses` | `register_loss` |
| Estoque | `manage` | `/stock/transfer` | `transfer_stock` |
| CMV | `manage_products`| `/stock/products` | `create_product` |
| CMV | `produce` | `/recipes/{id}/produce` | `produce_product` |

> [!IMPORTANT]
> Administradores com a capability `manage_options` possuem acesso total a todas as funcionalidades, independentemente das permissões de funcionário configuradas.
