# Padrões de Banco de Dados e Normalização

> Esta guideline define os padrões de normalização, nomenclatura de campos e estratégias
> para entidades compartilhadas (endereços, contatos, etc.) no projeto.

---

## 1. Normalização

### Nível Mínimo: 3NF (Terceira Forma Normal)

Toda tabela deve cumprir:

1. **1NF** - Cada coluna contém valores atômicos (não armazenar arrays/listas em string)
2. **2NF** - Todo campo não-chave depende da chave primária inteira
3. **3NF** - Nenhum campo não-chave depende de outro campo não-chave

### Quando Desnormalizar

Desnormalização **só é aceitável** quando:

| Cenário | Justificativa | Exemplo |
|---------|---------------|---------|
| Cache de agregação | Evitar JOINs pesados em dashboards | `orders.items_count`, `orders.total` |
| Dados históricos | Preservar snapshot no momento da operação | `order_items.unit_price` (cópia do preço atual) |
| Performance crítica | Query com > 100k registros e latência < 100ms | Relatórios denormalizados |

**Regra:** sempre documente com comentário na migration o motivo da desnormalização.

```php
// Desnormalizado: snapshot do preço no momento da compra (3NF seria FK para price_history)
$table->decimal('unit_price', 10, 2);
```

---

## 2. Padronização de Campos

### Campos Obrigatórios em Toda Tabela

```php
$table->uuid('id')->primary();          // PK sempre UUID
$table->timestamps();                    // created_at, updated_at
$table->softDeletes();                   // deleted_at
```

### Convenção de Nomes por Tipo

#### Booleanos - prefixo `is_` ou `has_`

```php
$table->boolean('is_active')->default(true)->index();
$table->boolean('is_default')->default(false);
$table->boolean('is_verified')->default(false);
$table->boolean('is_featured')->default(false);
$table->boolean('has_attachments')->default(false);
```

**Nunca:** `active`, `enabled`, `visible` (sem prefixo)

#### Ordenação - `sort_order`

```php
$table->unsignedInteger('sort_order')->default(0)->index();
```

**Padrão:** `sort_order` (não `order`, `position`, `order_column`)

#### Status/Tipo - `string` com Enum PHP

```php
$table->string('status', 20)->default('pending')->index();
$table->string('type', 30)->nullable()->index();
$table->string('priority', 20)->default('normal')->index();
```

**Regra:** banco = `string`, Model = cast para Enum PHP

#### Datas de evento - sufixo `_at`

```php
$table->timestamp('published_at')->nullable();
$table->timestamp('approved_at')->nullable();
$table->timestamp('expired_at')->nullable()->index();
$table->timestamp('completed_at')->nullable();
$table->timestamp('cancelled_at')->nullable();
$table->timestamp('verified_at')->nullable();
$table->timestamp('starts_at')->nullable();
$table->timestamp('ends_at')->nullable();
```

**Nunca:** `publish_date`, `date_approved`, `expiry`

#### Rastreamento de autoria - sufixo `_by`

```php
$table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
```

#### Valores monetários - `decimal(10, 2)` com nome descritivo

```php
$table->decimal('price', 10, 2)->default(0);
$table->decimal('unit_price', 10, 2);
$table->decimal('total', 10, 2)->default(0);
$table->decimal('subtotal', 10, 2)->default(0);
$table->decimal('discount', 10, 2)->default(0);
$table->decimal('tax', 10, 2)->default(0);
$table->decimal('balance', 10, 2)->default(0);
```

**Nunca:** `float` ou `double` para dinheiro

#### Quantidades - `integer` ou `decimal` conforme contexto

```php
$table->unsignedInteger('quantity')->default(0);
$table->decimal('weight', 8, 3)->nullable();       // peso em kg
$table->unsignedInteger('stock')->default(0);
$table->unsignedInteger('min_stock')->default(0);   // estoque mínimo
```

#### Textos

```php
$table->string('name');                  // entidades (pessoas, empresas)
$table->string('title');                 // coisas (posts, páginas, documentos)
$table->string('description', 500)->nullable();
$table->text('notes')->nullable();       // notas internas
$table->text('body')->nullable();        // conteúdo longo
$table->string('slug')->unique();        // URL-friendly
$table->string('code', 30)->unique();    // código interno
$table->string('reference', 50)->nullable(); // referência externa
```

