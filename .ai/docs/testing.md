# Padrões de Testes com Pest

> Esta guideline define os padrões de testes para projetos Laravel com Pest.
> Este projeto usa Pest (não PHPUnit). Crie testes via `php artisan make:test --pest`.

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

## Testes de Filament Resources

### Estrutura Base

```php
<?php

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\{CreateOrder, EditOrder, ListOrders};
use App\Models\{Customer, Order, User};
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

describe('list page', function () {
    it('renders successfully', function () {
        Livewire::test(ListOrders::class)
            ->assertSuccessful();
    });

    it('lists orders', function () {
        $orders = Order::factory()->count(3)->create();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords($orders);
    });

    it('searches orders by customer name', function () {
        $order = Order::factory()->create();

        Livewire::test(ListOrders::class)
            ->searchTable($order->customer->name)
            ->assertCanSeeTableRecords([$order]);
    });

    it('filters orders by status', function () {
        $pending = Order::factory()->pending()->create();
        $shipped = Order::factory()->shipped()->create();

        Livewire::test(ListOrders::class)
            ->filterTable('status', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$shipped]);
    });

    it('sorts orders by created_at', function () {
        $old = Order::factory()->create(['created_at' => now()->subDays(2)]);
        $new = Order::factory()->create(['created_at' => now()]);

        Livewire::test(ListOrders::class)
            ->sortTable('created_at', 'desc')
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    });
});

describe('create page', function () {
    it('renders successfully', function () {
        Livewire::test(CreateOrder::class)
            ->assertSuccessful();
    });

    it('creates an order', function () {
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
            ->fillForm([
                'customer_id' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['customer_id' => 'required']);
    });
});

describe('edit page', function () {
    it('renders successfully', function () {
        $order = Order::factory()->create();

        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->assertSuccessful();
    });

    it('updates an order', function () {
        $order = Order::factory()->create();

        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->fillForm([
                'notes' => 'Updated notes',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($order->fresh()->notes)->toBe('Updated notes');
    });

    it('deletes an order', function () {
        $order = Order::factory()->create();

        Livewire::test(EditOrder::class, ['record' => $order->id])
            ->callAction('delete');

        expect(Order::find($order->id))->toBeNull();
    });

    it('bulk deletes orders', function () {
        $orders = Order::factory()->count(3)->create();

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('delete', $orders);

        expect(Order::count())->toBe(0);
    });
});

describe('authorization', function () {
    it('denies access to non admin users', function () {
        actingAs(User::factory()->create());

        Livewire::test(ListOrders::class)
            ->assertForbidden();
    });
});
```

### Testando Actions

```php
it('ships an order', function () {
    $order = Order::factory()->processing()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->callAction('ship')
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);
});

it('cannot ship a pending order', function () {
    $order = Order::factory()->pending()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->assertActionHidden('ship');
});

it('cancels an order with reason', function () {
    $order = Order::factory()->pending()->create();

    Livewire::test(EditOrder::class, ['record' => $order->id])
        ->callAction('cancel', data: [
            'reason' => 'Customer requested',
        ])
        ->assertHasNoActionErrors();

    expect($order->fresh())
        ->status->toBe(OrderStatus::Cancelled)
        ->cancellation_reason->toBe('Customer requested');
});
```

## Testes de Services

