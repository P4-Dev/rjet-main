# Events e Listeners - Guideline

> **Regras para eventos de domínio, listeners, subscribers e model observers.**

---

## 1. Quando Usar Cada Padrão

| Padrão | Quando | Exemplo |
|--------|--------|---------|
| **Event + Listener** | Ação de negócio com efeitos colaterais | `OrderCreated` → envia email, atualiza estoque |
| **Model Observer** | Reação a lifecycle do Eloquent (creating, updating, deleting) | Gerar slug ao criar, limpar cache ao atualizar |
| **Event Subscriber** | Múltiplos listeners para o mesmo domínio | `OrderSubscriber` ouve Created, Shipped, Cancelled |

### Matriz de Decisão

| Pergunta | Event/Listener | Observer |
|----------|:--------------:|:--------:|
| É uma ação de negócio explícita? | ✅ | |
| É reação ao lifecycle do Model? | | ✅ |
| Precisa de Job assíncrono? | ✅ | |
| Precisa ser testado independentemente? | ✅ | |
| É um side-effect simples (slug, cache)? | | ✅ |
| Envolve múltiplos Models/Services? | ✅ | |

---

## 2. Estrutura de Diretórios

```
app/
├── Events/
│   ├── OrderCreated.php
│   ├── OrderShipped.php
│   └── PaymentReceived.php
├── Listeners/
│   ├── SendOrderConfirmation.php
│   ├── UpdateStockAfterOrder.php
│   └── NotifyAdminOnPayment.php
├── Subscribers/
│   └── OrderSubscriber.php
└── Observers/
    ├── OrderObserver.php
    └── ProductObserver.php
```

---

## 3. Event

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}
}
```

### Regras

1. **Nome no passado**: `OrderCreated`, `PaymentReceived`, `UserRegistered`
2. **Classe `final`** e propriedades `readonly`
3. **Dados mínimos**: passe o Model, não dados derivados
4. **Sem lógica**: events são apenas "mensageiros"

---

## 4. Listener

### Síncrono

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;

final class UpdateStockAfterOrder
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $this->stockService->decrementFromOrder($event->order);
    }
}
```

