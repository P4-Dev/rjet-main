# Soft Deletes e Ciclo de Vida de Dados

> **Regras para exclusao logica, restauracao, exclusao permanente, pruning e integracao com Filament v5.**

---

## 1. Regra Fundamental

**SoftDeletes e o padrao para toda entidade de dominio.** Hard delete e reservado exclusivamente para dados transitorios.

```php
// CORRETO - entidade de dominio
final class Order extends Model
{
    use HasFactory, HasUuid, SoftDeletes;
}

// CORRETO - dado transitorio (sem SoftDeletes)
final class PasswordResetToken extends Model
{
    // Hard delete: dado temporario, sem valor de negocio
}
```

**Justificativa:** dados de negocio deletados podem ser necessarios para auditoria, compliance, relatorios historicos e recuperacao de erros operacionais.

---

## 2. Quando Usar vs Nao Usar

### Matriz de Decisao

| Entidade | SoftDeletes? | Motivo |
|----------|:------------:|--------|
| Clientes / Usuarios | Sim | Auditoria, historico de relacionamentos |
| Pedidos / Vendas | Sim | Compliance fiscal, relatorios |
| Produtos / Servicos | Sim | Historico de precos, pedidos antigos referenciam |
| Categorias / Tags | Sim | Integridade referencial |
| Faturas / Pagamentos | Sim | Obrigacao fiscal/contabil |
| Documentos / Anexos | Sim | Rastreabilidade |
| Notas / Comentarios | Sim | Auditoria |
| Logs de atividade | **Nao** | Volume alto, sem valor individual |
| Sessoes | **Nao** | Dado efemero |
| Tokens de reset/verificacao | **Nao** | Dado temporario |
| Cache / Jobs processados | **Nao** | Dado transitorio |
| Tabelas pivot simples | **Nao** | Gerenciada via `sync()`/`detach()` |
| Filas (`jobs`, `failed_jobs`) | **Nao** | Gerenciada pelo framework |
| Notificacoes de banco | Depende | Se precisa de historico, sim |

### Regra Pratica

```
Pergunta: "Se um usuario deletar isso por engano, precisamos recuperar?"
  Sim → SoftDeletes
  Nao → Hard Delete
```

---

## 3. Configuracao

### Migration

```php
Schema::create('customers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('email')->unique();
    $table->boolean('is_active')->default(true)->index();
    $table->timestamps();
    $table->softDeletes(); // adiciona deleted_at (nullable timestamp) + indice
});
```

**Nota:** `$table->softDeletes()` ja cria indice automaticamente no campo `deleted_at`.

### Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
```

### Migration para Adicionar SoftDeletes a Tabela Existente

```php
Schema::table('products', function (Blueprint $table) {
    $table->softDeletes();
});

// Rollback
Schema::table('products', function (Blueprint $table) {
    $table->dropSoftDeletes();
});
```

---

## 4. Querying

### Comportamento Padrao

Por padrao, registros soft-deleted sao **excluidos automaticamente** de todas as queries.

```php
// Retorna apenas registros NAO deletados
Customer::all();
Customer::where('is_active', true)->get();

// Incluir registros deletados
Customer::withTrashed()->get();
Customer::withTrashed()->where('email', $email)->first();

// APENAS registros deletados
Customer::onlyTrashed()->get();
Customer::onlyTrashed()->where('deleted_at', '<', now()->subMonths(6))->get();

// Verificar se um registro esta soft-deleted
if ($customer->trashed()) {
    // registro esta na lixeira
}
```

### Impacto em Relacionamentos

```php
// Relacionamentos tambem excluem soft-deleted por padrao
$order->customer; // retorna null se customer estiver soft-deleted

// Para incluir soft-deleted em relacionamentos
public function customer(): BelongsTo
{
    return $this->belongsTo(Customer::class)->withTrashed();
}
```

### Impacto em Unique Constraints

**Problema:** um registro soft-deleted ocupa a constraint unique no banco. Se um cliente com email `foo@bar.com` for soft-deleted, nao sera possivel criar outro com o mesmo email.

```php
// Isso FALHA se existir um soft-deleted com mesmo email
Customer::create(['email' => 'foo@bar.com']); // SQLSTATE: duplicate entry
```

Veja a secao **10. Unique Constraints** para solucoes.

---

## 5. Restore e ForceDelete

### Restore

```php
// Restaurar um unico registro
$customer = Customer::onlyTrashed()->find($id);
$customer->restore();

