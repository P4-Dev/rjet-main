# API RESTful - Guideline

> **Regras para expor APIs REST do produto.**
> Para integrações com APIs externas (gateways, etc), consulte o skill `api-integration`.

---

## 1. Princípios Fundamentais

1. **RESTful** - Recursos como substantivos, verbos HTTP para ações
2. **Versionamento obrigatório** - `/api/v1/`, `/api/v2/`
3. **JSON only** - Request e Response sempre JSON
4. **Stateless** - Autenticação via token (Sanctum ou Passport)
5. **Documentação** - Swagger/OpenAPI via L5-Swagger
6. **Consistência** - Mesmo padrão em todos os endpoints

---

## 2. Autenticação

### Decisão: Sanctum vs Passport

| Critério | Sanctum | Passport |
|----------|---------|----------|
| Complexidade | Simples | Mais complexo |
| SPA/Mobile | Ideal | Suporta |
| OAuth2 completo | Nao | Sim (authorization_code, client_credentials, etc.) |
| Third-party clients | Nao recomendado | Ideal |
| Machine-to-machine | Token simples | Client credentials grant |
| Token scopes | Abilities (simples) | Scopes (OAuth2 completo) |
| Quando usar | API interna, SPA, mobile proprio | API publica, terceiros, OAuth2 |

### Regra de Decisao

- **Sanctum**: API consumida apenas pelo proprio frontend (SPA) ou app mobile proprio
- **Passport**: API publica para terceiros, parceiros, ou quando precisa de OAuth2 completo

### Configuracao Sanctum

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost')),
'expiration' => 60 * 24 * 7, // 7 dias

// Model User
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
}
```

### Configuracao Passport

```php
// config/passport.php
'personal_access_client' => [
    'id' => env('PASSPORT_PERSONAL_ACCESS_CLIENT_ID'),
    'secret' => env('PASSPORT_PERSONAL_ACCESS_CLIENT_SECRET'),
],

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [
        Laravel\Passport\Http\Middleware\CreateFreshApiToken::class,
    ]);
})
```

### Endpoints de Autenticacao

```
POST   /api/v1/auth/login          # Login, retorna token
POST   /api/v1/auth/register       # Registro (se aplicavel)
POST   /api/v1/auth/logout         # Revoga token
POST   /api/v1/auth/refresh        # Refresh token (Passport)
GET    /api/v1/auth/me             # Dados do usuario autenticado
```

---

## 3. Versionamento

### Estrutura Obrigatoria

```
routes/
├── api.php              # Inclui versoes
├── api/
│   ├── v1.php           # Rotas v1
│   └── v2.php           # Rotas v2 (quando necessario)

app/Http/Controllers/Api/
├── V1/
│   ├── AuthController.php
│   ├── OrderController.php
│   └── ProductController.php
├── V2/
│   └── ProductController.php

app/Http/Resources/Api/
├── V1/
│   ├── OrderResource.php
│   └── ProductResource.php
├── V2/
│   └── ProductResource.php
```

### Registro de Rotas

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('api.v1.')->group(
    base_path('routes/api/v1.php')
);

Route::prefix('v2')->as('api.v2.')->group(
    base_path('routes/api/v2.php')
);
```

```php
// routes/api/v1.php
use App\Http\Controllers\Api\V1;

Route::post('auth/login', [V1\AuthController::class, 'login']);
Route::post('auth/register', [V1\AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [V1\AuthController::class, 'logout']);
    Route::get('auth/me', [V1\AuthController::class, 'me']);

    Route::apiResource('orders', V1\OrderController::class);
    Route::apiResource('products', V1\ProductController::class);
});
```

### Politica de Versionamento

1. **v1 nunca quebra** - Apenas adicionar campos, nunca remover
2. **Nova versao** quando:
   - Remover campos do response
   - Alterar estrutura do response
   - Mudar regras de validacao que quebram clientes
   - Renomear endpoints
3. **Deprecation** - Manter versao antiga por no minimo 6 meses apos launch da nova
4. **Header de deprecacao**: `Deprecation: true`, `Sunset: 2025-12-31`

---

## 4. Controllers

