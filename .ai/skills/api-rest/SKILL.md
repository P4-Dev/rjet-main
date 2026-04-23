# Skill: API RESTful do Produto

## Quando Usar

Use este skill quando precisar:
- Criar endpoints REST para expor dados do produto
- Configurar autenticacao via Sanctum ou Passport
- Versionar a API (v1, v2, etc.)
- Documentar com Swagger/OpenAPI (L5-Swagger)
- Criar controllers, resources, form requests para API

> **IMPORTANTE:** Este skill e para APIs do PROPRIO produto.
> Para integracao com APIs externas (gateways, etc.), use o skill `api-integration`.

---

## OBRIGATORIO: Antes de Implementar

1. **Leia** `.ai/docs/api.md` para regras completas
2. **Verifique** qual autenticacao o projeto usa (Sanctum ou Passport)
3. **Verifique** a versao atual da API (`routes/api/`)
4. **Use** `search-docs` para sintaxe atual do Laravel

---

## Template: Controller API

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Store{Nome}Request;
use App\Http\Requests\Api\V1\Update{Nome}Request;
use App\Http\Resources\Api\V1\{Nome}Resource;
use App\Models\{Nome};
use App\Services\{Nome}Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @OA\Tag(name="{Nomes}", description="Gerenciamento de {nomes}")
 */
final class {Nome}Controller extends Controller
{
    public function __construct(
        private readonly {Nome}Service $service,
    ) {}

    /**
     * @OA\Get(
     *     path="/{nomes}",
     *     operationId="list{Nomes}",
     *     tags={"{Nomes}"},
     *     summary="Lista {nomes}",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="sort", in="query", @OA\Schema(type="string", default="-created_at")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(@OA\Property(property="data", type="array",
     *             @OA\Items(ref="#/components/schemas/{Nome}ResourceV1")))
     *     ),
     *     @OA\Response(response=401, description="Nao autenticado")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = {Nome}::query()
            ->when($request->filled('search'), fn ($q) =>
                $q->where('name', 'like', "%{$request->input('search')}%")
            )
            ->when($request->filled('status'), fn ($q) =>
                $q->where('status', $request->input('status'))
            )
            ->when($request->filled('sort'), function ($q) use ($request) {
                $sort = $request->input('sort');
                $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
                $q->orderBy(ltrim($sort, '-'), $direction);
            }, fn ($q) => $q->latest());

        return {Nome}Resource::collection(
            $query->paginate(min($request->integer('per_page', 15), 100))
        );
    }

    /**
     * @OA\Post(
     *     path="/{nomes}",
     *     operationId="create{Nome}",
     *     tags={"{Nomes}"},
     *     summary="Cria {nome}",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Store{Nome}RequestV1")
     *     ),
     *     @OA\Response(response=201, description="Criado",
     *         @OA\JsonContent(@OA\Property(property="data",
     *             ref="#/components/schemas/{Nome}ResourceV1"))
     *     ),
     *     @OA\Response(response=422, description="Erro de validacao")
     * )
     */
    public function store(Store{Nome}Request $request): JsonResponse
    {
        ${nome} = $this->service->create($request->validated());

        return {Nome}Resource::make(${nome})
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *     path="/{nomes}/{id}",
     *     operationId="show{Nome}",
     *     tags={"{Nomes}"},
     *     summary="Exibe {nome}",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="OK",
     *         @OA\JsonContent(@OA\Property(property="data",
     *             ref="#/components/schemas/{Nome}ResourceV1"))
     *     ),
     *     @OA\Response(response=404, description="Nao encontrado")
     * )
     */
    public function show({Nome} ${nome}): {Nome}Resource
    {
        ${nome}->load([/* relationships */]);

        return {Nome}Resource::make(${nome});
    }

    /**
     * @OA\Put(
     *     path="/{nomes}/{id}",
     *     operationId="update{Nome}",
     *     tags={"{Nomes}"},
     *     summary="Atualiza {nome}",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Update{Nome}RequestV1")
     *     ),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=422, description="Erro de validacao")
     * )
     */
    public function update(Update{Nome}Request $request, {Nome} ${nome}): {Nome}Resource
    {
        ${nome} = $this->service->update(${nome}, $request->validated());

        return {Nome}Resource::make(${nome});
    }

    /**
     * @OA\Delete(
     *     path="/{nomes}/{id}",
     *     operationId="delete{Nome}",
     *     tags={"{Nomes}"},
     *     summary="Remove {nome}",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=204, description="Removido"),
     *     @OA\Response(response=404, description="Nao encontrado")
     * )
     */
    public function destroy({Nome} ${nome}): JsonResponse
    {
        $this->service->delete(${nome});

        return response()->json(null, 204);
    }
}
```

---

## Template: API Resource

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="{Nome}ResourceV1",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="status", type="string", enum={"pending","active","completed"}),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time"),
 * )
 */
final class {Nome}Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            // Relationships (apenas quando carregadas)
            'parent' => ParentResource::make($this->whenLoaded('parent')),
            'items' => ItemResource::collection($this->whenLoaded('items')),
            // Timestamps em ISO 8601
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

---

## Template: Form Request

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @OA\Schema(
 *     schema="Store{Nome}RequestV1",
 *     required={"name"},
 *     @OA\Property(property="name", type="string", maxLength=255),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="is_active", type="boolean", default=true),
 * )
 */
final class Store{Nome}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('api.validation.name_required'),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @OA\Schema(
 *     schema="Update{Nome}RequestV1",
 *     @OA\Property(property="name", type="string", maxLength=255),
 *     @OA\Property(property="description", type="string", nullable=true),
 * )
 */
final class Update{Nome}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

---

## Template: Auth Controller

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/auth/login",
     *     operationId="login",
     *     tags={"Auth"},
     *     summary="Autenticacao",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string"),
     *             @OA\Property(property="device_name", type="string", default="api")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Token gerado"),
     *     @OA\Response(response=422, description="Credenciais invalidas")
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('api.validation.invalid_credentials')],
            ]);
        }

        $token = $user->createToken(
            $request->input('device_name', 'api')
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
```

