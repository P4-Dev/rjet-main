# Padrões de Arquitetura Laravel

> Esta guideline define os padrões de arquitetura para projetos Laravel.
> Consulte PROJECT.md para configurações específicas do projeto.

## Estrutura de Camadas

```
┌─────────────────────────────────────────────────────────────┐
│                      PRESENTATION                           │
│  Controllers, Filament Resources, API Resources, Views      │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                      APPLICATION                            │
│  Services, Actions, DTOs, Form Requests                     │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                        DOMAIN                               │
│  Models, Policies, Events, Observers                        │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                     INFRASTRUCTURE                          │
│  Repositories, Integrations, Jobs, External APIs             │
└─────────────────────────────────────────────────────────────┘
```

## Data Transfer Objects (DTOs)

DTOs transportam dados entre camadas de forma tipada e imutável.

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

use App\DTOs\Concerns\FromArray;
use App\DTOs\Concerns\FromRequest;

final readonly class OrderData
{
    use FromArray, FromRequest;

    public function __construct(
        public string $customer_id,
        public array $items,
        public ?string $notes = null,
        public ?string $id = null,
    ) {}
}
```

### Traits para DTOs

```php
// app/DTOs/Concerns/FromArray.php
trait FromArray
{
    public static function fromArray(array $data): static
    {
        return new static(...$data);
    }
}

// app/DTOs/Concerns/FromRequest.php
trait FromRequest
{
    public static function fromRequest(Request $request): static
    {
        return new static(...$request->validated());
    }
}
```

## Services

Services orquestram lógica de negócio e coordenam operações.

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\OrderData;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(
        private readonly OrderItemService $itemService,
    ) {}

    public function create(OrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = Order::create([
                'customer_id' => $data->customer_id,
                'notes' => $data->notes,
                'status' => OrderStatus::Pending,
            ]);

            $this->itemService->createMany($order, $data->items);

            OrderCreated::dispatch($order);

            return $order->fresh();
        });
    }

    public function update(Order $order, OrderData $data): Order
    {
        // ...
    }

    public function delete(Order $order): void
    {
        // ...
    }
}
```

### Regras

- Services são `final` e injetados via constructor
- Usam transactions para operações múltiplas
- Disparam events após operações importantes
- Não acessam Request diretamente (recebem DTOs)
- Retornam Models ou coleções, nunca arrays

## Actions

Actions são classes de responsabilidade única para operações específicas.

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\OrderData;
use App\Models\Order;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateOrderAction
{
    use AsAction;

    public function __construct(
        private readonly OrderService $service,
    ) {}

    public function handle(OrderData $data): Order
    {
        return $this->service->create($data);
    }

    // Para uso como Job
    public function asJob(OrderData $data): void
    {
        $this->handle($data);
    }

    // Para uso como Controller
    public function asController(CreateOrderRequest $request): OrderResource
    {
        $order = $this->handle(OrderData::fromRequest($request));
        
        return new OrderResource($order);
    }
}
```

### Quando usar Actions vs Services

| Use Action quando... | Use Service quando... |
|---------------------|----------------------|
| Operação única e específica | Múltiplas operações relacionadas |
| Precisa ser Job/Controller | Só será chamado internamente |
| Lógica pode ser reutilizada | Orquestração de outras services |

## Models

### Estrutura Base

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Order extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'status',
        'notes',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total' => 'decimal:2',
        ];
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Scopes
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending);
    }

    // Accessors & Mutators via Attribute
    protected function formattedTotal(): Attribute
    {
        return Attribute::get(fn () => 
            Number::currency($this->total)
        );
    }
}
```

### Traits Comuns

```php
// app/Models/Concerns/HasUuid.php
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::uuid()->toString();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
```

## Enums

Use Enums PHP para valores fixos. No banco, sempre `string`, nunca `enum`.

### Migration (banco = string)

```php
// ✅ CORRETO - string no banco
$table->string('status', 20)->default('pending')->index();

// ❌ ERRADO - nunca use enum no banco
$table->enum('status', ['pending', 'active']); // NÃO FAÇA ISSO
```

