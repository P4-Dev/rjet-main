# Factories e Seeders - Guideline

> **Regras para criação e uso de Factories e Seeders no projeto Laravel 12 + Filament v5.**
> Factories são para testes. Seeders são para dados iniciais. Toda Model DEVE ter Factory.

---

## 1. Regra Fundamental

**Toda Model DEVE ter uma Factory.** Sem exceção.

| Conceito | Propósito | Quando usar |
|----------|-----------|-------------|
| Factory | Gerar dados de teste | Testes (Pest), tinker, prototipagem |
| Seeder | Popular dados iniciais/essenciais | Setup do ambiente, deploy, dados de referência |

```php
// CORRETO - usar factory em testes
$order = Order::factory()->create();

// PROIBIDO - nunca usar Model::create() diretamente em testes
$order = Order::create(['customer_id' => $customer->id, 'status' => 'pending']);
```

**Regra:** ao criar uma Model via `php artisan make:model`, sempre passe `--factory --seeder`:

```bash
php artisan make:model Product --factory --seeder --no-interaction
```

---

## 2. Estrutura de Factory

### Diretório

```
database/factories/
├── UserFactory.php
├── CustomerFactory.php
├── OrderFactory.php
├── OrderItemFactory.php
└── ProductFactory.php
```

### Estrutura Base

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            'notes' => fake()->optional()->sentence(),
            'total' => fake()->randomFloat(2, 10, 5000),
            'shipped_at' => null,
        ];
    }
}
```

### Regras da Estrutura

1. **`final class`** - factories não devem ser estendidas
2. **`declare(strict_types=1)`** - obrigatório
3. **PHPDoc `@extends`** - para type safety
4. **`$model` explícito** - mesmo que Laravel resolva automaticamente
5. **Return type `array<string, mixed>`** no `definition()`
6. **Trait `HasFactory`** obrigatório no Model:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;
}
```

---

## 3. States

States definem variantes da Model. Use nomes descritivos e verbais.

### Convenção de Nomes

| Padrão | Exemplo | Uso |
|--------|---------|-----|
| Status/Estado | `->pending()`, `->active()`, `->cancelled()` | Variantes de status |
| Role/Tipo | `->admin()`, `->manager()`, `->guest()` | Variantes de papel/tipo |
| Com relacionamento | `->withItems()`, `->withAddress()`, `->withPayments()` | Criar com filhos |
| Sem algo | `->withoutTimestamps()`, `->unverified()` | Remover atributo |
| Condição | `->expired()`, `->overdue()`, `->featured()` | Condição específica |

### Exemplos

```php
/**
 * Pedido pendente (estado padrão).
 */
public function pending(): static
{
    return $this->state(fn (array $attributes): array => [
        'status' => OrderStatus::Pending,
        'shipped_at' => null,
    ]);
}

/**
 * Pedido enviado.
 */
public function shipped(): static
{
    return $this->state(fn (array $attributes): array => [
        'status' => OrderStatus::Shipped,
        'shipped_at' => fake()->dateTimeBetween('-7 days', 'now'),
    ]);
}

/**
 * Pedido cancelado com motivo.
 */
public function cancelled(?string $reason = null): static
{
    return $this->state(fn (array $attributes): array => [
        'status' => OrderStatus::Cancelled,
        'cancellation_reason' => $reason ?? fake()->sentence(),
        'cancelled_at' => now(),
    ]);
}

/**
 * Pedido com valor alto (> R$ 1.000).
 */
public function highValue(): static
{
    return $this->state(fn (array $attributes): array => [
        'total' => fake()->randomFloat(2, 1000, 50000),
    ]);
}
```

### States para User

```php
/**
 * Usuário administrador.
 */
public function admin(): static
{
    return $this->state(fn (array $attributes): array => [
        'role' => UserRole::Admin,
    ]);
}

/**
 * Usuário com e-mail não verificado.
 */
public function unverified(): static
{
    return $this->state(fn (array $attributes): array => [
        'email_verified_at' => null,
    ]);
}
```

### Combinando States

```php
// States podem ser encadeados
$order = Order::factory()
    ->shipped()
    ->highValue()
    ->create();
```

---

## 4. Sequences

Use `Sequence` para gerar dados alternados, especialmente em testes de listagem e filtros.

```php
use Illuminate\Database\Eloquent\Factories\Sequence;

// Alternar entre status
$orders = Order::factory()
    ->count(6)
    ->sequence(
        ['status' => OrderStatus::Pending],
        ['status' => OrderStatus::Processing],
        ['status' => OrderStatus::Shipped],
    )
    ->create();
// Resultado: pending, processing, shipped, pending, processing, shipped

// Alternar com Sequence explícito
$users = User::factory()
    ->count(4)
    ->state(new Sequence(
        ['role' => UserRole::Admin],
        ['role' => UserRole::Manager],
    ))
    ->create();

// Sequence com index (para dados incrementais)
$products = Product::factory()
    ->count(5)
    ->sequence(fn (Sequence $sequence): array => [
        'sort_order' => $sequence->index,
        'name' => "Produto #{$sequence->index}",
    ])
    ->create();
```

