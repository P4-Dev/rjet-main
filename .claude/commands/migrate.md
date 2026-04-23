---
description: Gera migration inteligente seguindo database.md e padroes do projeto
---

# /migrate $ARGUMENTS

Gere uma migration para **$ARGUMENTS** seguindo rigorosamente os padroes do projeto.

## Passos

### 0. Preferências (OBRIGATÓRIO)

Leia **"Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente.**

### 1. Analise Inicial

Antes de gerar a migration:

- Leia `.ai/docs/database.md` — padroes de nomenclatura, normalização, indices
- Leia `.ai/docs/enums.md` — verificar se ha campos de status/type
- Leia `PROJECT.md` — verificar `usar_uuid`, `usar_soft_deletes`, `usar_docker`, `container_app`
- Use `schema` para verificar tabelas existentes (evitar duplicatas e entender relationships)
- **Se Docker:** prefixar comandos artisan com `docker compose exec {container_app}`

### 2. Deteccao Automatica

Analise o nome da tabela e os campos para detectar automaticamente:

#### 2.1 Morph (Entidades Compartilhadas)

Se a tabela e uma das seguintes, sugerir morph ao inves de FK dedicada:
- `addresses` → `uuidMorphs('addressable')`
- `contacts` → `uuidMorphs('contactable')`
- `notes` / `comments` → `uuidMorphs('notable')` / `uuidMorphs('commentable')`
- `attachments` → `uuidMorphs('attachable')`
- `tags` / `taggables` → tabela pivot morph
- `activities` / `logs` → `uuidMorphs('loggable')`

**Consultar a Matriz de Decisao em `database.md` secao 3** para confirmar se morph e adequado.

#### 2.2 Nomenclatura Automatica

Aplicar automaticamente estas convencoes:

| Padrao Detectado | Tipo | Regra |
|-----------------|------|-------|
| `is_*`, `has_*` | `boolean` | `->default(false)->index()` |
| `*_at` | `timestamp` | `->nullable()` |
| `*_by` | `foreignUuid` | `->nullable()->constrained('users')->nullOnDelete()` |
| `sort_order` | `unsignedInteger` | `->default(0)->index()` |
| `price`, `total`, `subtotal`, `discount`, `tax`, `balance` | `decimal(10, 2)` | `->default(0)` |
| `quantity`, `stock`, `min_stock` | `unsignedInteger` | `->default(0)` |
| `status`, `type`, `priority` | `string(20)` | `->index()` + sugerir PHP Enum |
| `slug` | `string` | `->unique()` |
| `code` | `string(30)` | `->unique()` |
| `email` | `string` | `->index()` (ou `->unique()` se principal) |
| `document` | `string(20)` | `->nullable()->index()` |
| `phone`, `mobile` | `string(20)` | `->nullable()` |
| `name` | `string` | (sem modificadores extras) |
| `title` | `string` | (sem modificadores extras) |
| `description` | `string(500)` | `->nullable()` |
| `notes`, `body` | `text` | `->nullable()` |

#### 2.3 Indices Automaticos

Criar indices automaticamente para:
- Campos de status/type: `->index()`
- Campos booleanos: `->index()`
- FKs: automatico via `constrained()`
- Morph: automatico via `uuidMorphs()`
- Compostos frequentes: `['status', 'created_at']`, `['tenant_id', 'status']`

### 3. Validacao de Enum

**PROIBIDO:** `$table->enum()` — NUNCA usar no banco.

Se o campo e um status, type ou priority:

```php
// CORRETO
$table->string('status', 20)->default('pending')->index();

// PROIBIDO
$table->enum('status', ['pending', 'active', 'cancelled']);
```

Apos criar a migration, **sugerir criacao do PHP Enum** correspondente:

```php
// Sugerir: app/Enums/{Nome}Status.php
enum OrderStatus: string implements HasLabel, HasColor, HasIcon
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';

    // ...
}
```