---

## Template: Rotas

```php
<?php

// routes/api/v1.php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

// Publicas
Route::post('auth/login', [V1\AuthController::class, 'login'])
    ->middleware('throttle:auth');

// Protegidas
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('auth/logout', [V1\AuthController::class, 'logout']);
    Route::get('auth/me', [V1\AuthController::class, 'me']);

    // Resources
    Route::apiResource('{nomes}', V1\{Nome}Controller::class);
});
```

```php
<?php

// routes/api.php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('api.v1.')->group(
    base_path('routes/api/v1.php')
);
```

---

## Template: Testes

```php
<?php

use App\Models\{Nome};
use App\Models\User;

use function Pest\Laravel\{actingAs, getJson, postJson, putJson, deleteJson};

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('GET /api/v1/{nomes}', function () {
    it('returns paginated list', function () {
        {Nome}::factory()->count(3)->create();

        actingAs($this->user)
            ->getJson('/api/v1/{nomes}')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    });

    it('filters by status', function () {
        {Nome}::factory()->create(['status' => 'active']);
        {Nome}::factory()->create(['status' => 'pending']);

        actingAs($this->user)
            ->getJson('/api/v1/{nomes}?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('sorts by field', function () {
        {Nome}::factory()->create(['name' => 'Zebra']);
        {Nome}::factory()->create(['name' => 'Alpha']);

        actingAs($this->user)
            ->getJson('/api/v1/{nomes}?sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');
    });

    it('requires authentication', function () {
        getJson('/api/v1/{nomes}')->assertUnauthorized();
    });
});

describe('POST /api/v1/{nomes}', function () {
    it('creates a record', function () {
        $data = {Nome}::factory()->make()->toArray();

        actingAs($this->user)
            ->postJson('/api/v1/{nomes}', $data)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name']]);
    });

    it('validates required fields', function () {
        actingAs($this->user)
            ->postJson('/api/v1/{nomes}', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });
});

describe('GET /api/v1/{nomes}/{id}', function () {
    it('returns a record', function () {
        ${nome} = {Nome}::factory()->create();

        actingAs($this->user)
            ->getJson("/api/v1/{nomes}/{${nome}->id}")
            ->assertOk()
            ->assertJsonPath('data.id', ${nome}->id);
    });

    it('returns 404 for missing record', function () {
        actingAs($this->user)
            ->getJson('/api/v1/{nomes}/non-existent')
            ->assertNotFound();
    });
});

describe('PUT /api/v1/{nomes}/{id}', function () {
    it('updates a record', function () {
        ${nome} = {Nome}::factory()->create();

        actingAs($this->user)
            ->putJson("/api/v1/{nomes}/{${nome}->id}", ['name' => 'Updated'])
            ->assertOk();

        expect(${nome}->fresh()->name)->toBe('Updated');
    });
});

describe('DELETE /api/v1/{nomes}/{id}', function () {
    it('deletes a record', function () {
        ${nome} = {Nome}::factory()->create();

        actingAs($this->user)
            ->deleteJson("/api/v1/{nomes}/{${nome}->id}")
            ->assertNoContent();
    });
});
```

---

## Checklist de Implementacao

### Para cada Resource exposto via API:
- [ ] Controller em `app/Http/Controllers/Api/V{n}/{Nome}Controller.php`
- [ ] StoreRequest em `app/Http/Requests/Api/V{n}/Store{Nome}Request.php`
- [ ] UpdateRequest em `app/Http/Requests/Api/V{n}/Update{Nome}Request.php`
- [ ] Resource em `app/Http/Resources/Api/V{n}/{Nome}Resource.php`
- [ ] Rotas em `routes/api/v{n}.php`
- [ ] Anotacoes Swagger em Controller e Resource
- [ ] Testes em `tests/Feature/Api/V{n}/{Nome}ApiTest.php`
- [ ] Traducoes de validacao em `lang/{locale}/api.php`
- [ ] `php artisan l5-swagger:generate` executado

### Setup Inicial (uma vez por projeto):
- [ ] Pacote de autenticacao instalado (Sanctum ou Passport)
- [ ] L5-Swagger instalado e configurado
- [ ] `routes/api.php` com prefixos de versao
- [ ] `routes/api/v1.php` criado
- [ ] Auth endpoints implementados (login, logout, me)
- [ ] Rate limiting configurado
- [ ] CORS configurado
- [ ] Handler de excecoes para JSON configurado
- [ ] `lang/pt_BR/api.php` e `lang/en/api.php` criados