### Quando Usar Sequence

| Cenario | Motivo |
|---------|--------|
| Testes de filtros | Garantir que cada filtro tem dados |
| Testes de ordenação | Dados com ordem previsível |
| Testes de paginação | Dados suficientes para várias páginas |
| Dados de seed | Variedade controlada |

---

## 5. Relationships em Factories

### `afterCreating` - Para relacionamentos depois de persistir

```php
/**
 * Pedido com itens.
 */
public function withItems(int $count = 3): static
{
    return $this->afterCreating(function (Order $order) use ($count): void {
        OrderItem::factory()
            ->count($count)
            ->for($order)
            ->create();

        // Recalcular total após criar itens
        $order->update([
            'total' => $order->items()->sum('subtotal'),
        ]);
    });
}

/**
 * Pedido com endereço de entrega.
 */
public function withShippingAddress(): static
{
    return $this->afterCreating(function (Order $order): void {
        Address::factory()
            ->for($order, 'addressable')
            ->create(['type' => 'shipping']);
    });
}
```

### `afterMaking` - Para modificações antes de persistir

```php
/**
 * Ajustar dados antes de salvar.
 */
public function withCalculatedSlug(): static
{
    return $this->afterMaking(function (Product $product): void {
        $product->slug = str($product->name)->slug()->toString();
    });
}
```

### `has()` - Criar filhos automaticamente

```php
// Criar pedido com 5 itens
$order = Order::factory()
    ->has(OrderItem::factory()->count(5))
    ->create();

// Nomear o relacionamento explicitamente
$order = Order::factory()
    ->has(OrderItem::factory()->count(3), 'items')
    ->create();

// Filhos com state específico
$customer = Customer::factory()
    ->has(Order::factory()->shipped()->count(2))
    ->has(Order::factory()->pending()->count(1))
    ->create();
```

### `for()` - Associar a pai existente

```php
// Criar itens para um pedido existente
$order = Order::factory()->create();

$items = OrderItem::factory()
    ->count(3)
    ->for($order)
    ->create();

// Para morph relations
$address = Address::factory()
    ->for($customer, 'addressable')
    ->create();
```

### `recycle()` - Reutilizar model existente (evitar duplicatas)

```php
// SEM recycle: cria um Customer novo para CADA Order
$orders = Order::factory()->count(5)->create();
// Resultado: 5 orders com 5 customers diferentes

// COM recycle: reutiliza o mesmo Customer
$customer = Customer::factory()->create();
$orders = Order::factory()
    ->count(5)
    ->recycle($customer)
    ->create();
// Resultado: 5 orders todas do mesmo customer

// Recycle com múltiplos models
$customer = Customer::factory()->create();
$seller = User::factory()->create();

$orders = Order::factory()
    ->count(5)
    ->recycle([$customer, $seller])
    ->create();
```

**Regra:** sempre use `recycle()` em testes quando múltiplos registros devem compartilhar o mesmo pai.

---

## 6. Dados Realistas com Faker

### Configuração do Locale

O locale `pt_BR` deve estar configurado em `config/app.php`:

```php
'faker_locale' => 'pt_BR',
```

### Métodos Úteis do Faker pt_BR

```php
// Documentos brasileiros
fake()->cpf();              // '123.456.789-09'
fake()->cpf(false);         // '12345678909' (sem formatação)
fake()->cnpj();             // '12.345.678/0001-90'
fake()->cnpj(false);        // '12345678000190'

// Endereço brasileiro
fake()->streetName();        // 'Rua das Flores'
fake()->cityName();          // 'São Paulo' (se disponível)
fake()->stateAbbr();         // 'SP'
fake()->postcode();          // '01001-000'

// Telefone brasileiro
fake()->cellphoneNumber();   // '(11) 98765-4321'
fake()->landlineNumber();    // '(11) 3456-7890'
fake()->phoneNumber();       // telefone genérico

// Pessoa
fake()->name();              // 'João da Silva'
fake()->firstName();         // 'Maria'
fake()->lastName();          // 'Santos'

// Empresa
fake()->company();           // 'Silva & Associados Ltda'

// Geral (funciona em qualquer locale)
fake()->email();             // 'joao@example.com'
fake()->safeEmail();         // 'joao@example.org' (domínio seguro)
fake()->unique()->email();   // email único garantido
fake()->word();              // palavra aleatória
fake()->sentence();          // frase aleatória
fake()->paragraph();         // parágrafo aleatório
fake()->randomFloat(2, 0, 9999);  // float com 2 casas decimais
fake()->numberBetween(1, 100);     // inteiro entre 1 e 100
fake()->boolean(70);         // true em 70% das vezes
fake()->optional()->sentence();    // null ou frase (50/50)
fake()->dateTimeBetween('-1 year', 'now');  // data no último ano
fake()->uuid();              // UUID v4
```

