# Performance e Cache - Guideline

> **Regras para otimização de performance e estratégias de cache.**

---

## 1. Prevenção de N+1

### OBRIGATÓRIO: Eager Loading

```php
// PROIBIDO - N+1
$orders = Order::all();
foreach ($orders as $order) {
    echo $order->customer->name; // 1 query por order
}

// OBRIGATÓRIO - Eager load
$orders = Order::with(['customer', 'items.product'])->get();
```

### Regras

1. **Sempre** usar `with()` ao acessar relationships em loops
2. **Sempre** usar `withCount()` quando precisa apenas do total
3. **Usar** `load()` para eager load após o model já estar carregado
4. Em Filament Tables, usar `$table->modifyQueryUsing(fn ($query) => $query->with([...]))`
5. Em API controllers, eager load antes de retornar Resources

### Detecção

```php
// Em desenvolvimento, ativar detecção de N+1
// bootstrap/app.php ou AppServiceProvider
Model::preventLazyLoading(! app()->isProduction());
```

---

## 2. Query Optimization

### Selecionar Apenas Campos Necessários

```php
// EVITAR - traz todos os campos
$users = User::all();

// PREFERIR - apenas campos necessários
$users = User::query()->select(['id', 'name', 'email'])->get();

// Para contagens
$count = Order::where('status', 'pending')->count();

// Para verificação de existência
$exists = Order::where('email', $email)->exists();
```

### Bulk Operations

```php
// EVITAR - N queries
foreach ($ids as $id) {
    Order::find($id)->update(['status' => 'completed']);
}

// PREFERIR - 1 query
Order::whereIn('id', $ids)->update(['status' => 'completed']);

// Bulk insert
Order::insert($records); // Sem timestamps/events
Order::upsert($records, ['external_id'], ['status', 'total']); // Insert or update
```

### Chunking para Grandes Volumes

```php
// Para processar muitos registros sem estourar memória
Order::where('status', 'pending')
    ->chunkById(500, function ($orders) {
        foreach ($orders as $order) {
            ProcessOrderJob::dispatch($order);
        }
    });

// Lazy collection (mais eficiente em memória)
Order::where('status', 'pending')->lazy()->each(function ($order) {
    // Processa um por vez, sem carregar todos na memória
});
```

---

## 3. Índices de Banco

### Regras de Indexação

```php
// Sempre indexar:
$table->index('status');                    // Campos usados em WHERE
$table->index('created_at');               // Campos usados em ORDER BY
$table->index(['status', 'created_at']);   // Queries com múltiplos filtros
$table->foreignId('customer_id')->constrained(); // FKs (já cria índice)

// Índice único quando necessário
$table->unique('email');
$table->unique(['tenant_id', 'slug']);     // Unique composto
```

### Quando Indexar

| Cenário | Índice |
|---------|--------|
| `WHERE campo = valor` | Simples |
| `WHERE campo1 = x AND campo2 = y` | Composto (campo1, campo2) |
| `ORDER BY campo` | Simples |
| `WHERE campo1 = x ORDER BY campo2` | Composto (campo1, campo2) |
| Foreign key | Automático via `constrained()` |
| Campo com `UNIQUE` | Automático |
| Busca textual frequente | Fulltext (se MySQL/Postgres) |

### Quando NÃO Indexar

- Tabelas com poucos registros (< 1000)
- Campos com baixa cardinalidade (ex: boolean com 50/50)
- Tabelas com muita escrita e pouca leitura

---

## 4. Cache

### Estratégias por Camada

| Camada | Método | TTL | Quando |
|--------|--------|-----|--------|
| Config | `config:cache` | Até deploy | Produção |
| Routes | `route:cache` | Até deploy | Produção |
| Views | `view:cache` | Até deploy | Produção |
| Query cache | `Cache::remember()` | 5-60 min | Dados que mudam pouco |
| Model cache | `#[Computed(cache)]` | Configurável | Livewire computed props |
| HTTP cache | Cache-Control headers | Variável | API responses |

