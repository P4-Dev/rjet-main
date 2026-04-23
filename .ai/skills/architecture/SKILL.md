# Skill: Arquitetura Laravel

## Quando Usar

Use este skill quando precisar criar ou refatorar:

- DTOs (Data Transfer Objects)
- Services
- Actions
- Repositories
- Integrations
- Enums

## Agrupamento por Domínio

> **IMPORTANTE:** Antes de criar qualquer arquivo, verifique `agrupar_por_dominio` em PROJECT.md.
> Se o tipo de arquivo está configurado para agrupar, crie dentro de uma subpasta de domínio.

**Exemplo:** Se `agrupar_por_dominio.jobs: true` e o job é de email:
- Namespace: `App\Jobs\Email`
- Arquivo: `app/Jobs/Email/SendWelcomeJob.php`

**Regra de namespace:** Adicione o nome do domínio (PascalCase) ao namespace base:
- `App\Jobs\{Dominio}\{Nome}Job`
- `App\Events\{Entidade}\{Nome}Event`
- `App\Actions\{Entidade}\{Nome}Action`
- `App\Listeners\{Entidade}\{Nome}Listener`
- `App\Integrations\{Provider}\{Nome}Client`

## DTOs

### Template Básico

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class {Nome}Data
{
    public function __construct(
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

### Regras

- Sempre `final readonly`
- Propriedades no constructor
- Campos opcionais com `?` e default `null`
- ID sempre opcional (null em criação)
- Métodos estáticos: `fromArray`, `fromModel`, `fromRequest`
- Método `toArray` para persistência

## Services

### Template Básico

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\{Nome}Data;
use App\Events\{Nome}Created;
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

            return ${nome}->fresh();
        });
    }

    public function delete({Nome} ${nome}): void
    {
        ${nome}->delete();
    }
}
```

### Regras

- Sempre `final`
- Injetar dependências via constructor
- Receber DTOs, não arrays
- Usar transactions para múltiplas operações
- Disparar events após operações
- Retornar Models, não arrays

## Actions

### Template Básico

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\{Nome}Data;
use App\Models\{Nome};
use App\Services\{Nome}Service;
use Lorisleiva\Actions\Concerns\AsAction;

final class Create{Nome}Action
{
    use AsAction;

    public function __construct(
        private readonly {Nome}Service $service,
    ) {}

    public function handle({Nome}Data $data): {Nome}
    {
        return $this->service->create($data);
    }

    public function asJob({Nome}Data $data): void
    {
        $this->handle($data);
    }

    public function asController(Create{Nome}Request $request): {Nome}Resource
    {
        ${nome} = $this->handle({Nome}Data::fromRequest($request));
        
        return new {Nome}Resource(${nome});
    }
}
```

### Regras

- Sempre `final`
- Usar `AsAction` trait
- Método `handle` é o principal
- `asJob` para execução em queue
- `asController` para HTTP

## Enums

### Regra Principal

```
BANCO DE DADOS  →  string (nunca enum)
MODEL CAST      →  Enum PHP
```

### Template de Migration

```php
// ✅ CORRETO
$table->string('status', 20)->default('pending')->index();
$table->string('type', 30)->nullable();

// ❌ ERRADO - nunca use enum no banco
$table->enum('status', ['a', 'b']); // NÃO!
```

### Template de Enum

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum {Nome}Status: string implements HasLabel, HasColor, HasIcon
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Inactive => 'Inativo',
            self::Pending => 'Pendente',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'danger',
            self::Pending => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-o-check-circle',
            self::Inactive => 'heroicon-o-x-circle',
            self::Pending => 'heroicon-o-clock',
        };
    }
}
```

### Template de Model Cast

```php
protected function casts(): array
{
    return [
        'status' => {Nome}Status::class,
    ];
}
```

### Regras

- Banco: sempre `string` com tamanho adequado (20-30 chars)
- Adicione `->index()` se filtrar por esse campo
- Adicione `->default()` com valor do enum
- Model: cast para a classe Enum
- Enum: implemente `HasLabel`, `HasColor` para Filament
```

## Integrations

### Template Básico

```php
<?php

declare(strict_types=1);

namespace App\Integrations\{Provider};

use App\DTOs\{Input}Data;
use App\DTOs\{Output}Data;
use App\Integrations\Contracts\{Nome}Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class {Provider}{Nome}Integration implements {Nome}Integration
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.{provider}.url'))
            ->withToken(config('services.{provider}.secret'))
            ->timeout(30)
            ->retry(3, 100)
            ->acceptJson();
    }

    public function execute({Input}Data $data): {Output}Data
    {
        $response = $this->client->post('/endpoint', $data->toArray());

        if ($response->failed()) {
            throw new {Provider}Exception($response->body());
        }

        return {Output}Data::fromArray($response->json());
    }
}
```

### Regras

- Implementar interface (Contract) em `Contracts/`
- Organizar por provider em subpastas (`Asaas/`, `Stripe/`, `Twilio/`)
- Configurar timeout e retry
- Usar DTOs para input e output
- Lançar exceções específicas

## Checklist de Criação

### DTO
- [ ] Namespace correto
- [ ] `final readonly`
- [ ] Tipos em todas propriedades
- [ ] Método `fromArray`
- [ ] Método `toArray`

### Service
- [ ] Namespace correto
- [ ] `final`
- [ ] Injeção via constructor
- [ ] Recebe DTOs
- [ ] Usa transactions
- [ ] Dispara events

### Action
- [ ] Namespace correto
- [ ] `final`
- [ ] Usa `AsAction`
- [ ] Método `handle`
- [ ] `asJob` se precisar de queue

### Enum
- [ ] Backed enum (`: string` ou `: int`)
- [ ] Implementa `HasLabel` se usado em Filament
- [ ] Implementa `HasColor` se precisar de badges
