---
description: Cria um Service com DTO correspondente
---

# /service $ARGUMENTS

Crie um Service para **$ARGUMENTS** com DTO correspondente.

## Convenção de Nomes

Se `$ARGUMENTS` = "Order":
- Service: `OrderService`
- DTO: `OrderData`

Se `$ARGUMENTS` = "ProcessPayment":
- Service: `ProcessPaymentService`
- DTO: `PaymentData`

## Passos

### 0. Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente.**

### 1. Análise

Verifique:
- Se existe Model relacionado
- Campos do Model para o DTO
- Operações necessárias (CRUD? Custom?)

### 2. Criar DTO

`app/DTOs/{Nome}Data.php`:

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class {Nome}Data
{
    public function __construct(
        // Campos baseados no Model
        public string $campo_obrigatorio,
        public ?string $campo_opcional = null,
        public ?string $id = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            campo_obrigatorio: $data['campo_obrigatorio'],
            campo_opcional: $data['campo_opcional'] ?? null,
            id: $data['id'] ?? null,
        );
    }

    public static function fromRequest(FormRequest $request): static
    {
        return static::fromArray($request->validated());
    }

    public static function fromModel(Model $model): static
    {
        return new static(
            campo_obrigatorio: $model->campo_obrigatorio,
            campo_opcional: $model->campo_opcional,
            id: $model->id,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'campo_obrigatorio' => $this->campo_obrigatorio,
            'campo_opcional' => $this->campo_opcional,
        ], fn ($value) => $value !== null);
    }
}
```

### 3. Criar Service

`app/Services/{Nome}Service.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\{Nome}Data;
use App\Events\{Nome}Created;
use App\Events\{Nome}Updated;
use App\Events\{Nome}Deleted;
use App\Models\{Nome};
use Illuminate\Support\Facades\DB;

final class {Nome}Service
{
    public function create({Nome}Data $data): {Nome}
    {
        return DB::transaction(function () use ($data) {
            ${nome} = {Nome}::create($data->toArray());

            {Nome}Created::dispatch(${nome});

            return ${nome};
        });
    }

    public function update({Nome} ${nome}, {Nome}Data $data): {Nome}
    {
        return DB::transaction(function () use (${nome}, $data) {
            ${nome}->update($data->toArray());

            {Nome}Updated::dispatch(${nome});

            return ${nome}->fresh();
        });
    }

    public function delete({Nome} ${nome}): void
    {
        DB::transaction(function () use (${nome}) {
            ${nome}->delete();

            {Nome}Deleted::dispatch(${nome});
        });
    }
}
```

### 4. Criar Events (opcional)

Se PROJECT.md indica `usar_events: true`:

```bash
php artisan make:event {Nome}Created
php artisan make:event {Nome}Updated
php artisan make:event {Nome}Deleted
```

### 5. Criar Testes

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

it('creates from dto', function () {
    $data = new {Nome}Data(
        campo_obrigatorio: 'valor',
    );

    $result = $this->service->create($data);

    expect($result)
        ->toBeInstanceOf({Nome}::class)
        ->campo_obrigatorio->toBe('valor');
});

it('dispatches event on create', function () {
    Event::fake([{Nome}Created::class]);

    $this->service->create(new {Nome}Data(campo_obrigatorio: 'valor'));

    Event::assertDispatched({Nome}Created::class);
});

it('updates model', function () {
    ${nome} = {Nome}::factory()->create();
    $data = new {Nome}Data(campo_obrigatorio: 'novo valor');

    $result = $this->service->update(${nome}, $data);

    expect($result->campo_obrigatorio)->toBe('novo valor');
});

it('soft deletes model', function () {
    ${nome} = {Nome}::factory()->create();

    $this->service->delete(${nome});

    expect({Nome}::find(${nome}->id))->toBeNull();
});
```

### 6. Code Quality

Execute antes de finalizar:
```bash
vendor/bin/pint --dirty --format agent
```
Consulte `.ai/docs/pint.md`

## Output

```
✅ Arquivos criados:
- app/DTOs/{Nome}Data.php
- app/Services/{Nome}Service.php
- app/Events/{Nome}Created.php (se usar_events)
- app/Events/{Nome}Updated.php (se usar_events)
- app/Events/{Nome}Deleted.php (se usar_events)
- tests/Unit/Services/{Nome}ServiceTest.php
```