### Assíncrono (ShouldQueue)

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\OrderCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendOrderConfirmation implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function handle(OrderCreated $event): void
    {
        $event->order->customer->notify(
            new OrderCreatedNotification($event->order),
        );
    }

    public function failed(OrderCreated $event, \Throwable $exception): void
    {
        logger()->error('Failed to send order confirmation', [
            'order_id' => $event->order->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Quando Usar Queue

| Cenário | Queue? | Motivo |
|---------|--------|--------|
| Enviar email/notificação | Sim | I/O externo |
| Chamar API externa | Sim | I/O externo, pode falhar |
| Atualizar campo no banco | Não | Instantâneo |
| Limpar cache | Não | Instantâneo |
| Gerar PDF/relatório | Sim | CPU intensivo |

---

## 5. Registro de Listeners

### Auto-Discovery (Padrão Laravel 12)

Laravel 12 descobre listeners automaticamente por type-hint no método `handle()`. Nenhum registro manual necessário.

```php
// Basta criar o Listener com type-hint no handle()
final class SendOrderConfirmation
{
    public function handle(OrderCreated $event): void { ... }
}
// Laravel descobre automaticamente que este listener ouve OrderCreated
```

### Registro Manual (quando necessário)

```php
// app/Providers/EventServiceProvider.php (se existir)
// Ou bootstrap/app.php

use Illuminate\Support\Facades\Event;

Event::listen(OrderCreated::class, SendOrderConfirmation::class);
Event::listen(OrderCreated::class, UpdateStockAfterOrder::class);
```

---

## 6. Dispatching Events

### No Service

```php
final class OrderService
{
    public function create(OrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = Order::create([...]);

            // Dispatch APÓS a transaction
            OrderCreated::dispatch($order);

            return $order;
        });
    }
}
```

### IMPORTANTE: Dispatch Após Transaction

```php
// PROIBIDO - event pode rodar antes do commit
DB::transaction(function () {
    $order = Order::create([...]);
    OrderCreated::dispatch($order); // Listener pode não encontrar o registro!
});

// CORRETO - usar afterCommit (para listeners queued)
final class SendOrderConfirmation implements ShouldQueue
{
    public bool $afterCommit = true;
    // ...
}

// OU dispatch fora da transaction
$order = DB::transaction(fn () => Order::create([...]));
OrderCreated::dispatch($order);
```

---

## 7. Event Subscriber

Quando um domínio tem muitos events relacionados, agrupe em um Subscriber.

```php
<?php

declare(strict_types=1);

namespace App\Subscribers;

use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderShipped;
use Illuminate\Events\Dispatcher;

final class OrderSubscriber
{
    public function handleOrderCreated(OrderCreated $event): void
    {
        logger()->info('Order created', ['order_id' => $event->order->id]);

        // Notificar admin, atualizar dashboard, etc.
    }

    public function handleOrderShipped(OrderShipped $event): void
    {
        logger()->info('Order shipped', ['order_id' => $event->order->id]);

        // Notificar cliente, atualizar tracking, etc.
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        logger()->info('Order cancelled', ['order_id' => $event->order->id]);

        // Restaurar estoque, reembolsar, etc.
    }

    /**
     * Registrar os listeners do subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(OrderCreated::class, [self::class, 'handleOrderCreated']);
        $events->listen(OrderShipped::class, [self::class, 'handleOrderShipped']);
        $events->listen(OrderCancelled::class, [self::class, 'handleOrderCancelled']);
    }
}
```

### Registro do Subscriber

```php
// bootstrap/providers.php ou EventServiceProvider
use Illuminate\Support\Facades\Event;

Event::subscribe(OrderSubscriber::class);
```

---

## 8. Model Observer

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ProductObserver
{
    public function creating(Product $product): void
    {
        // Gerar slug antes de criar
        if (empty($product->slug)) {
            $product->slug = Str::slug($product->name);
        }
    }

    public function saved(Product $product): void
    {
        // Limpar cache ao salvar (create ou update)
        Cache::forget("product:{$product->id}");
        Cache::tags(['products'])->flush();
    }

    public function deleted(Product $product): void
    {
        // Limpar cache ao deletar
        Cache::forget("product:{$product->id}");
        Cache::tags(['products'])->flush();
    }
}
```

### Registro

```php
// No Model
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(ProductObserver::class)]
final class Product extends Model
{
    // ...
}
```

### Hooks Disponíveis

| Hook | Quando | Uso Comum |
|------|--------|-----------|
| `creating` | Antes de INSERT | Gerar slug, UUID, defaults |
| `created` | Após INSERT | Log, dispatch event |
| `updating` | Antes de UPDATE | Validar transição de status |
| `updated` | Após UPDATE | Limpar cache, log |
| `saving` | Antes de INSERT/UPDATE | Sanitizar dados |
| `saved` | Após INSERT/UPDATE | Limpar cache |
| `deleting` | Antes de DELETE | Verificar dependências |
| `deleted` | Após DELETE | Limpar cache, log |
| `restoring` | Antes de restore (SoftDelete) | Revalidar dados |
| `restored` | Após restore (SoftDelete) | Recalcular agregações |

### Observer vs Event: Regra Prática

```
Observer → Efeitos "automáticos" do Model (slug, cache, defaults, log simples)
Event   → Ações de negócio explícitas (notificar, processar, integrar)
```

---

## 9. Padrões no Projeto

### Nomenclatura

| Tipo | Convenção | Exemplo |
|------|-----------|---------|
| Event | `{Model}{Ação}` (passado) | `OrderCreated`, `PaymentReceived` |
| Listener | `{Verbo}{Complemento}` | `SendOrderConfirmation`, `UpdateStock` |
| Subscriber | `{Domínio}Subscriber` | `OrderSubscriber` |
| Observer | `{Model}Observer` | `ProductObserver` |

### Onde Dispatchar

| Camada | Dispatch? | Motivo |
|--------|-----------|--------|
| Service | **Sim** | Ponto central de lógica de negócio |
| Controller | Não | Delegue ao Service |
| Model | Não | Use Observer para lifecycle |
| Job | Depende | Se o Job realiza uma ação de negócio |
| Observer | Não | Observer reage, não inicia fluxos |

---

## 10. Testes

### Testar que Event foi Disparado

```php
<?php

use App\Events\OrderCreated;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Event;

it('dispatches OrderCreated event when order is created', function () {
    Event::fake([OrderCreated::class]);

    $service = app(OrderService::class);
    $order = $service->create($data);

    Event::assertDispatched(OrderCreated::class, function ($event) use ($order) {
        return $event->order->id === $order->id;
    });
});

it('does not dispatch event on update', function () {
    Event::fake([OrderCreated::class]);

    $order = Order::factory()->create();
    $service = app(OrderService::class);
    $service->update($order, $data);

    Event::assertNotDispatched(OrderCreated::class);
});
```

### Testar Listener Isoladamente

```php
<?php

use App\Events\OrderCreated;
use App\Listeners\UpdateStockAfterOrder;
use App\Models\Order;
use App\Models\Product;

it('decrements stock when order is created', function () {
    $product = Product::factory()->create(['stock' => 10]);
    $order = Order::factory()
        ->hasItems(1, ['product_id' => $product->id, 'quantity' => 3])
        ->create();

    $listener = app(UpdateStockAfterOrder::class);
    $listener->handle(new OrderCreated($order));

    expect($product->fresh()->stock)->toBe(7);
});
```

### Testar Observer

```php
<?php

use App\Models\Product;

it('generates slug on creation', function () {
    $product = Product::factory()->create(['name' => 'Produto Teste', 'slug' => null]);

    expect($product->slug)->toBe('produto-teste');
});

it('clears cache on update', function () {
    $product = Product::factory()->create();
    Cache::put("product:{$product->id}", $product);

    $product->update(['name' => 'Updated']);

    expect(Cache::has("product:{$product->id}"))->toBeFalse();
});
```

---

## 11. Checklist

- [ ] Events nomeados no passado (`OrderCreated`, não `CreateOrder`)
- [ ] Events são `final` com propriedades `readonly`
- [ ] Listeners com `ShouldQueue` para I/O externo
- [ ] Listeners com `$afterCommit = true` se queued dentro de transaction
- [ ] Observer registrado via `#[ObservedBy]` no Model
- [ ] Observer usado apenas para lifecycle (cache, slug, defaults)
- [ ] Events dispatchados no Service, não no Controller
- [ ] Subscriber para domínios com 3+ events relacionados
- [ ] Testes com `Event::fake()` para dispatch
- [ ] Testes de listener isolados (sem fake)