#### Documentos e identificação

```php
$table->string('document', 20)->nullable()->index();   // CPF/CNPJ
$table->string('email')->nullable()->index();
$table->string('phone', 20)->nullable();
$table->string('website')->nullable();
```

#### Foreign Keys

```php
// Padrão: nome_do_model_id
$table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
$table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
$table->foreignUuid('parent_id')->nullable()->constrained('categories')->nullOnDelete();
```

### Tabela Resumo de Convenções

| Tipo | Prefixo/Sufixo | Tipo DB | Exemplo |
|------|----------------|---------|---------|
| Booleano | `is_`, `has_` | `boolean` | `is_active` |
| Ordenação | - | `unsignedInteger` | `sort_order` |
| Status/Tipo | - | `string(20-30)` | `status`, `type` |
| Data evento | `_at` | `timestamp` | `published_at` |
| Autoria | `_by` | `foreignUuid` | `created_by` |
| Dinheiro | - | `decimal(10,2)` | `price`, `total` |
| Quantidade | - | `unsignedInteger` | `quantity`, `stock` |
| FK | `_id` | `foreignUuid` | `customer_id` |
| Slug | - | `string + unique` | `slug` |
| Código | - | `string + unique` | `code` |

---

## 3. Entidades Compartilhadas: Morph vs Dedicado

### Matriz de Decisão

Use esta matriz para decidir se uma entidade compartilhada deve usar morph ou tabelas dedicadas:

| Critério | Morph | Dedicado |
|----------|:-----:|:--------:|
| Estrutura idêntica entre models | ✅ | - |
| Estrutura pode divergir por model | - | ✅ |
| FK constraint é crítica (integridade) | - | ✅ |
| Precisa adicionar a novos models facilmente | ✅ | - |
| Alto volume (> 1M registros) com queries complexas | - | ✅ |
| Entidade é auxiliar/secundária | ✅ | - |
| Entidade é core do domínio | - | ✅ |
| Queries fazem JOIN frequente com a entidade | - | ✅ |
| Usado apenas para exibição/listagem | ✅ | - |

### Padrão Morph - Quando Usar

**Candidatos típicos:**
- Endereços (`addresses`)
- Contatos (`contacts`)
- Notas/Comentários (`notes`, `comments`)
- Anexos/Arquivos (`attachments`)
- Tags (`taggables`)
- Metadados (`meta`)
- Atividades/Log (`activities`)

**Estrutura:**

```php
// Migration
Schema::create('addresses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuidMorphs('addressable');               // addressable_type + addressable_id + índice
    $table->string('type', 20)->default('main');     // main, billing, shipping
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
```

```php
// Model Address
final class Address extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}

// Trait HasAddresses
trait HasAddresses
{
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function defaultAddress(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('is_default', true);
    }

    public function addressByType(string $type): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable')
            ->where('type', $type);
    }
}

// Uso no Model
final class Customer extends Model
{
    use HasAddresses;
}

final class Supplier extends Model
{
    use HasAddresses;
}
```

**Contatos (mesma estratégia):**

```php
Schema::create('contacts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuidMorphs('contactable');
    $table->string('type', 20)->default('main');     // main, billing, technical, support
    $table->string('name');
    $table->string('email')->nullable();
    $table->string('phone', 20)->nullable();
    $table->string('mobile', 20)->nullable();
    $table->string('position', 100)->nullable();      // cargo/função
    $table->string('department', 100)->nullable();
    $table->boolean('is_default')->default(false);
    $table->timestamps();
    $table->softDeletes();

    $table->index(['contactable_type', 'contactable_id', 'type']);
});
```

### Padrão Dedicado - Quando Usar

**Candidatos típicos:**
- Itens de pedido (estrutura diverge entre pedido de compra vs venda)
- Histórico de preço (precisa de FK constraint + queries pesadas)
- Movimentações financeiras (integridade é crítica)

**Estrutura:**