### Padrao

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Requests\Api\V1\UpdateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $service,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/orders",
     *     operationId="listOrders",
     *     tags={"Orders"},
     *     summary="Lista pedidos",
     *     security={{"sanctum": {}}},
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function index(): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->with(['customer', 'items'])
            ->latest()
            ->paginate();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->service->create($request->validated());

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        $order->load(['customer', 'items.product']);

        return OrderResource::make($order);
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $order = $this->service->update($order, $request->validated());

        return OrderResource::make($order);
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->service->delete($order);

        return response()->json(null, 204);
    }
}
```

### Regras do Controller

1. **Sempre** usar Form Requests (nunca validacao inline)
2. **Sempre** usar API Resources (nunca retornar Model direto)
3. **Sempre** usar Services para logica de negocio
4. **Sempre** retornar status codes corretos
5. **Sempre** eager load relationships necessarias
6. **Nunca** colocar logica de negocio no controller
7. **Tipo de retorno** explicito em todos os metodos

---

## 5. API Resources

### Padrao

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="OrderResourceV1",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="status", type="string"),
 *     @OA\Property(property="total", type="number", format="float"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 * )
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total' => $this->total,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
```

### Regras do Resource

1. **Timestamps** sempre em ISO 8601 (`->toIso8601String()`)
2. **Relationships** sempre com `whenLoaded()`
3. **Dinheiro** sempre como numero (float/int), nunca formatado
4. **IDs** sempre incluidos
5. **Campos sensiveis** nunca expostos (password, tokens, etc.)
6. **Classe final** - `final class`

---

## 6. Form Requests

### Padrao

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Usar Policy no controller se necessario
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => __('api.validation.customer_required'),
            'items.required' => __('api.validation.items_required'),
            'items.min' => __('api.validation.items_min'),
        ];
    }
}
```

### Estrutura de Pastas

```
app/Http/Requests/Api/
├── V1/
│   ├── Auth/
│   │   ├── LoginRequest.php
│   │   └── RegisterRequest.php
│   ├── StoreOrderRequest.php
│   └── UpdateOrderRequest.php
```

---

## 7. Respostas Padrao

### Sucesso

```json
// GET /api/v1/orders (lista com paginacao)
{
    "data": [
        { "id": "uuid", "status": "pending", ... }
    ],
    "links": {
        "first": "...",
        "last": "...",
        "prev": null,
        "next": "..."
    },
    "meta": {
        "current_page": 1,
        "last_page": 5,
        "per_page": 15,
        "total": 73
    }
}

// GET /api/v1/orders/{id} (recurso unico)
{
    "data": {
        "id": "uuid",
        "status": "pending",
        ...
    }
}

// POST /api/v1/orders (criacao) - Status 201
{
    "data": {
        "id": "uuid",
        ...
    }
}

// DELETE /api/v1/orders/{id} - Status 204 (sem body)
```

### Erro

```json
// 422 - Validacao
{
    "message": "The given data was invalid.",
    "errors": {
        "customer_id": ["The customer id field is required."],
        "items": ["The items field is required."]
    }
}

// 401 - Nao autenticado
{
    "message": "Unauthenticated."
}

// 403 - Nao autorizado
{
    "message": "This action is unauthorized."
}

// 404 - Nao encontrado
{
    "message": "Resource not found."
}

// 500 - Erro interno (producao - sem detalhes)
{
    "message": "Server Error."
}
```

### Handler de Excecoes

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->shouldRenderJsonWhen(function (Request $request) {
        return $request->is('api/*') || $request->expectsJson();
    });
})
```

---

## 8. Paginacao e Filtros

### Paginacao

```php
// Padrao: 15 por pagina
$orders = Order::query()->paginate();

// Customizavel via query string: ?per_page=25
$orders = Order::query()->paginate(
    perPage: min($request->integer('per_page', 15), 100)
);
```

### Filtros via Query String

```php
// GET /api/v1/orders?status=pending&customer_id=uuid&sort=-created_at

public function index(Request $request): AnonymousResourceCollection
{
    $orders = Order::query()
        ->when($request->filled('status'), fn ($q) =>
            $q->where('status', $request->input('status'))
        )
        ->when($request->filled('customer_id'), fn ($q) =>
            $q->where('customer_id', $request->input('customer_id'))
        )
        ->when($request->filled('search'), fn ($q) =>
            $q->where('number', 'like', "%{$request->input('search')}%")
        )
        ->when($request->filled('sort'), function ($q) use ($request) {
            $sort = $request->input('sort');
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $column = ltrim($sort, '-');
            $q->orderBy($column, $direction);
        }, fn ($q) => $q->latest())
        ->with(['customer'])
        ->paginate(min($request->integer('per_page', 15), 100));

    return OrderResource::collection($orders);
}
```

### Convencao de Sorting

- `?sort=name` - Ascendente
- `?sort=-name` - Descendente (prefixo `-`)
- `?sort=-created_at` - Mais recente primeiro (padrao)

---

## 9. Rate Limiting

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // Rate limits ja sao configurados em AppServiceProvider ou RouteServiceProvider
})