```php
<?php

use App\DTOs\OrderData;
use App\Events\OrderCreated;
use App\Models\{Customer, Order};
use App\Services\OrderService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = app(OrderService::class);
});

it('creates an order from dto', function () {
    $customer = Customer::factory()->create();
    $data = new OrderData(
        customer_id: $customer->id,
        items: [
            ['product_id' => 'prod_1', 'quantity' => 2],
        ],
        notes: 'Test order',
    );

    $order = $this->service->create($data);

    expect($order)
        ->toBeInstanceOf(Order::class)
        ->customer_id->toBe($customer->id)
        ->notes->toBe('Test order');
});

it('dispatches order created event', function () {
    Event::fake([OrderCreated::class]);

    $customer = Customer::factory()->create();
    $data = new OrderData(customer_id: $customer->id, items: []);

    $this->service->create($data);

    Event::assertDispatched(OrderCreated::class);
});

it('creates order items', function () {
    $customer = Customer::factory()->create();
    $data = new OrderData(
        customer_id: $customer->id,
        items: [
            ['product_id' => 'prod_1', 'quantity' => 2],
            ['product_id' => 'prod_2', 'quantity' => 1],
        ],
    );

    $order = $this->service->create($data);

    expect($order->items)->toHaveCount(2);
});

it('wraps in transaction', function () {
    $customer = Customer::factory()->create();
    $data = new OrderData(
        customer_id: $customer->id,
        items: [['invalid' => 'data']],
    );

    try {
        $this->service->create($data);
    } catch (\Exception) {
        // Expected
    }

    expect(Order::count())->toBe(0);
});

it('updates an order', function () {
    $order = Order::factory()->create(['notes' => 'old']);
    $data = new OrderData(
        customer_id: $order->customer_id,
        items: [],
        notes: 'new notes',
    );

    $updated = $this->service->update($order, $data);

    expect($updated->notes)->toBe('new notes');
});

it('soft deletes an order', function () {
    $order = Order::factory()->create();

    $this->service->delete($order);

    expect(Order::find($order->id))->toBeNull();
    expect(Order::withTrashed()->find($order->id))->not->toBeNull();
});
```

## Testes de API

```php
<?php

use App\Models\{Customer, Order, User};

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\deleteJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns paginated orders', function () {
    Order::factory()->count(15)->create();

    actingAs($this->user)
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'customer_id', 'status', 'created_at'],
            ],
            'meta' => ['current_page', 'total'],
        ]);
});

it('requires authentication', function () {
    getJson('/api/orders')
        ->assertUnauthorized();
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
        ->assertJsonValidationErrors(['customer_id']);
});

it('updates an order', function () {
    $order = Order::factory()->create();

    actingAs($this->user)
        ->putJson("/api/orders/{$order->id}", [
            'notes' => 'updated',
        ])
        ->assertOk()
        ->assertJsonPath('data.notes', 'updated');
});

it('returns 404 for non existent order', function () {
    actingAs($this->user)
        ->putJson('/api/orders/non-existent', [])
        ->assertNotFound();
});

it('deletes an order', function () {
    $order = Order::factory()->create();

    actingAs($this->user)
        ->deleteJson("/api/orders/{$order->id}")
        ->assertNoContent();

    expect(Order::find($order->id))->toBeNull();
});
```

## Mocking Externo

```php
<?php

use App\Integrations\Asaas\AsaasPaymentIntegration;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'api.asaas.com/*' => Http::response([
            'id' => 'pay_123',
            'status' => 'succeeded',
        ]),
    ]);
});

it('processes payment via integration', function () {
    $service = app(PaymentService::class);
    $result = $service->charge(new PaymentData(
        amount: 1000,
        currency: 'brl',
        token: 'tok_visa',
    ));

    expect($result->status)->toBe('succeeded');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.asaas.com')
            && $request['amount'] === 1000;
    });
});
```

## Convenções

### Nomenclatura de Testes

```php
// BOM - descreve o comportamento
it('creates an order with items')
it('dispatches event after creation')
it('denies access to non admin users')

// RUIM - não descreve comportamento
it('creates')
it('works')
```

### Estrutura de Arquivo

- Um arquivo de teste por classe/contexto
- Use `beforeEach` para setup comum
- Use `describe` para agrupar por contexto
- Use `expect()` para assertions fluentes
- Use helpers de Pest: `actingAs()`, `assertDatabaseHas()`, etc.

### Factories

- Crie estados para cenários comuns: `pending()`, `shipped()`, `admin()`
- Use traits para estados reutilizáveis
- Evite dados hardcoded, use Faker
