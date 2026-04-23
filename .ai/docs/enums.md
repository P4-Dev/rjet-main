# Enums - Guideline

> **Regras para criação e uso de PHP Enums com Filament.**

---

## 1. Regra Fundamental

**Banco de dados = `string`**, **PHP = `Enum`**, **Filament = contratos**.

```php
// Migration: SEMPRE string, NUNCA enum()
$table->string('status', 20)->default('pending')->index();

// Model: cast para Enum
protected function casts(): array
{
    return ['status' => OrderStatus::class];
}
```

---

## 2. Estrutura Padrão

```
app/Enums/
├── OrderStatus.php          # Status de pedidos
├── PaymentMethod.php        # Métodos de pagamento
├── UserRole.php             # Papéis de usuário
└── Concerns/
    └── HasTranslatedLabels.php  # Trait para labels i18n
```

---

## 3. Enum Completo para Filament

### Com Contratos Filament

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('enums.order_status.pending'),
            self::Processing => __('enums.order_status.processing'),
            self::Shipped => __('enums.order_status.shipped'),
            self::Delivered => __('enums.order_status.delivered'),
            self::Cancelled => __('enums.order_status.cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processing => 'info',
            self::Shipped => 'primary',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Processing => 'heroicon-o-arrow-path',
            self::Shipped => 'heroicon-o-truck',
            self::Delivered => 'heroicon-o-check-circle',
            self::Cancelled => 'heroicon-o-x-circle',
        };
    }
}
```

---

## 4. Contratos Filament

### Quando Implementar Cada Contrato

| Contrato | Quando | Resultado no Filament |
|----------|--------|-----------------------|
| `HasLabel` | **Sempre** | Labels em selects, tabelas, badges |
| `HasColor` | Quando exibido em badge/status | Cor do badge/indicador |
| `HasIcon` | Quando precisa de ícone visual | Ícone ao lado do label |
| `HasDescription` | Quando usado em radio/select com descrição | Texto auxiliar |

### HasDescription (para radio buttons ou selects com descrição)

```php
use Filament\Support\Contracts\HasDescription;

enum SubscriptionPlan: string implements HasLabel, HasDescription
{
    case Basic = 'basic';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public function getLabel(): string
    {
        return match ($this) {
            self::Basic => __('enums.subscription.basic'),
            self::Pro => __('enums.subscription.pro'),
            self::Enterprise => __('enums.subscription.enterprise'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Basic => __('enums.subscription.basic_desc'),
            self::Pro => __('enums.subscription.pro_desc'),
            self::Enterprise => __('enums.subscription.enterprise_desc'),
        };
    }
}
```

---

## 5. Transições de Estado

### Método `canTransitionTo`

```php
enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    // ... cases e contratos ...