// Restaurar em massa
Customer::onlyTrashed()
    ->where('deleted_at', '>', now()->subDays(30))
    ->restore();
```

### Force Delete (exclusao permanente)

```php
// Deletar permanentemente
$customer = Customer::withTrashed()->find($id);
$customer->forceDelete();

// Force delete em massa
Customer::onlyTrashed()
    ->where('deleted_at', '<', now()->subYear())
    ->forceDelete();
```

### Eventos Disponiveis

| Evento | Quando | Uso Comum |
|--------|--------|-----------|
| `restoring` | Antes de restaurar | Validar se restauracao e permitida |
| `restored` | Apos restaurar | Recalcular agregacoes, notificar |
| `forceDeleting` | Antes de excluir permanentemente | Limpar arquivos, registros dependentes |
| `forceDeleted` | Apos exclusao permanente | Log de auditoria final |

```php
// No Observer
final class CustomerObserver
{
    public function restoring(Customer $customer): bool
    {
        // Impedir restauracao se email ja esta em uso por outro registro ativo
        if (Customer::where('email', $customer->email)->exists()) {
            return false; // cancela a restauracao
        }

        return true;
    }

    public function restored(Customer $customer): void
    {
        logger()->info('Customer restored', ['customer_id' => $customer->id]);
    }

    public function forceDeleting(Customer $customer): void
    {
        // Limpar arquivos associados antes de exclusao permanente
        Storage::deleteDirectory("customers/{$customer->id}");
    }
}
```

---

## 6. Relationships e Cascade

### O Problema: `cascadeOnDelete` NAO Funciona com SoftDeletes

**CRITICO:** quando o parent usa SoftDeletes, `cascadeOnDelete()` na FK **nao e acionado** porque o registro nao e realmente deletado do banco.

```php
// Migration do child
$table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();

// Isso NAO deleta os orders quando customer e soft-deleted!
$customer->delete(); // Apenas seta deleted_at no customer
// Os orders permanecem intactos e orfaos de um customer "deletado"
```

### Solucao 1: Observer (Recomendada)

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;

final class CustomerObserver
{
    /**
     * Cascade soft delete para relacionamentos dependentes.
     */
    public function deleted(Customer $customer): void
    {
        $customer->orders()->delete(); // soft delete em cascata
        $customer->addresses()->delete();
    }

    /**
     * Cascade restore para relacionamentos dependentes.
     */
    public function restored(Customer $customer): void
    {
        $customer->orders()->onlyTrashed()
            ->where('deleted_at', '>=', $customer->updated_at)
            ->restore();

        $customer->addresses()->onlyTrashed()
            ->where('deleted_at', '>=', $customer->updated_at)
            ->restore();
    }

    /**
     * Cascade force delete para relacionamentos dependentes.
     */
    public function forceDeleted(Customer $customer): void
    {
        $customer->orders()->forceDelete();
        $customer->addresses()->forceDelete();
    }
}
```

### Solucao 2: Trait Reutilizavel

```php
<?php

declare(strict_types=1);

namespace App\Concerns;

trait CascadesSoftDeletes
{
    /**
     * Relacionamentos que devem sofrer cascade de soft delete.
     * Defina no Model: protected array $cascadeDeletes = ['orders', 'addresses'];
     *
     * @return array<string>
     */
    abstract protected function cascadeDeleteRelationships(): array;

    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleted(function (self $model) {
            foreach ($model->cascadeDeleteRelationships() as $relationship) {
                $model->{$relationship}()->delete();
            }
        });

        static::restored(function (self $model) {
            foreach ($model->cascadeDeleteRelationships() as $relationship) {
                $model->{$relationship}()->onlyTrashed()
                    ->where('deleted_at', '>=', $model->updated_at)
                    ->restore();
            }
        });

        static::forceDeleted(function (self $model) {
            foreach ($model->cascadeDeleteRelationships() as $relationship) {
                $model->{$relationship}()->forceDelete();
            }
        });
    }
}
```