### 4. Template de Migration

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{table}', function (Blueprint $table) {
            // ---- PK ----
            $table->uuid('id')->primary();

            // ---- Foreign Keys ----
            $table->foreignUuid('{parent}_id')->constrained()->cascadeOnDelete();

            // ---- Campos ----
            $table->string('name');
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ---- Timestamps ----
            $table->timestamps();
            $table->softDeletes();

            // ---- Indices Compostos ----
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{table}');
    }
};
```

### 5. Regras Obrigatorias

#### PK
- **Sempre UUID:** `$table->uuid('id')->primary()`
- Nunca `bigIncrements`

#### Foreign Keys
- **Sempre** `constrained()` com cascade explícito
- `cascadeOnDelete()` para filhos que nao existem sem pai
- `nullOnDelete()` para referencias opcionais (autoria, etc.)
- `restrictOnDelete()` para impedir exclusao de pai com filhos

#### Soft Deletes
- Adicionar `$table->softDeletes()` para entidades de dominio
- Nao usar para tabelas pivot ou logs

#### Timestamps
- **Sempre** `$table->timestamps()`

#### Nomenclatura da Tabela
- Plural, snake_case: `orders`, `order_items`, `stock_movements`
- Pivot: `model1_model2` (ordem alfabetica, singular): `order_product`

### 6. Checklist DBA Automatico

Antes de finalizar, validar:

- [ ] **3NF:** Cada campo depende da PK e de nada mais?
- [ ] **Nomenclatura:** Todos os campos seguem convencoes de `database.md`?
- [ ] **Enum proibido:** Nenhum `$table->enum()` usado?
- [ ] **Indices:** Campos de busca/filtro/ordenacao tem indice?
- [ ] **FKs:** Todas as FKs usam `constrained()` com cascade explicito?
- [ ] **Morph:** Entidades compartilhadas usam `uuidMorphs()` quando adequado?
- [ ] **Desnormalizacao:** Se houver, esta documentada com comentario?
- [ ] **Valores monetarios:** Usam `decimal(10, 2)`, nunca `float`?

### 7. Criar a Migration

```bash
php artisan make:migration create_{table}_table --no-interaction
```

Editar o arquivo gerado com a estrutura definida.

### 8. Rodar a Migration

```bash
php artisan migrate
```

Se Docker:
```bash
docker compose exec {container_app} php artisan migrate
```

### 9. Output

Ao finalizar, apresentar:

```
Arquivo criado:
- database/migrations/xxxx_create_{table}_table.php

Decisoes tomadas:
- PK: UUID
- Soft Deletes: sim/nao (motivo)
- Indices: {lista}
- Morph: sim/nao (motivo)
- Campos enum detectados: {lista} → sugerir PHP Enum

Proximos passos recomendados:
1. Criar Model: php artisan make:model {Nome}
2. Criar Enum (se detectado): app/Enums/{Nome}Status.php
3. Criar Factory: php artisan make:factory {Nome}Factory
4. Configurar casts() no Model para Enums
5. Registrar morph map se usar morph (AppServiceProvider)
```

---

## Exemplos

### Exemplo 1: Tabela Simples

```
/migrate products
```

```php
Schema::create('products', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('description', 500)->nullable();
    $table->decimal('price', 10, 2)->default(0);
    $table->unsignedInteger('stock')->default(0);
    $table->unsignedInteger('min_stock')->default(0);
    $table->boolean('is_active')->default(true)->index();
    $table->unsignedInteger('sort_order')->default(0)->index();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['is_active', 'created_at']);
});
```

### Exemplo 2: Tabela com Enum

```
/migrate orders
```

```php
Schema::create('orders', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
    $table->string('code', 30)->unique();
    $table->string('status', 20)->default('pending')->index();
    $table->string('priority', 20)->default('normal')->index();
    $table->decimal('subtotal', 10, 2)->default(0);
    $table->decimal('discount', 10, 2)->default(0);
    $table->decimal('tax', 10, 2)->default(0);
    $table->decimal('total', 10, 2)->default(0);
    $table->text('notes')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['status', 'created_at']);
    $table->index(['customer_id', 'status']);
});

// Sugerir Enums:
// - app/Enums/OrderStatus.php (pending, approved, shipped, delivered, cancelled)
// - app/Enums/OrderPriority.php (low, normal, high, urgent)
```

### Exemplo 3: Morph Detectado

```
/migrate addresses
```

```php
Schema::create('addresses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuidMorphs('addressable');  // addressable_type + addressable_id + indice
    $table->string('type', 20)->default('main');
    $table->string('street');
    $table->string('number', 20)->nullable();
    $table->string('complement', 100)->nullable();
    $table->string('neighborhood');
    $table->string('city');
    $table->string('state', 2);
    $table->string('zip_code', 10);
    $table->string('country', 2)->default('BR');
    $table->boolean('is_default')->default(false);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['addressable_type', 'addressable_id', 'type']);
});

// Decisao: Morph usado porque 'addresses' e entidade compartilhada
// (pode pertencer a Customer, Supplier, Company, etc.)
//
// Proximo passo: Registrar morph map no AppServiceProvider:
// Relation::enforceMorphMap([
//     'customer' => Customer::class,
//     'supplier' => Supplier::class,
// ]);
```

---

## Referencias

- `.ai/docs/database.md` - Normalizacao, nomenclatura, morph vs dedicado
- `.ai/docs/enums.md` - PHP Enums, HasLabel/HasColor/HasIcon
- `.ai/docs/soft-deletes.md` - SoftDeletes, cascade, Filament trashed
- `.ai/docs/performance.md` - Indices, eager loading
- `.ai/checklists.md` - Checklist #2 (Migration)
