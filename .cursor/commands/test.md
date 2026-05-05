---
description: Cria testes Pest para uma classe ou Resource
---

# /test $ARGUMENTS

Crie testes Pest para **$ARGUMENTS**.

## Detecção de Tipo

Analise $ARGUMENTS para determinar o tipo:

| Pattern | Tipo | Caminho |
|---------|------|---------|
| `*Resource` | Filament Resource | `tests/Feature/Filament/` |
| `*Service` | Service | `tests/Feature/Services/` |
| `*Action` | Action | `tests/Unit/Actions/` |
| `*Controller` | Controller/API | `tests/Feature/Api/` |
| `*` (Model) | Model | `tests/Unit/Models/` |

## Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente.**

## Criação

**Verifique `usar_docker` em PROJECT.md** — se Docker, prefixar com `docker compose exec {container}`.

Use `php artisan make:test --pest {NomeTest}` para criar o arquivo base.

## Templates por Tipo

### Filament Resource

`tests/Feature/Filament/{Nome}ResourceTest.php`:

```php
<?php

use App\Filament\Resources\{Nome}Resource\Pages\{Create{Nome}, Edit{Nome}, List{Nomes}};
use App\Models\{Nome};
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    actingAs(User::factory()->admin()->create());
});

describe('list page', function () {
    it('renders successfully', function () {
        Livewire::test(List{Nomes}::class)
            ->assertSuccessful();
    });

    it('lists records', function () {
        $records = {Nome}::factory()->count(3)->create();

        Livewire::test(List{Nomes}::class)
            ->assertCanSeeTableRecords($records);
    });

    it('searches by name', function () {
        $record = {Nome}::factory()->create(['name' => 'Teste']);

        Livewire::test(List{Nomes}::class)
            ->searchTable('Teste')
            ->assertCanSeeTableRecords([$record]);
    });

    it('filters by status', function () {
        $active = {Nome}::factory()->active()->create();
        $inactive = {Nome}::factory()->inactive()->create();

        Livewire::test(List{Nomes}::class)
            ->filterTable('status', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    });
});

describe('create page', function () {
    it('renders successfully', function () {
        Livewire::test(Create{Nome}::class)
            ->assertSuccessful();
    });

    it('creates a record', function () {
        Livewire::test(Create{Nome}::class)
            ->fillForm([
                'name' => 'Novo Item',
                // outros campos...
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        assertDatabaseHas('{nomes}', ['name' => 'Novo Item']);
    });

    it('validates required fields', function () {
        Livewire::test(Create{Nome}::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    });
});

describe('edit page', function () {
    it('renders successfully', function () {
        $record = {Nome}::factory()->create();

        Livewire::test(Edit{Nome}::class, ['record' => $record->id])
            ->assertSuccessful();
    });

    it('updates a record', function () {
        $record = {Nome}::factory()->create();

        Livewire::test(Edit{Nome}::class, ['record' => $record->id])
            ->fillForm(['name' => 'Atualizado'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($record->fresh()->name)->toBe('Atualizado');
    });

    it('deletes a record', function () {
        $record = {Nome}::factory()->create();

        Livewire::test(Edit{Nome}::class, ['record' => $record->id])
            ->callAction('delete');

        expect({Nome}::find($record->id))->toBeNull();
    });
});

describe('authorization', function () {
    it('denies access to guests', function () {
        auth()->logout();

        Livewire::test(List{Nomes}::class)
            ->assertForbidden();
    });
});
```

### Service

`tests/Feature/Services/{Nome}ServiceTest.php`:

```php
<?php

use App\DTOs\{Nome}Data;
use App\Events\{Nome}Created;
use App\Models\{Nome};
use App\Services\{Nome}Service;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = app({Nome}Service::class);
});

it('creates record from dto', function () {
    $data = new {Nome}Data(name: 'Test');

    $result = $this->service->create($data);

    expect($result)
        ->toBeInstanceOf({Nome}::class)
        ->name->toBe('Test');
});

it('dispatches created event', function () {
    Event::fake([{Nome}Created::class]);

    $this->service->create(new {Nome}Data(name: 'Test'));

    Event::assertDispatched({Nome}Created::class);
});

it('updates record', function () {
    $record = {Nome}::factory()->create(['name' => 'Old']);

    $result = $this->service->update(
        $record,
        new {Nome}Data(name: 'New')
    );

    expect($result->name)->toBe('New');
});

it('deletes record', function () {
    $record = {Nome}::factory()->create();

    $this->service->delete($record);

    expect({Nome}::find($record->id))->toBeNull();
});
```

### API Controller

`tests/Feature/Api/{Nome}ApiTest.php`:

```php
<?php

use App\Models\{Nome};
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\deleteJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns paginated list', function () {
    {Nome}::factory()->count(15)->create();

    actingAs($this->user)
        ->getJson('/api/{nomes}')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'name', 'created_at']],
            'meta' => ['current_page', 'total'],
        ]);
});

it('requires authentication', function () {
    getJson('/api/{nomes}')
        ->assertUnauthorized();
});

it('creates record', function () {
    actingAs($this->user)
        ->postJson('/api/{nomes}', ['name' => 'Test'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Test');
});

it('validates request', function () {
    actingAs($this->user)
        ->postJson('/api/{nomes}', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('returns single record', function () {
    $record = {Nome}::factory()->create();

    actingAs($this->user)
        ->getJson("/api/{nomes}/{$record->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $record->id);
});

it('returns 404 for non existent', function () {
    actingAs($this->user)
        ->getJson('/api/{nomes}/non-existent')
        ->assertNotFound();
});

it('updates record', function () {
    $record = {Nome}::factory()->create();

    actingAs($this->user)
        ->putJson("/api/{nomes}/{$record->id}", ['name' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated');
});

it('deletes record', function () {
    $record = {Nome}::factory()->create();

    actingAs($this->user)
        ->deleteJson("/api/{nomes}/{$record->id}")
        ->assertNoContent();

    expect({Nome}::find($record->id))->toBeNull();
});
```

### Model

`tests/Unit/Models/{Nome}Test.php`:

```php
<?php

use App\Models\{Nome};

it('belongs to parent', function () {
    $record = {Nome}::factory()->create();

    expect($record->parent)->toBeInstanceOf(Parent::class);
});

it('has many children', function () {
    $record = {Nome}::factory()
        ->has(Child::factory()->count(3))
        ->create();

    expect($record->children)->toHaveCount(3);
});

it('filters active records', function () {
    {Nome}::factory()->active()->count(2)->create();
    {Nome}::factory()->inactive()->create();

    expect({Nome}::active()->count())->toBe(2);
});

it('casts status to enum', function () {
    $record = {Nome}::factory()->create(['status' => 'active']);

    expect($record->status)->toBe(Status::Active);
});
```

## Output

```
Testes criados:
- tests/{Feature|Unit}/{Tipo}/{Nome}Test.php

Para executar:
php artisan test --filter={Nome}
```

## Code Quality

Após criar os testes, execute:
```bash
vendor/bin/pint --dirty --format agent
```
Consulte `.ai/docs/pint.md`