### Convenção: `fake()` vs `$this->faker`

**Este projeto usa `fake()`** (helper global). Nunca use `$this->faker` nas factories.

```php
// CORRETO
'name' => fake()->name(),
'email' => fake()->unique()->safeEmail(),

// PROIBIDO neste projeto
'name' => $this->faker->name(),
```

### Dicas de Uso

```php
// Imagem placeholder (URL)
'avatar_url' => fake()->imageUrl(200, 200, 'people'),

// Enum aleatório
'status' => fake()->randomElement(OrderStatus::cases()),

// Valor monetário realista
'price' => fake()->randomFloat(2, 1, 999),

// Dados opcionais (nullable)
'notes' => fake()->optional(0.3)->sentence(),  // 30% de chance de ter valor

// Texto com tamanho controlado
'description' => fake()->realText(200),

// Slug a partir de palavras
'slug' => fake()->unique()->slug(3),
```

---

## 7. Seeders

### Estrutura

```
database/seeders/
├── DatabaseSeeder.php          # Orquestrador principal
├── UserSeeder.php              # Usuários iniciais
├── RoleSeeder.php              # Roles/permissões
├── CategorySeeder.php          # Categorias base
└── DevelopmentSeeder.php       # Dados para desenvolvimento (não produção)
```

### `DatabaseSeeder` como Orquestrador

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Ordem importa: seeders com dependências vêm depois.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            CategorySeeder::class,
        ]);

        // Dados de desenvolvimento (nunca em produção)
        if (app()->environment('local', 'testing')) {
            $this->call([
                DevelopmentSeeder::class,
            ]);
        }
    }
}
```

### Seeder Idempotente (Obrigatório)

**Regra:** todo seeder DEVE poder rodar múltiplas vezes sem duplicar dados.

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

final class UserSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate = idempotente
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrador',
                'role' => UserRole::Admin,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'manager@example.com'],
            [
                'name' => 'Gerente',
                'role' => UserRole::Manager,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
```

### Seeder por Módulo com `firstOrCreate`

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Eletrônicos', 'slug' => 'eletronicos', 'sort_order' => 0],
            ['name' => 'Vestuário', 'slug' => 'vestuario', 'sort_order' => 1],
            ['name' => 'Alimentos', 'slug' => 'alimentos', 'sort_order' => 2],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
        }
    }
}
```

### Seeder de Desenvolvimento (com Factories)

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Seeder;

final class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        // Só rodar se não existirem dados
        if (Customer::count() > 0) {
            return;
        }

        $customers = Customer::factory()->count(20)->create();
        $products = Product::factory()->count(50)->create();

        // Criar pedidos com itens, reciclando customers e products
        Order::factory()
            ->count(100)
            ->withItems(3)
            ->recycle($customers)
            ->recycle($products)
            ->create();
    }
}
```

### Executando Seeders

```bash
# Todos os seeders
php artisan db:seed --no-interaction

# Seeder específico
php artisan db:seed --class=CategorySeeder --no-interaction

# Fresh migration + seed
php artisan migrate:fresh --seed --no-interaction
```

---

## 8. Uso em Testes (Pest)

### Criação Básica

```php
// Criar e persistir no banco
$order = Order::factory()->create();

// Criar sem persistir (apenas instância)
$order = Order::factory()->make();

// Múltiplos registros
$orders = Order::factory()->count(5)->create();

// Com atributos específicos
$order = Order::factory()->create([
    'total' => 1500.00,
    'notes' => 'Pedido especial',
]);
```

### Usando States

```php
it('lists only shipped orders', function () {
    Order::factory()->shipped()->count(3)->create();
    Order::factory()->pending()->count(2)->create();

    $shipped = Order::query()->where('status', OrderStatus::Shipped)->get();

    expect($shipped)->toHaveCount(3);
});

it('creates order with items', function () {
    $order = Order::factory()->withItems(5)->create();

    expect($order->items)->toHaveCount(5);
});
```

### Usando `recycle()` para Models Compartilhados

