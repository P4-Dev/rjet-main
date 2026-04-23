# Skill: Testing com Pest

## Quando Usar

Use este skill quando precisar:
- Criar testes com Pest
- Testar Filament Resources
- Testar APIs
- Testar Services e Actions
- Configurar mocks e fakes

## Importante

Este projeto usa **Pest** (não PHPUnit). Todos os testes devem usar sintaxe Pest.
Use `php artisan make:test --pest {name}` para criar novos testes.
Use `Livewire::test()` para testar componentes Filament/Livewire.

## Estrutura de Testes

```
tests/
├── Pest.php                    # Configuração global do Pest
├── TestCase.php                # Base test case
├── Feature/
│   ├── Filament/               # Testes de Resources Filament
│   │   ├── OrderResourceTest.php
│   │   └── CustomerResourceTest.php
│   ├── Api/                    # Testes de API
│   │   └── OrderApiTest.php
│   └── Services/               # Testes de integração de Services
│       └── OrderServiceTest.php
├── Unit/
│   ├── DTOs/                   # Testes de DTOs
│   ├── Services/               # Testes unitários de Services
│   ├── Actions/                # Testes de Actions
│   └── Models/                 # Testes de Models (scopes, casts)
```

## Padrões de Teste

### Estrutura Básica

```php
<?php

use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates with valid data', function () {
    // Arrange
    $data = ['name' => 'Test'];

    // Act
    $result = $this->service->create($data);

    // Assert
    expect($result)->toBeInstanceOf(Order::class);
});

it('fails with invalid data', function () {
    $this->service->create([]);
})->throws(ValidationException::class);
```

### Nomenclatura

```php
// Bom - descreve comportamento
it('creates an order with items')
it('sends notification after creation')
it('denies access to non admin users')
it('validates email format')

// Ruim - vago ou técnico demais
it('creates')
it('works')
```

## Filament Testing

### Resource List

```php
<?php

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

it('renders the list page', function () {
    Livewire::test(ListOrders::class)
        ->assertSuccessful();
});

it('displays records', function () {
    $orders = Order::factory()->count(3)->create();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords($orders);
});

it('searches by customer name', function () {
    $order = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->searchTable($order->customer->name)
        ->assertCanSeeTableRecords([$order]);
});

it('filters by status', function () {
    $pending = Order::factory()->pending()->create();
    $shipped = Order::factory()->shipped()->create();

    Livewire::test(ListOrders::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$shipped]);
});

it('sorts by created_at', function () {
    $old = Order::factory()->create(['created_at' => now()->subDay()]);
    $new = Order::factory()->create();

    Livewire::test(ListOrders::class)
        ->sortTable('created_at', 'desc')
        ->assertCanSeeTableRecords([$new, $old], inOrder: true);
});
```

### Resource Create

```php
use App\Filament\Resources\OrderResource\Pages\CreateOrder;
use App\Models\Customer;

use function Pest\Laravel\assertDatabaseHas;

it('renders the create page', function () {
    Livewire::test(CreateOrder::class)
        ->assertSuccessful();
});

it('creates a record', function () {
    $customer = Customer::factory()->create();

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'notes' => 'Test order',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('orders', ['customer_id' => $customer->id]);
});

it('validates required fields', function () {
    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_id' => null])
        ->call('create')
        ->assertHasFormErrors(['customer_id' => 'required']);
});
```

### Resource Edit

```php
use App\Filament\Resources\OrderResource\Pages\EditOrder;

it('renders with record data', function () {
    $order = Order::factory()->create(['notes' => 'Original']);

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->assertFormSet(['notes' => 'Original']);
});

it('updates a record', function () {
    $order = Order::factory()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->fillForm(['notes' => 'Updated'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->notes)->toBe('Updated');
});

it('deletes a record', function () {
    $order = Order::factory()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->callAction('delete');

    expect(Order::find($order->id))->toBeNull();
});
```

### Actions