```php
// Tabelas separadas quando estrutura diverge
Schema::create('customer_addresses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
    // campos de endereço + campos específicos de customer
    $table->boolean('is_delivery_point')->default(false); // campo específico
    $table->timestamps();
});

Schema::create('supplier_addresses', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('supplier_id')->constrained()->cascadeOnDelete();
    // campos de endereço + campos específicos de supplier
    $table->string('dock_info')->nullable(); // campo específico
    $table->timestamps();
});
```

### Morph - Cuidados Obrigatórios

1. **Índice composto** é obrigatório:
   ```php
   $table->uuidMorphs('addressable'); // já cria índice
   // OU manualmente:
   $table->index(['addressable_type', 'addressable_id']);
   ```

2. **Sem FK constraint nativa** - compensar com:
   - Observer ou Event para limpar registros órfãos
   - Job periódico de limpeza (se necessário)
   - Soft deletes para não perder dados

3. **Não usar morph para entidades core** do domínio
   - Se a entidade tem regras de negócio próprias, não é candidata a morph

4. **Morph type curto** via `Relation::enforceMorphMap()`:
   ```php
   // AppServiceProvider::boot()
   Relation::enforceMorphMap([
       'customer' => Customer::class,
       'supplier' => Supplier::class,
       'order' => Order::class,
   ]);
   ```
   Isso evita armazenar `App\Models\Customer` no banco (frágil a refactoring).

---

## 4. Padrões de Tabelas Auxiliares

### Tabela Pivot (ManyToMany)

```php
// Nome: model1_model2 (ordem alfabética, singular)
Schema::create('order_product', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('quantity')->default(1);
    $table->decimal('unit_price', 10, 2);
    $table->timestamps();

    $table->unique(['order_id', 'product_id']);
});
```

### Tabela de Histórico/Log

```php
// Nome: model_history ou model_logs
Schema::create('order_status_history', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('from_status', 20)->nullable();
    $table->string('to_status', 20);
    $table->text('reason')->nullable();
    $table->timestamp('created_at');

    $table->index(['order_id', 'created_at']);
});
```

### Tabela de Configurações (Key-Value)

```php
// Evite key-value genérico. Prefira colunas tipadas.
// Se inevitável:
Schema::create('settings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('group', 50)->index();     // general, mail, payment
    $table->string('key', 100);
    $table->text('value')->nullable();
    $table->string('type', 20)->default('string'); // string, integer, boolean, json
    $table->timestamps();

    $table->unique(['group', 'key']);
});
```

---

## 5. Índices Obrigatórios

### Regra: sempre indexar

| Campo | Índice |
|-------|--------|
| FK (`*_id`) | Automático via `constrained()` |
| `status`, `type` | `->index()` |
| `is_active`, `is_default` | `->index()` |
| `slug`, `code` | `->unique()` |
| `email` (busca) | `->index()` |
| `document` (CPF/CNPJ) | `->index()` |
| `created_at` (ordenação) | Já indexado por timestamps |
| `sort_order` | `->index()` |
| `deleted_at` | Já indexado por `softDeletes()` |
| morph (`*able_type` + `*able_id`) | Usar `uuidMorphs()` |

### Índices Compostos Frequentes

```php
// Listagem filtrada por status
$table->index(['status', 'created_at']);

// Busca dentro de contexto
$table->index(['tenant_id', 'status']);
$table->index(['customer_id', 'status', 'created_at']);

// Morph + tipo
$table->index(['addressable_type', 'addressable_id', 'type']);
```

---

## 6. Checklist de Nova Tabela

Antes de criar qualquer migration, verifique:

- [ ] Tabela está na 3NF (ou desnormalização documentada)
- [ ] PK é UUID (`$table->uuid('id')->primary()`)
- [ ] Campos seguem convenção de nomes (is_, _at, _by, sort_order)
- [ ] Booleanos com prefixo `is_` ou `has_`
- [ ] Valores monetários com `decimal(10, 2)`
- [ ] Enums como `string` (não `enum`)
- [ ] Foreign keys com `constrained()` e `cascadeOnDelete/nullOnDelete`
- [ ] Índices em campos de busca, filtro e ordenação
- [ ] Timestamps presentes
- [ ] Soft deletes se aplicável
- [ ] Morph map configurado se usar morphTo
- [ ] Comentário se houver desnormalização intencional