### Cache de Queries

```php
use Illuminate\Support\Facades\Cache;

// Cache simples com TTL
$stats = Cache::remember('dashboard:stats', now()->addMinutes(15), function () {
    return [
        'total_orders' => Order::count(),
        'revenue' => Order::sum('total'),
        'pending' => Order::where('status', 'pending')->count(),
    ];
});

// Cache com tags (requer Redis/Memcached)
$products = Cache::tags(['products'])->remember(
    "products:category:{$categoryId}",
    now()->addHours(1),
    fn () => Product::where('category_id', $categoryId)->get()
);

// Invalidar cache por tag
Cache::tags(['products'])->flush();
```

### Invalidação de Cache

```php
// No Service, ao criar/atualizar/deletar
final class OrderService
{
    public function create(array $data): Order
    {
        $order = DB::transaction(fn () => Order::create($data));

        // Invalidar caches relacionados
        Cache::forget('dashboard:stats');
        Cache::tags(['orders'])->flush();

        return $order;
    }
}

// Via Model Observer (se usar_observers: true)
class OrderObserver
{
    public function saved(Order $order): void
    {
        Cache::forget('dashboard:stats');
    }
}
```

### Cache Key Conventions

```
{recurso}:{identificador}:{variante}

Exemplos:
dashboard:stats
products:category:uuid-123
user:uuid-456:permissions
orders:list:page:1:per_page:15
```

---

## 5. Cache em Filament

### Widgets com Cache

```php
use Filament\Widgets\StatsOverviewWidget;
use Illuminate\Support\Facades\Cache;

final class DashboardStats extends StatsOverviewWidget
{
    // Polling com intervalo maior para dados cacheados
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $stats = Cache::remember('dashboard:stats', now()->addMinutes(5), fn () => [
            'total' => Order::count(),
            'pending' => Order::where('status', 'pending')->count(),
            'revenue' => Order::where('status', 'completed')->sum('total'),
        ]);

        return [
            Stat::make(__('widgets.total'), $stats['total']),
            Stat::make(__('widgets.pending'), $stats['pending'])->color('warning'),
            Stat::make(__('widgets.revenue'), 'R$ ' . number_format($stats['revenue'], 2, ',', '.')),
        ];
    }
}
```

### Computed Properties com Cache (Livewire v4)

```php
// Cache por componente (entre requests)
#[Computed(persist: true, seconds: 300)]
public function stats(): array
{
    return ['total' => Order::count()];
}

// Cache global da aplicação
#[Computed(cache: true, key: 'global-stats', seconds: 600)]
public function globalStats(): array
{
    return ['total' => Order::count()];
}
```

---

## 6. Paginação

### Regras

1. **Sempre** paginar listas — nunca `->get()` sem limite em produção
2. **Padrão:** 15 itens por página
3. **API:** respeitar `?per_page` com máximo de 100
4. **Cursor pagination** para feeds/infinite scroll (mais performático)

```php
// Standard
$orders = Order::query()->paginate(15);

// Cursor (melhor para grandes datasets)
$orders = Order::query()->cursorPaginate(15);

// Simple (sem count total — mais rápido)
$orders = Order::query()->simplePaginate(15);
```

---

## 7. Checklist de Performance

### Em Cada Feature
- [ ] Eager loading em todas as queries com relationships
- [ ] `preventLazyLoading()` ativo em dev
- [ ] Índices criados para campos em WHERE/ORDER BY
- [ ] Paginação em todas as listagens
- [ ] Cache para dados calculados/agregados
- [ ] Bulk operations para operações em massa
- [ ] `select()` para limitar campos quando possível

### Em Produção
- [ ] `config:cache`, `route:cache`, `view:cache` executados
- [ ] Redis configurado para cache e sessions
- [ ] Opcache habilitado
- [ ] Queries lentas logadas (`DB::listen()` ou query log)