```php
it('lists orders for a specific customer', function () {
    $customer = Customer::factory()->create();

    // Todas as orders pertencem ao mesmo customer
    $orders = Order::factory()
        ->count(3)
        ->recycle($customer)
        ->create();

    $otherOrders = Order::factory()->count(2)->create();

    expect($customer->orders)->toHaveCount(3);
});

it('calculates customer total spending', function () {
    $customer = Customer::factory()->create();

    Order::factory()
        ->count(3)
        ->recycle($customer)
        ->sequence(
            ['total' => 100.00],
            ['total' => 200.00],
            ['total' => 300.00],
        )
        ->create();

    expect($customer->orders()->sum('total'))->toBe(600.00);
});
```

### Usando `for()` em Testes

```php
it('creates items for an existing order', function () {
    $order = Order::factory()->create();

    $items = OrderItem::factory()
        ->count(3)
        ->for($order)
        ->create();

    expect($order->fresh()->items)->toHaveCount(3);
});
```

### Setup com `beforeEach`

```php
<?php

use App\Models\{Customer, Order, User};
use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->customer = Customer::factory()->create();

    actingAs($this->admin);
});

it('creates an order for the customer', function () {
    $order = Order::factory()
        ->recycle($this->customer)
        ->create();

    expect($order->customer_id)->toBe($this->customer->id);
});
```

---

## 9. Padroes Proibidos

### Nunca usar `Model::create()` em testes

```php
// PROIBIDO - dados manuais sem factory
$order = Order::create([
    'customer_id' => $customer->id,
    'status' => 'pending',
    'total' => 100,
]);

// CORRETO - sempre usar factory
$order = Order::factory()->recycle($customer)->create([
    'total' => 100,
]);
```

### Nunca duplicar lógica de factory em testes

```php
// PROIBIDO - recriar a definição da factory manualmente
$user = User::factory()->create([
    'name' => fake()->name(),
    'email' => fake()->email(),
    'role' => UserRole::Admin,
    'email_verified_at' => now(),
]);

// CORRETO - usar state da factory
$user = User::factory()->admin()->create();
```

### Nunca criar dados de relacionamento manualmente

```php
// PROIBIDO - criar itens separadamente sem usar state
$order = Order::factory()->create();
OrderItem::factory()->create(['order_id' => $order->id]);
OrderItem::factory()->create(['order_id' => $order->id]);

// CORRETO - usar state withItems ou has()
$order = Order::factory()->withItems(2)->create();
// OU
$order = Order::factory()
    ->has(OrderItem::factory()->count(2))
    ->create();
```

### Nunca usar dados fixos/hardcoded sem motivo

```php
// PROIBIDO - dados fixos sem razão
$product = Product::factory()->create([
    'name' => 'Produto Teste',
    'price' => 99.90,
    'sku' => 'SKU-001',
]);

// CORRETO - deixar factory gerar dados, sobrescrever só o necessário
$product = Product::factory()->create([
    'price' => 99.90, // só se o teste precisa desse valor específico
]);
```

### Nunca criar seeder que falha ao rodar duas vezes

```php
// PROIBIDO - vai falhar na segunda execução (unique constraint)
User::create(['email' => 'admin@example.com', ...]);

// CORRETO - idempotente
User::updateOrCreate(
    ['email' => 'admin@example.com'],
    [...],
);
```

---

## 10. Checklist

### Nova Factory

- [ ] Factory criada em `database/factories/` via `php artisan make:factory`
- [ ] Classe é `final` com `declare(strict_types=1)`
- [ ] PHPDoc `@extends Factory<Model>` presente
- [ ] `$model` explicitamente definido
- [ ] `definition()` retorna todos os campos obrigatórios
- [ ] Relacionamentos FK usam `Model::factory()` (não IDs hardcoded)
- [ ] Usa `fake()` (não `$this->faker`)
- [ ] States criados para cada status/role/variante do Model
- [ ] States com prefixo `with` para relacionamentos (`withItems()`, `withAddress()`)
- [ ] `afterCreating` usado para criar relacionamentos filhos
- [ ] `recycle()` documentado nos states que criam registros dependentes
- [ ] Model tem trait `HasFactory` com PHPDoc `@use`

### Novo Seeder

- [ ] Seeder criado em `database/seeders/` via `php artisan make:seeder`
- [ ] Classe é `final` com `declare(strict_types=1)`
- [ ] Seeder e idempotente (`updateOrCreate` ou `firstOrCreate`)
- [ ] Registrado no `DatabaseSeeder` na ordem correta de dependencias
- [ ] Dados de desenvolvimento protegidos por `app()->environment('local', 'testing')`
- [ ] Seeder de desenvolvimento usa factories (nao dados manuais)

### Uso em Testes

- [ ] Testes usam `Model::factory()->create()` (nunca `Model::create()`)
- [ ] States usados para variantes (nao overrides manuais)
- [ ] `recycle()` usado quando multiplos registros compartilham mesmo pai
- [ ] `beforeEach` configura models compartilhados do describe
- [ ] Factories geram dados, testes sobrescrevem apenas o necessario para a asserção