```php
it('executes ship action', function () {
    $order = Order::factory()->processing()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->callAction('ship')
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

it('hides ship action for pending orders', function () {
    $order = Order::factory()->pending()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->assertActionHidden('ship');
});

it('executes action with form data', function () {
    $order = Order::factory()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->callAction('cancel', data: [
            'reason' => 'Customer request',
        ])
        ->assertHasNoActionErrors();

    expect($order->fresh()->cancellation_reason)->toBe('Customer request');
});
```

## API Testing

```php
<?php

use App\Models\Order;
use App\Models\User;
use App\Models\Customer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\deleteJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns paginated list', function () {
    Order::factory()->count(15)->create();

    actingAs($this->user)
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'customer_id', 'status']],
            'meta' => ['current_page', 'total'],
        ]);
});

it('requires authentication', function () {
    getJson('/api/orders')
        ->assertUnauthorized();
});

it('filters by status', function () {
    Order::factory()->pending()->count(2)->create();
    Order::factory()->shipped()->create();

    actingAs($this->user)
        ->getJson('/api/orders?status=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('creates an order', function () {
    $customer = Customer::factory()->create();

    actingAs($this->user)
        ->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_id' => 'prod_1', 'quantity' => 2],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id);
});

it('validates request', function () {
    actingAs($this->user)
        ->postJson('/api/orders', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id', 'items']);
});
```

## Mocking

### HTTP Fake

```php
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'api.stripe.com/*' => Http::response([
            'id' => 'ch_123',
            'status' => 'succeeded',
        ]),
    ]);
});

it('processes payment', function () {
    $result = app(PaymentIntegration::class)->charge(...);

    expect($result->status)->toBe('succeeded');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.stripe.com/charges';
    });
});
```

### Event Fake

```php
use Illuminate\Support\Facades\Event;

it('dispatches event after creation', function () {
    Event::fake([OrderCreated::class]);

    $this->service->create($data);

    Event::assertDispatched(OrderCreated::class, function ($event) {
        return $event->order->id !== null;
    });
});
```

### Queue Fake

```php
use Illuminate\Support\Facades\Queue;

it('queues job', function () {
    Queue::fake();

    $this->service->processAsync($order);

    Queue::assertPushed(ProcessOrderJob::class, function ($job) use ($order) {
        return $job->order->id === $order->id;
    });
});
```

### Notification Fake

```php
use Illuminate\Support\Facades\Notification;

it('sends notification', function () {
    Notification::fake();

    $this->service->complete($order);

    Notification::assertSentTo(
        $order->customer,
        OrderCompletedNotification::class,
    );
});
```

### Time Manipulation

```php
it('expires after 30 days', function () {
    $order = Order::factory()->create();

    $this->travel(31)->days();

    expect($order->fresh()->isExpired())->toBeTrue();
});

it('uses frozen time', function () {
    $this->freezeTime();

    $order = Order::factory()->create();

    expect($order->created_at->timestamp)->toBe(now()->timestamp);
});
```

## Assertions Comuns (expect)

```php
// Existência
expect(Order::find($id))->not->toBeNull();
expect(Order::where('status', 'active')->exists())->toBeTrue();

// Valores
expect($order->status)->toBe(OrderStatus::Pending);
expect($order->total)->toBe(100.50);
expect($order->items)->toHaveCount(3);

// Tipos
expect($result)->toBeInstanceOf(Order::class);

// Exceções
expect(fn () => $this->service->create([]))
    ->toThrow(ValidationException::class);
```

## Factories

### States

```php
// database/factories/OrderFactory.php

public function pending(): static
{
    return $this->state(['status' => OrderStatus::Pending]);
}

public function shipped(): static
{
    return $this->state(['status' => OrderStatus::Shipped]);
}

public function withItems(int $count = 3): static
{
    return $this->has(OrderItem::factory()->count($count), 'items');
}

public function forCustomer(Customer $customer): static
{
    return $this->state(['customer_id' => $customer->id]);
}
```

### Uso

```php
// Estados simples
Order::factory()->pending()->create();
Order::factory()->shipped()->create();

// Com relacionamentos
Order::factory()->withItems(5)->create();

// Combinados
Order::factory()
    ->pending()
    ->withItems(3)
    ->forCustomer($customer)
    ->create();
```
