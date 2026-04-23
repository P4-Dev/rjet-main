# PHPStan / Larastan - Guideline

> **Regras para analise estatica obrigatoria com PHPStan e Larastan neste projeto Laravel 12 + Filament v5.**

---

## 1. Regra Fundamental

Analise estatica e **obrigatoria** em todo codigo PHP do projeto. Usamos **Larastan** (wrapper do PHPStan para Laravel) como ferramenta padrao.

| Parametro | Valor |
|-----------|-------|
| Ferramenta | Larastan (PHPStan + regras Laravel) |
| Nivel minimo | **5** |
| Nivel meta | **8** |
| CI | Deve falhar em qualquer erro |
| Execucao local | Antes de todo commit |

**Nenhum PR pode ser mergeado com erros de PHPStan.**

---

## 2. Instalacao e Configuracao

### Instalar Larastan

```bash
composer require --dev larastan/larastan
```

### Criar `phpstan.neon` na raiz do projeto

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/

    level: 5

    excludePaths:
        - app/Console/Kernel.php

    treatPhpDocTypesAsCertain: false
    checkMissingIterableValueType: false
    checkGenericClassInNonGenericObjectType: false
```

### Verificar instalacao

```bash
vendor/bin/phpstan analyse --memory-limit=2G
```

---

## 3. Niveis do PHPStan

O PHPStan possui 10 niveis (0-9). Cada nivel adiciona verificacoes mais rigorosas:

| Nivel | O Que Verifica | Recomendacao |
|:-----:|----------------|:------------:|
| 0 | Erros basicos: classes, funcoes e metodos inexistentes | - |
| 1 | Variaveis possivelmente indefinidas, `__call` e `__get` desconhecidos | - |
| 2 | Metodos desconhecidos em `mixed`, validacao de PHPDoc | - |
| 3 | Return types, tipos em propriedades atribuidas | - |
| 4 | Type checking basico (dead code em `instanceof`, `always true/false`) | - |
| **5** | **Argumentos passados a metodos/funcoes validados por tipo** | **Inicio** |
| 6 | Reporta tipos `missing` em typehints | Intermediario |
| 7 | Uniao de tipos parciais, metodos chamados em tipos que nem sempre existem | Intermediario |
| **8** | **Reporta chamadas em tipos `nullable`, validacao completa de nullability** | **Meta** |
| 9 | Tipos `mixed` sao estritamente verificados - nenhum `mixed` permitido | Avancado |

### Estrategia de Progressao

1. **Comecar no nivel 5** - cobre argumentos de metodos, erros de tipo basicos
2. **Gerar baseline** para erros existentes (ver secao 5)
3. **Subir um nivel** quando o baseline anterior estiver zerado
4. **Meta: nivel 8** - cobertura completa de nullability e tipos
5. **Nivel 9** e opcional e pode ser adotado em modulos criticos

---

## 4. Configuracao do `phpstan.neon`

### Configuracao Completa Recomendada

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    # Paths para analisar
    paths:
        - app/
        - database/factories/
        - database/seeders/

    # Nivel atual do projeto
    level: 5

    # Paths a excluir
    excludePaths:
        - app/Console/Kernel.php
        - app/Http/Middleware/*.php

    # Larastan: nao tratar PHPDoc como certeza absoluta
    # Evita falsos positivos quando PHPDoc esta desatualizado
    treatPhpDocTypesAsCertain: false

    # Nao exigir tipo de valor em iteraveis (ex: Collection sem generics)
    # Desabilitar ate atingir nivel 7+
    checkMissingIterableValueType: false

    # Nao exigir generics em classes genericas (ex: Collection<int, Model>)
    checkGenericClassInNonGenericObjectType: false

    # Ignorar erros especificos (usar com moderacao)
    ignoreErrors:
        # Exemplo: ignorar erro em metodos magicos do Filament
        # - '#Call to an undefined method .+::filament\(\)#'

    # Paralelismo para projetos grandes
    parallel:
        maximumNumberOfProcesses: 4
```

### Parametros Importantes do Larastan

| Parametro | Valor | Motivo |
|-----------|-------|--------|
| `treatPhpDocTypesAsCertain` | `false` | Evita falsos positivos com PHPDoc impreciso |
| `checkMissingIterableValueType` | `false` | Collections sem generics nao geram erro |
| `checkGenericClassInNonGenericObjectType` | `false` | Relaxa exigencia de generics |

Conforme o nivel sobe, habilitar gradualmente essas verificacoes.

---

## 5. Baseline para Codigo Legado

### O Que e o Baseline

O baseline permite "congelar" erros existentes e garantir que **nenhum erro novo** seja introduzido. Erros do baseline sao progressivamente corrigidos.

### Gerar Baseline

```bash
vendor/bin/phpstan analyse --generate-baseline --memory-limit=2G
```

Isso cria o arquivo `phpstan-baseline.neon` com todos os erros atuais.

