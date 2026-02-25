# Copyright (c) 2026 Jordan Barbosa Machado — All Rights Reserved

# Módulo: banco_horas

Realiza todos os cálculos de crédito, débito e saldo do **Banco de Horas** dos colaboradores no **Portal do Colaborador** SISTUR.

---

## Visão Geral

| Camada | Arquivo |
|---|---|
| Service | `app/services/banco_horas_service.py` — `BancoDeHorasService` |
| Model (helper) | `app/models/funcionario.py` — `Funcionario.saldo_banco_horas()` |

> **Status atual:** O módulo de cálculo puro está completo e testável.  
> As queries ao banco de dados (`obter_saldo_atual`) são stubs aguardando a portagem do modelo `PontoEletronico` (tabela `sistur_timebank_deductions`).

---

## Convenção de Unidade — Tudo em Minutos

Todos os valores de tempo são manipulados como **inteiros de minutos** para eliminar erros de arredondamento de ponto flutuante em cálculos de folha de pagamento.  
A conversão para string legível é responsabilidade da apresentação (`formatar_minutos`).

```
480 minutos = 8 horas  (jornada padrão CLT)
 60 minutos = 1 hora   (almoço padrão)
```

---

## Service — `BancoDeHorasService`

Todos os métodos são `@staticmethod` — sem estado de instância, sem I/O, sem imports do Flask.  
Compatível com compilação via **Cython** (Antigravity Rule #2).

---

### `calcular_saldo_dia` — Cálculo de Saldo Diário

```python
BancoDeHorasService.calcular_saldo_dia(
    minutos_trabalhados: int,
    minutos_esperados: int,
    minutos_almoco_realizado: int,
    minutos_almoco_esperados: int = 60,
) -> int
```

**Regra de negócio (CLT / comportamento herdado do legado):**

```
saldo = (minutos_trabalhados − minutos_almoco_realizado)
      − (minutos_esperados − minutos_almoco_esperados)
```

| Resultado | Significado |
|---|---|
| Positivo `(+)` | Hora extra — creditada ao banco |
| Negativo `(−)` | Débito — debitado do banco |
| Zero | Jornada cumprida exatamente |

**Exemplos:**

| Cenário | Trabalhado | Almoço real | Esperado | Almoço esperado | Saldo |
|---|---|---|---|---|---|
| 9h trabalhadas, 1h almoço | 540 | 60 | 480 | 60 | **+60** (+1h) |
| 7h30 trabalhadas, 30min almoço | 450 | 30 | 480 | 60 | **−30** (−30min) |
| Jornada exata | 540 | 60 | 480 | 60 | **0** |

```python
BancoDeHorasService.calcular_saldo_dia(540, 480, 60, 60)   # → 60
BancoDeHorasService.calcular_saldo_dia(450, 480, 30, 60)   # → -30
```

---

### `calcular_saldo_periodo` — Agregação de Período

```python
BancoDeHorasService.calcular_saldo_periodo(
    saldos_diarios: list[int],
) -> int
```

Agrega uma lista de saldos diários (saída de `calcular_saldo_dia` para cada dia) em um total do período.

```python
BancoDeHorasService.calcular_saldo_periodo([60, -30, 0, 120, -15])  # → 135
```

---

### `aplicar_deducao` — Dedução Manual

```python
BancoDeHorasService.aplicar_deducao(
    saldo_atual: int,
    minutos_deducao: int,
) -> int
```

Aplica uma dedução ao saldo atual — por exemplo, um abono de horas aprovado por gestão.

| Parâmetro | Restrição |
|---|---|
| `minutos_deducao` | Deve ser **positivo**. Para créditos e débitos de jornada, use `calcular_saldo_dia`. |

```python
BancoDeHorasService.aplicar_deducao(300, 120)   # → 180  (300min − 120min)
BancoDeHorasService.aplicar_deducao(300, -60)   # → ValueError
```

**Raises:** `ValueError` se `minutos_deducao < 0`.

---

### `formatar_minutos` — Formatação para Exibição

```python
BancoDeHorasService.formatar_minutos(minutos: int) -> str
```

Converte um inteiro de minutos (positivo ou negativo) para string legível.

| Entrada | Saída |
|---|---|
| `150` | `"2h 30min"` |
| `-75` | `"-1h 15min"` |
| `0` | `"0h 00min"` |

---

### `obter_saldo_atual` — Saldo Atual do Colaborador *(stub)*

```python
BancoDeHorasService.obter_saldo_atual(funcionario_id: int) -> int
```

Retorna o saldo atual do banco de horas do colaborador em minutos.

> ⚠️ **Stub** — retorna `0` até que o modelo `PontoEletronico` seja portado.  
> A implementação real consultará `sistur_timebank_deductions` filtrando pelo `funcionario_id` e recuperando o `balance_after_minutes` mais recente.

---

## Integração com o Modelo `Funcionario`

O modelo `Funcionario` expõe dois helpers de conveniência que delegam para o service:

```python
funcionario.saldo_banco_horas()           # int — saldo atual em minutos (chama BancoDeHorasService)
funcionario.saldo_banco_horas_formatado() # str — ex. "2h 30min"
```

---

## Jornada por Dia — Precedência

O campo `Funcionario.jornada_semanal` (JSON) define minutos esperados e almoço por dia da semana.  
Quando presente, seus valores **sobrepõem** os globais `minutos_esperados_dia` e `minutos_almoco`.

A lógica de seleção da jornada correta para um dado dia é responsabilidade do `PontoService` (a ser implementado), que alimentará `calcular_saldo_dia` com os valores pertinentes.

```
Prioridade: jornada_semanal[dia].minutos  >  Funcionario.minutos_esperados_dia
```

---

## Regras de Negócio

1. **Unidade exclusiva: minutos inteiros** — nunca use `float` para representar horas.
2. **Almoço é descontado de ambos os lados** — hora efetiva = horas brutas − almoço real; jornada esperada = jornada bruta − almoço padrão.
3. **Saldo pode ser negativo** — indica que o colaborador está devendo horas ao banco.
4. **`aplicar_deducao` é exclusivo para abonos administrativos** — não use para registrar jornada diária.
5. **Jornada semanal tem prioridade** sobre os campos globais do `Funcionario`.
6. **Auditoria de deduções manuais** — toda dedução manual deve ser auditada via `AuditService` com `module="banco_horas"` antes de chamar `aplicar_deducao` *(a ser implementado junto com o PontoService)*.

---

## Roadmap — Pendências

| Item | Dependência |
|---|---|
| `obter_saldo_atual()` com query real | Portagem do model `PontoEletronico` e tabela `sistur_timebank_deductions` |
| Cálculo automático por período | Integração com `PontoService` (registros de entrada/saída) |
| Rotas de visualização (dashboard do colaborador) | Service completo + Blueprint do portal |
| Auditoria de deduções manuais | Service completo |