```php
// Uso no Model
final class Customer extends Model
{
    use HasFactory, SoftDeletes, CascadesSoftDeletes;

    protected function cascadeDeleteRelationships(): array
    {
        return ['orders', 'addresses', 'contacts'];
    }
}
```

### Solucao 3: `onDelete('restrict')` para Impedir Exclusao

```php
// Quando NAO quiser cascade, mas sim impedir exclusao
$table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
```

```php
// No Service, verificar antes de deletar
public function deleteCustomer(Customer $customer): void
{
    if ($customer->orders()->exists()) {
        throw new BusinessException(
            __('customers.cannot_delete_with_orders'),
        );
    }

    $customer->delete();
}
```

---

## 7. Pruning (Ciclo de Vida de Dados)

### Conceito

Pruning remove permanentemente registros soft-deleted apos um periodo de retencao. Isso evita que a tabela cresca indefinidamente com registros na lixeira.

### Configuracao no Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Customer extends Model
{
    use HasFactory, MassPrunable, SoftDeletes;

    /**
     * Registros soft-deleted ha mais de 90 dias serao removidos permanentemente.
     */
    public function prunable(): Builder
    {
        return static::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(90));
    }
}
```

**`MassPrunable` vs `Prunable`:**

| Trait | Comportamento | Quando Usar |
|-------|---------------|-------------|
| `MassPrunable` | `DELETE` em massa (sem eventos) | Volume alto, sem cleanup |
| `Prunable` | Deleta um a um (dispara eventos) | Precisa limpar arquivos/relacionamentos |

```php
// Se precisar limpar arquivos ao prunar, use Prunable
use Illuminate\Database\Eloquent\Prunable;

final class Document extends Model
{
    use HasFactory, Prunable, SoftDeletes;

    public function prunable(): Builder
    {
        return static::onlyTrashed()
            ->where('deleted_at', '<', now()->subMonths(6));
    }

    /**
     * Executado antes de cada registro ser deletado permanentemente.
     */
    protected function pruning(): void
    {
        Storage::deleteDirectory("documents/{$this->id}");
    }
}
```

### Agendar Pruning

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();

// Ou com Models especificos
Schedule::command('model:prune', [
    '--model' => [Customer::class, Document::class],
])->daily();
```

### Execucao Manual

```bash
# Prunar todos os models com trait Prunable/MassPrunable
php artisan model:prune

# Prunar model especifico
php artisan model:prune --model="App\Models\Customer"

# Simular (sem deletar de verdade)
php artisan model:prune --pretend
```

### Politica de Retencao Sugerida

| Tipo de Dado | Retencao |
|--------------|----------|
| Usuarios/Clientes | 90 dias |
| Pedidos/Vendas | 365 dias (compliance fiscal) |
| Produtos | 180 dias |
| Documentos/Anexos | 180 dias |
| Categorias/Tags | 60 dias |
| Comentarios/Notas | 60 dias |

---

## 8. Integracao com Filament v5

### Filtro de Lixeira na Tabela

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\TrashedFilter;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    // No Resource, metodo table()
}
```

```php
// No Resource
use Filament\Tables\Filters\TrashedFilter;

public static function table(Table $table): Table
{
    return $table
        ->columns([
            // ...
        ])
        ->filters([
            TrashedFilter::make(),
        ]);
}
```

O `TrashedFilter` adiciona automaticamente um select com opcoes:
- **Sem registros excluidos** (padrao)
- **Com registros excluidos**
- **Apenas registros excluidos**

### Acoes de Restore e Force Delete

```php
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;

// Na pagina de edicao (EditRecord)
protected function getHeaderActions(): array
{
    return [
        DeleteAction::make(),
        RestoreAction::make(),
        ForceDeleteAction::make(),
    ];
}
```

### Acoes em Massa na Tabela

```php
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;