### Incluir Baseline no `phpstan.neon`

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - app/
```

### Estrategia de Reducao do Baseline

1. **Nunca adicionar** novos erros ao baseline
2. **A cada sprint**, corrigir pelo menos 10% dos erros do baseline
3. **Ao subir de nivel**, gerar novo baseline e repetir o processo
4. **Meta**: baseline zerado no nivel 8

```bash
# Verificar quantos erros restam no baseline
grep -c 'message:' phpstan-baseline.neon
```

### Arquivo `phpstan.neon.dist`

Use `phpstan.neon.dist` como configuracao base versionada e `phpstan.neon` para overrides locais:

```neon
# phpstan.neon.dist (versionado no git)
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - app/
```

```gitignore
# .gitignore
# NÃO ignorar phpstan.neon.dist e phpstan-baseline.neon
# Ambos devem ser versionados
```

---

## 6. Regras Comuns e Solucoes

### 6.1 Propriedades de Model

**Erro:** `Access to an undefined property App\Models\Order::$status`

**Solucao:** Adicionar `@property` no PHPDoc do Model.

```php
/**
 * @property string $id
 * @property string $status
 * @property string $customer_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 */
final class Order extends Model
{
    // ...
}
```

**Dica:** Use o comando `php artisan ide-helper:models --write` (com `barryvdh/laravel-ide-helper`) para gerar automaticamente.

### 6.2 Return Types de Relationships

**Erro:** `Method App\Models\Order::customer() return type has no value type specified in iterable type`

**Solucao:** Sempre tipar return type das relationships.

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class Order extends Model
{
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }
}
```

### 6.3 Collection Generics

**Erro:** `Generic type Illuminate\Database\Eloquent\Collection does not specify its types`

**Solucao:** Usar PHPDoc com generics.

```php
/**
 * @param \Illuminate\Database\Eloquent\Collection<int, Order> $orders
 */
public function processOrders(\Illuminate\Database\Eloquent\Collection $orders): void
{
    // ...
}

/**
 * @return \Illuminate\Database\Eloquent\Collection<int, Order>
 */
public function getPendingOrders(): \Illuminate\Database\Eloquent\Collection
{
    return Order::where('status', 'pending')->get();
}
```

### 6.4 Valores de Config

**Erro:** `Parameter #1 ... expects string, mixed given` (ao usar `config()`)

**Solucao:** Fazer cast ou usar `@var`.

```php
// Opcao 1: Cast explicito
$appName = (string) config('app.name');

// Opcao 2: @var
/** @var string $appName */
$appName = config('app.name');

// Opcao 3: Helper com tipo (preferido para uso frequente)
/** @var array{name: string, url: string, debug: bool} */
$appConfig = config('app');
```

### 6.5 Request Validated Data

**Erro:** `Cannot access offset 'name' on array<string, mixed>`

**Solucao:** Usar `@var` com array shape ou Form Request tipado.

```php
// Em FormRequest - definir o tipo de retorno de validated()
final class StoreOrderRequest extends FormRequest
{
    /**
     * @return array{
     *     customer_id: string,
     *     items: array<int, array{product_id: string, quantity: int}>,
     *     notes: string|null,
     * }
     */
    public function validated($key = null, $default = null): array
    {
        return parent::validated($key, $default);
    }
}

// No Controller
public function store(StoreOrderRequest $request): JsonResponse
{
    $data = $request->validated();
    // PHPStan agora conhece a estrutura de $data
    $customerId = $data['customer_id']; // string
}
```

### 6.6 Metodos Magicos e Scopes

**Erro:** `Call to an undefined method App\Models\Order::active()`

**Solucao:** Documentar scopes no PHPDoc do Model.

```php
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static> active()
 * @method static \Illuminate\Database\Eloquent\Builder<static> pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static> forCustomer(string $customerId)
 */
final class Order extends Model
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
```

---

## 7. Type Safety Patterns

### 7.1 PHPDoc Tags Essenciais

```php
/** @var string $name */
$name = $request->input('name');

/** @param array<string, mixed> $data */
public function process(array $data): void { }

/** @return array{total: int, items: list<Order>} */
public function summary(): array { }

/**
 * @template T of Model
 * @param class-string<T> $modelClass
 * @return T
 */
public function findOrFail(string $modelClass, string $id): Model { }
```

### 7.2 Array Shapes

Use array shapes para definir a estrutura exata de arrays:

```php
/**
 * @param array{
 *     name: string,
 *     email: string,
 *     age?: int,
 *     roles: list<string>,
 *     address: array{street: string, city: string, state: string},
 * } $data
 */
public function createUser(array $data): User
{
    // PHPStan valida todos os acessos a $data
    return User::create([
        'name' => $data['name'],
        'email' => $data['email'],
    ]);
}
```

### 7.3 Generics em Classes Proprias

```php
/**
 * @template T
 */
final class Result
{
    /**
     * @param T $data
     */
    public function __construct(
        public readonly mixed $data,
        public readonly bool $success = true,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param T $data
     * @return self<T>
     */
    public static function ok(mixed $data): self
    {
        return new self(data: $data);
    }

    /**
     * @return self<null>
     */
    public static function fail(string $error): self
    {
        return new self(data: null, success: false, error: $error);
    }
}

// Uso tipado
/** @var Result<Order> $result */
$result = Result::ok($order);
```