    /**
     * Define transições válidas entre estados.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Processing, self::Cancelled]),
            self::Processing => in_array($target, [self::Shipped, self::Cancelled]),
            self::Shipped => $target === self::Delivered,
            self::Delivered, self::Cancelled => false,
        };
    }

    /**
     * Retorna os estados possíveis a partir do atual.
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        return array_filter(self::cases(), fn (self $case) => $this->canTransitionTo($case));
    }
}
```

### Uso com Validação

```php
// No Service
public function updateStatus(Order $order, OrderStatus $newStatus): Order
{
    if (! $order->status->canTransitionTo($newStatus)) {
        throw OrderException::invalidTransition(
            $order->status->value,
            $newStatus->value,
        );
    }

    $order->update(['status' => $newStatus]);

    return $order;
}
```

### Uso em Filament Select (apenas transições válidas)

```php
Select::make('status')
    ->options(fn (Order $record): array => collect($record->status->allowedTransitions())
        ->mapWithKeys(fn (OrderStatus $status) => [$status->value => $status->getLabel()])
        ->all()
    )
```

---

## 6. Enum Simples (Sem Estado/Transição)

### Tipo/Categoria (sem cor/ícone)

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';
    case Pix = 'pix';
    case BankSlip = 'bank_slip';

    public function getLabel(): string
    {
        return match ($this) {
            self::CreditCard => __('enums.payment_method.credit_card'),
            self::DebitCard => __('enums.payment_method.debit_card'),
            self::Pix => __('enums.payment_method.pix'),
            self::BankSlip => __('enums.payment_method.bank_slip'),
        };
    }
}
```

### Boolean-like (ativo/inativo)

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActiveStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('enums.active_status.active'),
            self::Inactive => __('enums.active_status.inactive'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'danger',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
```

---

## 7. Uso no Filament

### Em Forms

```php
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Radio;

// Select (opções automáticas via HasLabel)
Select::make('status')
    ->options(OrderStatus::class)
    ->required(),

// Radio (com HasDescription para texto auxiliar)
Radio::make('plan')
    ->options(SubscriptionPlan::class)
    ->required(),

// ToggleButtons (visual compacto)
ToggleButtons::make('payment_method')
    ->options(PaymentMethod::class)
    ->inline()
    ->required(),
```

### Em Tables

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

// Coluna com badge (automático via HasColor + HasIcon)
TextColumn::make('status')
    ->badge(),

// Filtro por enum
SelectFilter::make('status')
    ->options(OrderStatus::class),

// Filtro múltiplo
SelectFilter::make('status')
    ->multiple()
    ->options(OrderStatus::class),
```

### Em Infolists

```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('status')
    ->badge(),
```

---

## 8. Helpers Úteis

### Listar Valores

```php
// Todos os values
OrderStatus::cases(); // [OrderStatus::Pending, ...]

// Values como array
array_column(OrderStatus::cases(), 'value'); // ['pending', 'processing', ...]

// Subset
$activeStatuses = [OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Shipped];
```

### Scopes no Model

```php
// No Model
public function scopeByStatus(Builder $query, OrderStatus $status): Builder
{
    return $query->where('status', $status);
}

public function scopeActive(Builder $query): Builder
{
    return $query->whereIn('status', [
        OrderStatus::Pending,
        OrderStatus::Processing,
        OrderStatus::Shipped,
    ]);
}
```

---

## 9. Traduções

```php
// lang/pt_BR/enums.php
return [
    'order_status' => [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'shipped' => 'Enviado',
        'delivered' => 'Entregue',
        'cancelled' => 'Cancelado',
    ],
    'payment_method' => [
        'credit_card' => 'Cartão de Crédito',
        'debit_card' => 'Cartão de Débito',
        'pix' => 'PIX',
        'bank_slip' => 'Boleto Bancário',
    ],
    'active_status' => [
        'active' => 'Ativo',
        'inactive' => 'Inativo',
    ],
];

// lang/en/enums.php
return [
    'order_status' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ],
    'payment_method' => [
        'credit_card' => 'Credit Card',
        'debit_card' => 'Debit Card',
        'pix' => 'PIX',
        'bank_slip' => 'Bank Slip',
    ],
    'active_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
];
```

---

## 10. Testes

```php
<?php

use App\Enums\OrderStatus;

describe('OrderStatus', function () {
    it('has correct values', function () {
        expect(OrderStatus::Pending->value)->toBe('pending');
        expect(OrderStatus::Cancelled->value)->toBe('cancelled');
    });

    it('returns translated labels', function () {
        expect(OrderStatus::Pending->getLabel())->toBeString()->not->toBeEmpty();
    });

    it('returns valid colors', function () {
        $validColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'gray'];

        foreach (OrderStatus::cases() as $case) {
            expect($validColors)->toContain($case->getColor());
        }
    });

    it('validates transitions correctly', function () {
        expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Processing))->toBeTrue();
        expect(OrderStatus::Pending->canTransitionTo(OrderStatus::Delivered))->toBeFalse();
        expect(OrderStatus::Delivered->canTransitionTo(OrderStatus::Pending))->toBeFalse();
    });

    it('returns allowed transitions', function () {
        $allowed = OrderStatus::Pending->allowedTransitions();

        expect($allowed)->toContain(OrderStatus::Processing);
        expect($allowed)->toContain(OrderStatus::Cancelled);
        expect($allowed)->not->toContain(OrderStatus::Delivered);
    });
});
```

---

## 11. Checklist

- [ ] Enum usa `string` backed values (nunca `int`)
- [ ] Migration usa APENAS `$table->string()` — `$table->enum()` é PROIBIDO no projeto
- [ ] Model tem `casts()` configurado para o Enum
- [ ] Implementa `HasLabel` (obrigatório)
- [ ] Implementa `HasColor` se usado em badges/status
- [ ] Implementa `HasIcon` se precisa de ícone visual
- [ ] Labels usam `__()` para traduções
- [ ] Arquivo `lang/pt_BR/enums.php` atualizado
- [ ] Arquivo `lang/en/enums.php` atualizado
- [ ] `canTransitionTo()` se enum representa estado com transições
- [ ] Testes criados para values, labels, colors, transições