public static function table(Table $table): Table
{
    return $table
        ->columns([...])
        ->filters([
            TrashedFilter::make(),
        ])
        ->actions([
            // acoes por linha
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ])
        ->bulkActions([
            DeleteBulkAction::make(),
            RestoreBulkAction::make(),
            ForceDeleteBulkAction::make(),
        ]);
}
```

### Eloquent Query com SoftDeletes no Resource

```php
// Para que o Filament consiga exibir registros na lixeira,
// o Resource precisa incluir soft-deleted na query base
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->withTrashed();
}
```

**Sem isso, `RestoreAction` e `ForceDeleteAction` nao encontrarao registros na lixeira.**

---

## 9. Scopes Customizados

### Scope Ativo (combinando `is_active` + nao deletado)

```php
// No Model
use Illuminate\Database\Eloquent\Builder;

public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
    // SoftDeletes ja exclui deletados automaticamente
}

// Uso
Customer::active()->get(); // ativos E nao deletados
Customer::withTrashed()->active()->get(); // ativos, incluindo deletados
```

### Global Scope para Negocio

```php
// Se precisar de um scope global alem do SoftDeletes
// (cuidado: multiplos global scopes podem gerar confusao)

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class ActiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('is_active', true);
    }
}

// CUIDADO: global scopes alem de SoftDeletes devem ser usados
// com parcimonia. Prefira scopes locais (scopeActive) na maioria dos casos.
```

### Query Complexa com SoftDeletes

```php
// Relatorio: total de vendas incluindo clientes deletados
Order::query()
    ->whereHas('customer', function (Builder $query) {
        $query->withTrashed(); // incluir clientes deletados
    })
    ->selectRaw('SUM(total) as revenue')
    ->first();
```

---

## 10. Unique Constraints com SoftDeletes

### O Problema

```sql
-- No banco, existe: email = 'foo@bar.com' com deleted_at = '2026-01-15'
-- Tentar inserir: email = 'foo@bar.com' com deleted_at = NULL
-- RESULTADO: SQLSTATE duplicate entry!
```

### Solucao 1: Indice Parcial (PostgreSQL)

```php
// Migration - PostgreSQL suporta indice parcial nativo
Schema::table('customers', function (Blueprint $table) {
    $table->dropUnique(['email']);
});

DB::statement(
    'CREATE UNIQUE INDEX customers_email_unique ON customers (email) WHERE deleted_at IS NULL'
);
```

### Solucao 2: Validacao no Codigo (MySQL / Todos os bancos)

```php
// Na Form Request ou Rule customizada
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'email' => [
            'required',
            'email',
            Rule::unique('customers', 'email')
                ->whereNull('deleted_at')
                ->ignore($this->customer), // para edicao
        ],
    ];
}
```

### Solucao 3: Restaurar ou Limpar Antes de Criar

```php
// No Service
public function createCustomer(array $data): Customer
{
    // Verificar se existe soft-deleted com mesmo email
    $trashed = Customer::onlyTrashed()
        ->where('email', $data['email'])
        ->first();

    if ($trashed) {
        // Opcao A: restaurar o registro existente
        $trashed->restore();
        $trashed->update($data);

        return $trashed;

        // Opcao B: forcar exclusao do antigo antes de criar
        // $trashed->forceDelete();
    }

    return Customer::create($data);
}
```

### Solucao 4: Unique Composto com `deleted_at`

```php
// Migration - MySQL: unique composto incluindo deleted_at
// Nao ideal, mas funciona como paliativo
Schema::table('customers', function (Blueprint $table) {
    $table->dropUnique(['email']);
    $table->unique(['email', 'deleted_at']);
});
```

**Aviso:** esta solucao so permite uma exclusao por email. Se o mesmo email for soft-deleted duas vezes, falhara na segunda.

### Recomendacao

| Banco | Solucao Preferida |
|-------|-------------------|
| PostgreSQL | Indice parcial (`WHERE deleted_at IS NULL`) |
| MySQL | Validacao no codigo (`Rule::unique()->whereNull('deleted_at')`) |
| SQLite (testes) | Validacao no codigo |

---

## 11. Testes

### Testar Soft Delete

```php
<?php

use App\Models\Customer;