### 7.4 Assertions e Narrowing

```php
use Webmozart\Assert\Assert;

public function processOrder(mixed $orderId): void
{
    Assert::string($orderId);
    // PHPStan agora sabe que $orderId e string

    $order = Order::findOrFail($orderId);
    Assert::isInstanceOf($order, Order::class);
    // PHPStan agora sabe que $order e Order
}
```

---

## 8. Integracao com CI

### GitHub Actions

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  phpstan:
    name: PHPStan
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --no-progress --error-format=github --memory-limit=2G
```

### Parametros Importantes no CI

| Parametro | Motivo |
|-----------|--------|
| `--no-progress` | Remove barra de progresso (limpa output no CI) |
| `--error-format=github` | Formata erros como annotations no PR |
| `--memory-limit=2G` | Evita estouro de memoria em projetos grandes |

### Resultado no PR

Com `--error-format=github`, os erros aparecem diretamente como **annotations** nos arquivos modificados no PR, facilitando a correcao.

---

## 9. Integracao com Filament v5

### Closures em Componentes

Filament usa closures extensivamente. PHPStan pode reclamar de tipos em closures. Solucao: tipar parametros explicitamente.

```php
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;

// Tipar parametros de closures
Select::make('status')
    ->options(OrderStatus::class)
    ->required()
    ->live()
    ->afterStateUpdated(function (string $state, Get $get): void {
        // PHPStan conhece os tipos de $state e $get
    }),

TextInput::make('total')
    ->visible(fn (Get $get): bool => $get('type') === 'invoice'),

// Tipar $record em colunas
TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),
```

### Actions com Tipagem

```php
use Filament\Actions\Action;

Action::make('approve')
    ->action(function (Order $record, array $data): void {
        // PHPStan conhece o tipo de $record e $data
        $record->update(['status' => 'approved']);
    })
    ->form([
        TextInput::make('reason')->required(),
    ]),
```

### Erros Comuns com Filament

| Erro | Causa | Solucao |
|------|-------|---------|
| `Undefined method ::make()` | PHPStan nao reconhece static | Larastan resolve automaticamente |
| `Parameter expects Closure, Closure given` | Tipo de retorno da closure ausente | Tipar retorno: `fn (): bool =>` |
| `mixed in closure parameter` | Parametro sem tipo | Tipar: `fn (Get $get): bool =>` |
| `Cannot access property on mixed` | `$record` sem tipo na closure | Tipar: `fn (Order $record): string =>` |

### Ignorar Erros Especificos do Filament (ultimo recurso)

```neon
parameters:
    ignoreErrors:
        # Apenas se absolutamente necessario
        - '#Call to an undefined method .+\\Table::filament#'
```

---

## 10. Composer Scripts

### Adicionar ao `composer.json`

```json
{
    "scripts": {
        "stan": "vendor/bin/phpstan analyse --memory-limit=2G",
        "stan:baseline": "vendor/bin/phpstan analyse --generate-baseline --memory-limit=2G",
        "stan:ci": "vendor/bin/phpstan analyse --no-progress --error-format=github --memory-limit=2G",
        "check": [
            "@stan",
            "@test",
            "vendor/bin/pint --test"
        ],
        "test": "php artisan test --compact"
    }
}
```

### Uso

```bash
# Analise completa
composer stan

# Gerar baseline
composer stan:baseline

# Rodar no CI
composer stan:ci

# Check completo (PHPStan + Testes + Pint)
composer check
```

### Integracao com Script `check`

O script `check` deve rodar **PHPStan primeiro**, depois testes, depois Pint. Se PHPStan falhar, os demais nao executam, economizando tempo.

---

## 11. Checklist

### Configuracao Inicial
- [ ] Larastan instalado (`composer require --dev larastan/larastan`)
- [ ] `phpstan.neon` criado na raiz com `level: 5`
- [ ] Baseline gerado para erros existentes
- [ ] Composer scripts adicionados (`stan`, `stan:baseline`, `stan:ci`)
- [ ] GitHub Actions configurado com `--error-format=github`

### Em Cada Feature
- [ ] Return types explicitos em todos os metodos
- [ ] PHPDoc `@property` em Models para propriedades acessadas
- [ ] PHPDoc `@return` com generics em relationships (`BelongsTo<Customer, $this>`)
- [ ] Array shapes em metodos que retornam arrays complexos
- [ ] Closures do Filament com parametros tipados
- [ ] Scopes documentados com `@method` no Model
- [ ] `vendor/bin/phpstan analyse` rodado sem erros antes do commit

### Progressao de Nivel
- [ ] Nivel 5 atingido e estavel (baseline zerado)
- [ ] Nivel 6 atingido (typehints em todos os parametros)
- [ ] Nivel 7 atingido (union types parciais corrigidos)
- [ ] Nivel 8 atingido (nullability completa)
- [ ] `checkMissingIterableValueType` habilitado (apos nivel 7)
- [ ] `treatPhpDocTypesAsCertain` habilitado (apos baseline limpo)
