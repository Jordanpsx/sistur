# Justificativa de Faltas - Documentação

## Visão Geral

Esta funcionalidade permite que administradores justifiquem ausências de funcionários. Quando uma falta é justificada, o banco de horas "perdoa" a ausência, ou seja, não desconta as horas esperadas do dia.

## Como Funciona

### 1. Banco de Dados

Foi adicionada a coluna `is_justified` na tabela `wp_sistur_schedule_exceptions`:
- `is_justified = 0`: Falta NÃO justificada (desconta do banco de horas)
- `is_justified = 1`: Falta JUSTIFICADA (não desconta do banco de horas)

### 2. Lógica de Cálculo

Quando uma exceção do tipo 'absence' é marcada como justificada:
- `custom_expected_minutes = 0` (não desconta horas do banco)
- `status = 'approved'`
- `is_justified = 1`

Quando uma falta NÃO é justificada:
- `custom_expected_minutes = [horas esperadas do dia]` (desconta normalmente)
- `is_justified = 0`

### 3. Impressão de Ponto

Na impressão da folha de ponto, quando há uma falta justificada:
- Aparece `(FALTA JUSTIFICADA)` na coluna de observações
- Se houver uma justificativa (notes), ela também é exibida

## Uso via PHP

### Justificar uma Falta

```php
// Obter instância da classe
$exceptions = SISTUR_Schedule_Exceptions::get_instance();

// Justificar falta
$result = $exceptions->justify_absence(
    $employee_id,      // ID do funcionário
    '2025-12-02',      // Data da falta (Y-m-d)
    'Consulta médica'  // Justificativa (opcional)
);

if ($result) {
    echo "Falta justificada com sucesso!";
}
```

### Remover Justificativa

```php
// Obter instância da classe
$exceptions = SISTUR_Schedule_Exceptions::get_instance();

// Remover justificativa
$result = $exceptions->unjustify_absence(
    $employee_id,      // ID do funcionário
    '2025-12-02'       // Data da falta (Y-m-d)
);

if ($result) {
    echo "Justificativa removida. Horas descontadas do banco.";
}
```

## Uso via AJAX

### Justificar Falta

```javascript
jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'sistur_justify_absence',
        nonce: sistur_nonce,
        employee_id: 123,
        date: '2025-12-02',
        justification: 'Consulta médica'
    },
    success: function(response) {
        if (response.success) {
            alert('Falta justificada com sucesso!');
        }
    }
});
```

### Remover Justificativa

```javascript
jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'sistur_unjustify_absence',
        nonce: sistur_nonce,
        employee_id: 123,
        date: '2025-12-02'
    },
    success: function(response) {
        if (response.success) {
            alert('Justificativa removida!');
        }
    }
});
```

## Exemplo Prático

### Cenário 1: Funcionário com jornada de 8h/dia falta sem justificativa

1. Funcionário não bate ponto no dia 02/12/2025
2. Banco de horas: **-8h** (desconta 8 horas)
3. Na impressão: horários vazios, banco negativo

### Cenário 2: Funcionário com jornada de 8h/dia falta COM justificativa

1. Funcionário não bate ponto no dia 02/12/2025
2. Administrador justifica a falta:
   ```php
   $exceptions->justify_absence(123, '2025-12-02', 'Atestado médico');
   ```
3. Banco de horas: **0h** (não desconta)
4. Na impressão: aparece **(FALTA JUSTIFICADA) - Atestado médico**

## Migração

A migração é executada automaticamente ao ativar/reativar o plugin. O campo `is_justified` é adicionado à tabela `wp_sistur_schedule_exceptions`.

## Notas Importantes

- Apenas administradores (`manage_options`) podem justificar faltas via AJAX
- Ao justificar/desjustificar uma falta, o dia é **reprocessado automaticamente** para atualizar o banco de horas
- A justificativa não afeta dias com batidas de ponto (apenas ausências completas)
- Funcionários horistas/diaristas também podem ter faltas justificadas da mesma forma

## Versão

Funcionalidade implementada na versão **1.5.0**