it('soft deletes a customer', function () {
    $customer = Customer::factory()->create();

    $customer->delete();

    expect($customer->trashed())->toBeTrue();
    assertSoftDeleted($customer);

    // Nao aparece em queries normais
    expect(Customer::find($customer->id))->toBeNull();

    // Aparece com withTrashed
    expect(Customer::withTrashed()->find($customer->id))->not->toBeNull();
});
```

### Testar Restore

```php
it('restores a soft-deleted customer', function () {
    $customer = Customer::factory()->create();
    $customer->delete();

    $customer->restore();

    expect($customer->trashed())->toBeFalse();
    expect(Customer::find($customer->id))->not->toBeNull();
});
```

### Testar Force Delete

```php
it('permanently deletes a customer with forceDelete', function () {
    $customer = Customer::factory()->create();
    $id = $customer->id;

    $customer->forceDelete();

    expect(Customer::withTrashed()->find($id))->toBeNull();
    $this->assertDatabaseMissing('customers', ['id' => $id]);
});
```

### Testar Cascade Soft Delete

```php
it('cascade soft deletes orders when customer is deleted', function () {
    $customer = Customer::factory()
        ->has(Order::factory()->count(3))
        ->create();

    $customer->delete();

    expect($customer->orders()->count())->toBe(0);
    expect($customer->orders()->onlyTrashed()->count())->toBe(3);
});

it('cascade restores orders when customer is restored', function () {
    $customer = Customer::factory()
        ->has(Order::factory()->count(3))
        ->create();

    $customer->delete();
    $customer->restore();

    expect($customer->orders()->count())->toBe(3);
});
```

### Testar Unique com SoftDeletes

```php
it('allows creating customer with email of a soft-deleted record', function () {
    $customer = Customer::factory()->create(['email' => 'test@example.com']);
    $customer->delete();

    // Deve ser possivel criar com mesmo email (via validacao customizada)
    $newCustomer = Customer::factory()->create(['email' => 'test@example.com']);

    expect($newCustomer->email)->toBe('test@example.com');
    expect(Customer::where('email', 'test@example.com')->count())->toBe(1);
});
```

### Testar Pruning

```php
use Illuminate\Support\Facades\Artisan;

it('prunes customers soft-deleted more than 90 days ago', function () {
    $recent = Customer::factory()->create();
    $recent->delete(); // deletado agora

    $old = Customer::factory()->create();
    $old->delete();
    $old->update(['deleted_at' => now()->subDays(91)]);

    Artisan::call('model:prune', [
        '--model' => [Customer::class],
    ]);

    // Registro recente permanece na lixeira
    expect(Customer::onlyTrashed()->find($recent->id))->not->toBeNull();

    // Registro antigo foi removido permanentemente
    expect(Customer::withTrashed()->find($old->id))->toBeNull();
});
```

### Testar Filament Resource com SoftDeletes

```php
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

it('can filter trashed records in table', function () {
    $this->actingAs(User::factory()->create());

    $active = Customer::factory()->create();
    $trashed = Customer::factory()->create();
    $trashed->delete();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active, $trashed]);
});

it('can restore a soft-deleted record', function () {
    $this->actingAs(User::factory()->create());

    $customer = Customer::factory()->create();
    $customer->delete();

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('restore');

    expect($customer->fresh()->trashed())->toBeFalse();
});
```

---

## 12. Checklist

- [ ] Entidades de dominio usam `SoftDeletes` (trait + migration)
- [ ] Dados transitorios (logs, sessoes, tokens) usam hard delete
- [ ] Migration inclui `$table->softDeletes()`
- [ ] Relacionamentos que referenciam soft-deleted usam `->withTrashed()` quando necessario
- [ ] Cascade manual configurado via Observer ou trait `CascadesSoftDeletes`
- [ ] **Nunca** confiar em `cascadeOnDelete()` da FK quando o parent usa SoftDeletes
- [ ] Unique constraints tratados (`Rule::unique()->whereNull('deleted_at')` ou indice parcial)
- [ ] `MassPrunable` ou `Prunable` configurado com politica de retencao
- [ ] `model:prune` agendado em `routes/console.php`
- [ ] Filament Resource com `TrashedFilter`, `RestoreAction`, `ForceDeleteAction`
- [ ] Filament Resource sobrescreve `getEloquentQuery()` com `->withTrashed()`
- [ ] Testes cobrem: soft delete, restore, force delete, cascade, unique, pruning
- [ ] Eventos de soft delete (`restoring`, `restored`, `forceDeleting`) tratados se necessario
