# Skill: Custom Livewire v4 Components

## Quando Usar

Use este skill quando precisar:
- Criar componentes Livewire customizados para uso em Filament (Resources, Pages, Widgets)
- Criar componentes standalone (modais, charts, datatables, dashboards)
- Usar Islands para performance em telas complexas
- Integrar JavaScript/Alpine.js com Livewire
- Criar Form Objects para formularios complexos
- Implementar drag-and-drop, lazy loading, streaming

> **OBRIGATORIO:** Leia `.ai/docs/livewire.md` para mudancas criticas do v4.

---

## IMPORTANTE: Antes de Implementar

1. **Leia** `.ai/docs/livewire.md` para breaking changes v3→v4
2. **SEMPRE usar componentes Filament** — nunca HTML puro para forms/inputs/botoes
3. **Verifique** componentes existentes em `app/Livewire/` para manter padrao
4. **Use** `search-docs` para sintaxe atual do Livewire v4
5. **Decida** o tipo: Class-based (Filament integration) ou SFC (standalone)
6. **Nunca** usar `@entangle` (deprecado) — use `$wire` diretamente

### Regra de Ouro: Componentes Filament SEMPRE

Todo componente Livewire customizado DEVE usar componentes nativos do Filament:

| Em vez de... | Usar... |
|-------------|---------|
| `<input type="text">` | `TextInput::make()` via `InteractsWithForms` |
| `<select>` | `Select::make()` via `InteractsWithForms` |
| `<textarea>` | `Textarea::make()` via `InteractsWithForms` |
| `<input type="checkbox">` | `Toggle::make()` ou `Checkbox::make()` |
| `<input type="file">` | `FileUpload::make()` via `InteractsWithForms` |
| `<button>` | `<x-filament::button>` |
| `<table>` manual | `InteractsWithTable` + `Table` schema |
| Modal HTML | `Filament\Actions\Action` com `->modal()` |
| Toast/Alert | `Filament\Notifications\Notification` |
| Loading spinner | `<x-filament::loading-indicator>` |
| Badge | `<x-filament::badge>` |
| Icon | `<x-filament::icon>` |

**Se o componente tem campos de entrada → implementar `HasForms` + `InteractsWithForms`.**

---

## Template: Componente Class-Based (Padrao para Filament)

### Componente Base

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class {Nome}Component extends Component
{
    public string $title = '';

    public function mount(string $title = ''): void
    {
        $this->title = $title;
    }

    #[Computed]
    public function data(): array
    {
        // Propriedade computada — cached durante o render
        return [];
    }

    #[On('refresh-{nome}')]
    public function refresh(): void
    {
        // Responde a eventos de outros componentes
        unset($this->data); // Limpa cache do computed
    }

    public function render()
    {
        return view('livewire.components.{nome}-component');
    }
}
```

### View (resources/views/livewire/components/{nome}-component.blade.php)

```blade
<div>
    <h3>{{ __('components.{nome}.title') }}</h3>

    <div>
        {{-- Conteudo do componente --}}
        @foreach ($this->data as $item)
            <div wire:key="{{ $item['id'] }}">
                {{ $item['name'] }}
            </div>
        @endforeach
    </div>
</div>
```

---

## Template: Componente com Filament Forms

Use quando precisar de um formulario Filament dentro de um componente Livewire customizado.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\{Nome};
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Livewire\Component;

final class {Nome}FormComponent extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label(__('components.{nome}.fields.name'))
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->label(__('components.{nome}.fields.type'))
                    ->options([
                        'option_a' => __('components.{nome}.options.option_a'),
                        'option_b' => __('components.{nome}.options.option_b'),
                    ])
                    ->required(),

                Toggle::make('is_active')
                    ->label(__('common.fields.is_active'))
                    ->default(true),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        {Nome}::create($data);

        Notification::make()
            ->title(__('components.{nome}.messages.created'))
            ->success()
            ->send();

        $this->form->fill();
        $this->dispatch('refresh-{nome}-list');
    }

    public function render()
    {
        return view('livewire.components.{nome}-form-component');
    }
}
```

### View

```blade
<div>
    <form wire:submit="submit">
        {{ $this->form }}

        <div class="mt-4 flex justify-end">
            <x-filament::button type="submit">
                {{ __('common.actions.save') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</div>
```

---

## Template: Componente com Filament Table

Use quando precisar de uma table Filament dentro de um componente Livewire customizado.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\{Nome};
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