// app/Providers/AppServiceProvider.php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(5)->by($request->ip());
    });
}

// routes/api/v1.php
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth');
```

### Headers de Rate Limit

Laravel automaticamente inclui:
- `X-RateLimit-Limit: 60`
- `X-RateLimit-Remaining: 59`
- `Retry-After: 30` (quando excedido)

---

## 10. Documentacao com L5-Swagger

### Instalacao

```bash
composer require darkaonline/l5-swagger
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"
```

### Configuracao

```php
// config/l5-swagger.php
'defaults' => [
    'routes' => [
        'api' => 'api/documentation',
        'docs' => 'docs',
    ],
    'paths' => [
        'docs' => storage_path('api-docs'),
        'annotations' => [
            base_path('app/Http/Controllers/Api'),
            base_path('app/Http/Resources/Api'),
        ],
    ],
],
```

### Anotacao Base

```php
// app/Http/Controllers/Controller.php (ou arquivo dedicado)

/**
 * @OA\Info(
 *     title="Nome do Projeto - API",
 *     version="1.0.0",
 *     description="API RESTful do produto",
 *     @OA\Contact(email="contato@empresa.com")
 * )
 *
 * @OA\Server(
 *     url="/api/v1",
 *     description="API v1"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="token"
 * )
 */
```

### Anotacao de Endpoint

```php
/**
 * @OA\Get(
 *     path="/orders",
 *     operationId="listOrders",
 *     tags={"Orders"},
 *     summary="Lista todos os pedidos",
 *     description="Retorna lista paginada de pedidos do usuario autenticado",
 *     security={{"sanctum": {}}},
 *     @OA\Parameter(
 *         name="status",
 *         in="query",
 *         description="Filtrar por status",
 *         @OA\Schema(type="string", enum={"pending", "active", "completed"})
 *     ),
 *     @OA\Parameter(
 *         name="per_page",
 *         in="query",
 *         description="Itens por pagina (max 100)",
 *         @OA\Schema(type="integer", default=15)
 *     ),
 *     @OA\Parameter(
 *         name="sort",
 *         in="query",
 *         description="Campo de ordenacao (prefixo - para desc)",
 *         @OA\Schema(type="string", default="-created_at")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Lista de pedidos",
 *         @OA\JsonContent(
 *             @OA\Property(property="data", type="array",
 *                 @OA\Items(ref="#/components/schemas/OrderResourceV1")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=401, description="Nao autenticado")
 * )
 */
```

### Geracao

```bash
php artisan l5-swagger:generate
```

A documentacao fica acessivel em `/api/documentation`.

---

## 11. Testes de API

### Padrao com Pest

```php
<?php