### Enum PHP

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
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Shipped => 'Enviado',
            self::Delivered => 'Entregue',
            self::Cancelled => 'Cancelado',
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

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending => in_array($status, [self::Processing, self::Cancelled]),
            self::Processing => in_array($status, [self::Shipped, self::Cancelled]),
            self::Shipped => $status === self::Delivered,
            default => false,
        };
    }
}
```

### Model (cast para Enum)

```php
// No Model
protected function casts(): array
{
    return [
        'status' => OrderStatus::class,  // Cast automático string ↔ Enum
    ];
}
```

### Uso

```php
// Criar com enum
Order::create(['status' => OrderStatus::Pending]);

// Comparar
if ($order->status === OrderStatus::Pending) { ... }

// Transição
if ($order->status->canTransitionTo(OrderStatus::Shipped)) { ... }

// Label para exibição
$order->status->getLabel(); // "Pendente"
```

## Integrations (APIs Externas)

Integrations encapsulam comunicação com qualquer serviço externo (pagamentos, CRM, ERP, notificações, etc).

### Estrutura

```
app/Integrations/
├── Contracts/
│   └── PaymentIntegration.php      # Interface
├── Asaas/
│   └── AsaasPaymentIntegration.php # Implementação Asaas
├── Stripe/
│   └── StripePaymentIntegration.php # Implementação Stripe
└── Notifications/
    └── TwilioIntegration.php       # Outra integração
```

### Interface (Contrato)

```php
<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\DTOs\PaymentData;
use App\DTOs\PaymentResultData;

interface PaymentIntegration
{
    public function charge(PaymentData $data): PaymentResultData;
    public function refund(string $transactionId): bool;
}
```

### Implementação

```php
<?php

declare(strict_types=1);

namespace App\Integrations\Asaas;

use App\DTOs\PaymentData;
use App\DTOs\PaymentResultData;
use App\Integrations\Contracts\PaymentIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class AsaasPaymentIntegration implements PaymentIntegration
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.asaas.url'))
            ->withToken(config('services.asaas.token'))
            ->timeout(30)
            ->retry(3, 100)
            ->acceptJson();
    }

    public function charge(PaymentData $data): PaymentResultData
    {
        $response = $this->client->post('/payments', [
            'customer' => $data->customerId,
            'value' => $data->amount,
            'billingType' => $data->method,
        ]);

        return PaymentResultData::fromArray($response->json());
    }

    public function refund(string $transactionId): bool
    {
        $response = $this->client->post("/payments/{$transactionId}/refund");
        return $response->successful();
    }
}
```

### Registro no Container

```php
// AppServiceProvider
$this->app->bind(
    PaymentIntegration::class,
    AsaasPaymentIntegration::class
);
```

## Jobs

Jobs processam tarefas assíncronas.

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function handle(OrderService $service): void
    {
        $service->process($this->order);
    }

    public function failed(\Throwable $exception): void
    {
        // Log, notify, etc.
    }
}
```

## Convenções Gerais

### Nomenclatura

| Tipo | Convenção | Exemplo |
|------|-----------|---------|
| Model | Singular, PascalCase | `Order` |
| Table | Plural, snake_case | `orders` |
| DTO | PascalCase + Data | `OrderData` |
| Service | PascalCase + Service | `OrderService` |
| Action | Verbo + PascalCase + Action | `CreateOrderAction` |
| Job | Verbo + PascalCase + Job | `ProcessOrderJob` |
| Event | PascalCase + Passado | `OrderCreated` |
| Policy | PascalCase + Policy | `OrderPolicy` |
| Enum | PascalCase (sem sufixo) | `OrderStatus` |
| Interface | PascalCase (sem prefixo I) | `PaymentIntegration` |

### Imports

- Use imports explícitos, nunca `use App\Models\*`
- Ordene: PHP nativo → Laravel → Pacotes → App
- Um import por linha

### Tipagem

- Use `declare(strict_types=1)` em todos os arquivos
- Type hints em todos os parâmetros e retornos
- Use `readonly` para propriedades imutáveis
- Use `final` para classes que não devem ser estendidas