final class {Nome}TableComponent extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query({Nome}::query())
            ->columns([
                TextColumn::make('name')
                    ->label(__('components.{nome}.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('common.fields.status'))
                    ->badge(),

                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public function render()
    {
        return view('livewire.components.{nome}-table-component');
    }
}
```

### View

```blade
<div>
    {{ $this->table }}
</div>
```

---

## Template: Widget para Dashboard/Resource

Use para criar widgets de stats, charts ou conteudo customizado para Filament.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\{Nome}Resource\Widgets;

use App\Models\{Nome};
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Computed;

final class {Nome}StatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        return [
            Stat::make(
                label: __('widgets.{nome}.total'),
                value: {Nome}::count()
            )
                ->description(__('widgets.{nome}.total_description'))
                ->icon('heroicon-o-cube'),

            Stat::make(
                label: __('widgets.{nome}.active'),
                value: {Nome}::where('is_active', true)->count()
            )
                ->color('success')
                ->icon('heroicon-o-check-circle'),

            Stat::make(
                label: __('widgets.{nome}.pending'),
                value: {Nome}::where('status', 'pending')->count()
            )
                ->color('warning')
                ->icon('heroicon-o-clock'),
        ];
    }
}
```

---

## Template: Chart Widget

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\{Nome}Resource\Widgets;

use App\Models\{Nome};
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Computed;

final class {Nome}ChartWidget extends ChartWidget
{
    protected static ?string $heading = null;
    protected static ?string $pollingInterval = '60s';
    protected static ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return __('widgets.{nome}.chart_heading');
    }

    protected function getData(): array
    {
        $data = {Nome}::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => __('widgets.{nome}.chart_label'),
                    'data' => $data->pluck('total')->toArray(),
                    'borderColor' => '#3B82F6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $data->pluck('date')
                ->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d/m'))
                ->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
```

---

## Template: Componente com Islands (Performance)

Use Islands quando o componente tem partes que devem atualizar independentemente.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class DashboardOverview extends Component
{
    #[Computed(persist: true, seconds: 300)]
    public function totalRevenue(): float
    {
        return Order::where('status', 'completed')->sum('total');
    }

    #[Computed(persist: true, seconds: 60)]
    public function recentOrders(): array
    {
        return Order::with('customer')
            ->latest()
            ->take(5)
            ->get()
            ->toArray();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return Order::where('status', 'pending')->count();
    }

    public function render()
    {
        return view('livewire.components.dashboard-overview');
    }
}
```

### View com Islands

```blade
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

    {{-- Island: Revenue - atualiza independentemente --}}
    @island(name: 'revenue', lazy: true)
        @placeholder
            <div class="animate-pulse h-24 bg-gray-200 rounded-lg"></div>
        @endplaceholder

        <div class="bg-white p-6 rounded-lg shadow">
            <h3 class="text-sm font-medium text-gray-500">
                {{ __('dashboard.revenue') }}
            </h3>
            <p class="text-2xl font-bold">
                R$ {{ number_format($this->totalRevenue, 2, ',', '.') }}
            </p>
        </div>
    @endisland

    {{-- Island: Pending count - com polling isolado --}}
    @island(name: 'pending')
        <div class="bg-white p-6 rounded-lg shadow" wire:poll.10s>
            <h3 class="text-sm font-medium text-gray-500">
                {{ __('dashboard.pending') }}
            </h3>
            <p class="text-2xl font-bold text-amber-600">
                {{ $this->pendingCount }}
            </p>
        </div>
    @endisland

    {{-- Island: Recent orders - lazy loaded --}}
    @island(name: 'recent-orders', defer: true)
        @placeholder
            <div class="animate-pulse h-48 bg-gray-200 rounded-lg"></div>
        @endplaceholder

        <div class="bg-white p-6 rounded-lg shadow col-span-full">
            <h3 class="text-sm font-medium text-gray-500 mb-4">
                {{ __('dashboard.recent_orders') }}
            </h3>
            @foreach ($this->recentOrders as $order)
                <div wire:key="order-{{ $order['id'] }}" class="flex justify-between py-2 border-b">
                    <span>{{ $order['customer']['name'] ?? '-' }}</span>
                    <span>R$ {{ number_format($order['total'], 2, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    @endisland

</div>
```

---

## Template: Componente Modal (via Filament Actions)

Preferir Filament Actions para modais — garante consistencia visual e dark mode.

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Livewire\Component;

final class {Nome}WithModal extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public function createAction(): Action
    {
        return Action::make('create')
            ->label(__('common.actions.create'))
            ->icon('heroicon-o-plus')
            ->form([
                TextInput::make('name')
                    ->label(__('components.{nome}.fields.name'))
                    ->required()
                    ->maxLength(255),

                Textarea::make('description')
                    ->label(__('components.{nome}.fields.description'))
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                {Nome}::create($data);

                Notification::make()
                    ->title(__('components.{nome}.messages.created'))
                    ->success()
                    ->send();
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('common.actions.delete'))
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading(__('components.{nome}.messages.confirm_delete'))
            ->action(function (array $arguments): void {
                {Nome}::find($arguments['id'])?->delete();

                Notification::make()
                    ->title(__('components.{nome}.messages.deleted'))
                    ->success()
                    ->send();
            });
    }

    public function render()
    {
        return view('livewire.components.{nome}-with-modal');
    }
}
```

### View

```blade
<div>
    {{-- Botao que abre modal de criacao (Filament Action) --}}
    {{ $this->createAction }}

    {{-- Lista com botao de delete por item --}}
    @foreach ($this->items as $item)
        <div wire:key="{{ $item->id }}" class="flex items-center justify-between py-2">
            <span>{{ $item->name }}</span>
            {{ $this->deleteAction(['id' => $item->id]) }}
        </div>
    @endforeach

    {{-- OBRIGATORIO: renderiza os modais do Filament --}}
    <x-filament-actions::modals />
</div>
```

---

## Template: Componente com Drag and Drop

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\{Nome};
use Livewire\Component;

final class {Nome}Sortable extends Component
{
    /** @var array<int, array{id: string, name: string, sort_order: int}> */
    public array $items = [];

    public function mount(): void
    {
        $this->items = {Nome}::query()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order'])
            ->toArray();
    }

    public function reorder(array $order): void
    {
        foreach ($order as $position => $id) {
            {Nome}::where('id', $id)->update(['sort_order' => $position]);
        }

        $this->mount(); // Refresh
    }

    public function render()
    {
        return view('livewire.components.{nome}-sortable');
    }
}
```

### View

```blade
<div>
    <ul wire:sort="reorder" class="space-y-2">
        @foreach ($items as $item)
            <li wire:key="{{ $item['id'] }}"
                wire:sort:item="{{ $item['id'] }}"
                class="flex items-center gap-3 p-3 bg-white rounded-lg shadow-sm border">

                <span wire:sort:handle class="cursor-grab text-gray-400 hover:text-gray-600">
                    <x-heroicon-o-bars-3 class="w-5 h-5" />
                </span>

                <span class="flex-1">{{ $item['name'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
```

---

## Template: Form Object

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\{Nome};
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Form;

final class {Nome}Form extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('boolean')]
    public bool $is_active = true;

    public ?{Nome} $record = null;

    public function setRecord({Nome} $record): void
    {
        $this->record = $record;
        $this->name = $record->name;
        $this->description = $record->description ?? '';
        $this->is_active = $record->is_active;
    }

    public function store(): {Nome}
    {
        $this->validate();

        $record = {Nome}::create($this->only(['name', 'description', 'is_active']));
        $this->reset();

        return $record;
    }

    public function update(): {Nome}
    {
        $this->validate();

        $this->record->update($this->only(['name', 'description', 'is_active']));

        return $this->record->fresh();
    }
}
```

---

## Template: Componente com Alpine.js

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use Livewire\Attributes\Json;
use Livewire\Component;

final class SearchableSelect extends Component
{
    public string $search = '';
    public ?string $selected = null;

    #[Json]
    public function searchItems(string $query): array
    {
        return Item::where('name', 'like', "%{$query}%")
            ->take(10)
            ->get(['id', 'name'])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.components.searchable-select');
    }
}
```

### View com Alpine (usando componentes Filament)

```blade
<div x-data="{
    open: false,
    search: '',
    results: [],
    async doSearch() {
        if (this.search.length < 2) { this.results = []; return; }
        this.results = await $wire.searchItems(this.search);
        this.open = this.results.length > 0;
    },
    select(item) {
        $wire.selected = item.id;
        this.search = item.name;
        this.open = false;
    }
}">
    {{-- Usar componente Filament para input --}}
    <x-filament::input.wrapper>
        <x-filament::input
            type="text"
            x-model="search"
            x-on:input.debounce.300ms="doSearch"
            x-on:focus="open = results.length > 0"
            x-on:click.away="open = false"
            :placeholder="__('common.actions.search')"
        />
    </x-filament::input.wrapper>

    {{-- Dropdown de resultados --}}
    <div x-show="open" x-transition class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-60 overflow-y-auto">
        <template x-for="item in results" :key="item.id">
            <button x-on:click="select(item)"
                    x-text="item.name"
                    class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
            </button>
        </template>
    </div>
</div>
```

> **Nota:** Para Alpine.js puro com dropdowns, nao existe componente Filament equivalente.
> Use `<x-filament::input>` para o campo e Tailwind para o dropdown. Sempre inclua classes `dark:` para dark mode.

---

## Template: Embeddable em Filament Schema

Para embutir um componente Livewire dentro de um Filament Resource/Page:

```php
// No FormSchema ou InfolistSchema do Resource:
use Filament\Schemas\Components\Livewire;

public static function schema(): array
{
    return [
        // ... outros campos ...

        Livewire::make(\App\Livewire\Components\{Nome}Component::class)
            ->data([
                'recordId' => fn ($record) => $record?->id,
            ])
            ->key('{nome}-component')
            ->lazy()
            ->columnSpanFull(),
    ];
}
```

---

## Template: Testes com Pest

```php
<?php

use App\Livewire\Components\{Nome}Component;
use App\Models\{Nome};
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    actingAs(User::factory()->create());
});

describe('{Nome}Component', function () {
    it('renders successfully', function () {
        Livewire::test({Nome}Component::class)
            ->assertSuccessful();
    });

    it('renders with initial data', function () {
        $record = {Nome}::factory()->create();

        Livewire::test({Nome}Component::class, ['recordId' => $record->id])
            ->assertSee($record->name);
    });

    it('updates property', function () {
        Livewire::test({Nome}Component::class)
            ->set('title', 'Novo Titulo')
            ->assertSet('title', 'Novo Titulo');
    });

    it('calls method', function () {
        $record = {Nome}::factory()->create();

        Livewire::test({Nome}Component::class)
            ->call('save')
            ->assertHasNoErrors();
    });

    it('validates required fields', function () {
        Livewire::test({Nome}Component::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    });

    it('dispatches event', function () {
        Livewire::test({Nome}Component::class)
            ->call('save')
            ->assertDispatched('refresh-{nome}-list');
    });

    it('listens to event', function () {
        Livewire::test({Nome}Component::class)
            ->dispatch('refresh-{nome}')
            ->assertSuccessful();
    });
});

describe('{Nome}Component with Form', function () {
    it('fills form and submits', function () {
        Livewire::test({Nome}Component::class)
            ->fillForm([
                'name' => 'Teste',
                'is_active' => true,
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        expect({Nome}::where('name', 'Teste')->exists())->toBeTrue();
    });

    it('validates form', function () {
        Livewire::test({Nome}Component::class)
            ->fillForm([
                'name' => '',
            ])
            ->call('submit')
            ->assertHasFormErrors(['name' => 'required']);
    });
});

describe('{Nome}Component lazy loading', function () {
    it('renders without lazy loading', function () {
        Livewire::withoutLazyLoading()
            ->test({Nome}Component::class)
            ->assertSee('Expected Content');
    });
});
```

---

## Embutir em Filament Page Customizada

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

final class CustomDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $title = null;

    protected static string $view = 'filament.pages.custom-dashboard';

    public function getTitle(): string
    {
        return __('pages.dashboard.title');
    }
}
```

### View (resources/views/filament/pages/custom-dashboard.blade.php)

```blade
<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @livewire('components.revenue-chart')
        @livewire('components.recent-orders', ['limit' => 5])
    </div>

    <div class="mt-6">
        @livewire('components.activity-feed', lazy: true)
    </div>
</x-filament-panels::page>
```

---

## Checklist de Implementacao

### Para cada componente customizado:
- [ ] **UI usa SOMENTE componentes Filament (nunca `<input>`, `<select>`, `<button>` puro)**
- [ ] **`InteractsWithForms` implementado se tem campos de entrada**
- [ ] **`InteractsWithActions` implementado se tem modais/confirmacoes**
- [ ] Classe em `app/Livewire/{Tipo}/{Nome}.php`
- [ ] View em `resources/views/livewire/{tipo}/{nome}.blade.php`
- [ ] `declare(strict_types=1)` e `final class`
- [ ] Propriedades tipadas
- [ ] Metodos com return types
- [ ] Labels usando `__()` (nunca hardcoded)
- [ ] Botoes usando `<x-filament::button>` (nunca `<button>` puro)
- [ ] Notificacoes usando `Filament\Notifications\Notification`
- [ ] `<x-filament-actions::modals />` incluido se usa Actions
- [ ] `#[Computed]` para dados derivados
- [ ] `#[Lazy]` ou `#[Defer]` se pesado
- [ ] Islands onde performance importa
- [ ] Testes em `tests/Feature/Livewire/{Nome}Test.php`
- [ ] Tags de componente FECHADAS (`<livewire:nome />`)
- [ ] Sem `@entangle` (usar `$wire` direto)

### Decisao de tipo:

| Cenario | Tipo Recomendado |
|---------|-----------------|
| Dentro de Filament Resource/Page | Class-based + `Livewire::make()` |
| Widget de dashboard | Extend `StatsOverviewWidget` ou `ChartWidget` |
| Formulario independente | Class-based + `InteractsWithForms` |
| Tabela independente | Class-based + `InteractsWithTable` |
| Modal/Confirmacao | Class-based + `InteractsWithActions` |
| Componente UI simples | Class-based + `<x-filament::*>` Blade components |
| Drag-and-drop | Class-based + `wire:sort` |
| Full-page standalone | Class-based + `InteractsWithForms` |