use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\{actingAs, getJson, postJson, putJson, deleteJson};

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('GET /api/v1/orders', function () {
    it('returns paginated orders', function () {
        Order::factory()->count(3)->create();

        actingAs($this->user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status', 'total', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    });

    it('filters by status', function () {
        Order::factory()->create(['status' => 'pending']);
        Order::factory()->create(['status' => 'completed']);

        actingAs($this->user)
            ->getJson('/api/v1/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('requires authentication', function () {
        getJson('/api/v1/orders')
            ->assertUnauthorized();
    });
});

describe('POST /api/v1/orders', function () {
    it('creates an order', function () {
        $data = [
            'customer_id' => Customer::factory()->create()->id,
            'items' => [
                ['product_id' => Product::factory()->create()->id, 'quantity' => 2],
            ],
        ];

        actingAs($this->user)
            ->postJson('/api/v1/orders', $data)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'status']]);
    });

    it('validates required fields', function () {
        actingAs($this->user)
            ->postJson('/api/v1/orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'items']);
    });
});

describe('GET /api/v1/orders/{id}', function () {
    it('returns a single order', function () {
        $order = Order::factory()->create();

        actingAs($this->user)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    });

    it('returns 404 for missing order', function () {
        actingAs($this->user)
            ->getJson('/api/v1/orders/non-existent-uuid')
            ->assertNotFound();
    });
});

describe('PUT /api/v1/orders/{id}', function () {
    it('updates an order', function () {
        $order = Order::factory()->create();

        actingAs($this->user)
            ->putJson("/api/v1/orders/{$order->id}", [
                'notes' => 'Updated notes',
            ])
            ->assertOk();

        expect($order->fresh()->notes)->toBe('Updated notes');
    });
});

describe('DELETE /api/v1/orders/{id}', function () {
    it('deletes an order', function () {
        $order = Order::factory()->create();

        actingAs($this->user)
            ->deleteJson("/api/v1/orders/{$order->id}")
            ->assertNoContent();

        expect(Order::find($order->id))->toBeNull();
    });
});
```

---

## 12. Middleware e CORS

### CORS

```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
    'max_age' => 86400,
    'supports_credentials' => false,
];
```

### Middleware Customizado (se necessario)

```php
// API-specific middleware
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Rotas protegidas
});
```

---

## 13. Estrutura Completa de Pastas

```
app/Http/
├── Controllers/Api/
│   ├── V1/
│   │   ├── AuthController.php
│   │   ├── OrderController.php
│   │   └── ProductController.php
│   └── V2/
│       └── ProductController.php
├── Requests/Api/
│   ├── V1/
│   │   ├── Auth/
│   │   │   ├── LoginRequest.php
│   │   │   └── RegisterRequest.php
│   │   ├── StoreOrderRequest.php
│   │   └── UpdateOrderRequest.php
│   └── V2/
│       └── ...
├── Resources/Api/
│   ├── V1/
│   │   ├── OrderResource.php
│   │   ├── OrderCollection.php (se customizado)
│   │   └── ProductResource.php
│   └── V2/
│       └── ProductResource.php
routes/
├── api.php
├── api/
│   ├── v1.php
│   └── v2.php
lang/
├── pt_BR/
│   └── api.php
├── en/
│   └── api.php
```

---

## 14. Traducoes da API

```php
// lang/pt_BR/api.php
return [
    'validation' => [
        'customer_required' => 'O campo cliente e obrigatorio.',
        'items_required' => 'Os itens do pedido sao obrigatorios.',
        'items_min' => 'O pedido deve ter pelo menos um item.',
    ],
    'messages' => [
        'unauthenticated' => 'Voce precisa estar autenticado.',
        'unauthorized' => 'Voce nao tem permissao para esta acao.',
        'not_found' => 'Recurso nao encontrado.',
        'server_error' => 'Erro interno do servidor.',
        'throttle' => 'Muitas requisicoes. Tente novamente em :seconds segundos.',
    ],
];

// lang/en/api.php
return [
    'validation' => [
        'customer_required' => 'The customer field is required.',
        'items_required' => 'Order items are required.',
        'items_min' => 'The order must have at least one item.',
    ],
    'messages' => [
        'unauthenticated' => 'You must be authenticated.',
        'unauthorized' => 'You are not authorized for this action.',
        'not_found' => 'Resource not found.',
        'server_error' => 'Internal server error.',
        'throttle' => 'Too many requests. Try again in :seconds seconds.',
    ],
];
```

---

## 15. Checklist para Novo Endpoint

### Antes de Criar
- [ ] Definir versao da API (v1, v2, ...)
- [ ] Definir recursos e verbos HTTP
- [ ] Definir autenticacao necessaria (Sanctum/Passport/publica)
- [ ] Definir rate limiting

### Implementacao
- [ ] Controller em `app/Http/Controllers/Api/V{n}/`
- [ ] Form Request em `app/Http/Requests/Api/V{n}/`
- [ ] API Resource em `app/Http/Resources/Api/V{n}/`
- [ ] Rotas em `routes/api/v{n}.php`
- [ ] Policy aplicada (se necessario)
- [ ] Eager loading de relationships
- [ ] Paginacao configurada

### Documentacao
- [ ] Anotacoes Swagger no Controller
- [ ] Schema no Resource
- [ ] `php artisan l5-swagger:generate` executado
- [ ] Acessivel em `/api/documentation`

### Testes
- [ ] Teste de listagem com paginacao
- [ ] Teste de criacao com validacao
- [ ] Teste de atualizacao
- [ ] Teste de delecao
- [ ] Teste de autenticacao (401)
- [ ] Teste de autorizacao (403)
- [ ] Teste de not found (404)
- [ ] Teste de filtros

### Traducoes
- [ ] Mensagens de validacao em `lang/pt_BR/api.php`
- [ ] Mensagens de validacao em `lang/en/api.php`

---

## 16. Boas Praticas

1. **Nao expor IDs internos** - Use UUIDs
2. **Nao expor campos sensiveis** - password, remember_token, etc.
3. **Sempre paginar** listas - Nunca retornar colecoes inteiras
4. **Usar Policies** para autorizacao em endpoints
5. **Eager load** para evitar N+1
6. **Cache** respostas de leitura quando possivel (Cache-Control headers)
7. **Log** todas as requisicoes de escrita para auditoria
8. **HTTPS only** em producao
9. **Validar** tudo no Form Request, nunca no controller
10. **Idempotencia** - PUT e DELETE devem ser idempotentes
