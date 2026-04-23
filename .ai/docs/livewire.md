# Livewire v4 - Guideline

> **Referencia completa para componentes Livewire v4 no projeto.**
> O projeto usa Livewire v4 + Filament v5. Este guideline cobre as mudancas
> criticas do v4, novos recursos e padroes de uso.

---

## 1. Mudancas Criticas v3 → v4

### Tags de Componente DEVEM ser fechadas

```blade
{{-- v3 - funcionava --}}
<livewire:component-name>

{{-- v4 - OBRIGATORIO fechar --}}
<livewire:component-name />
```

### wire:model — Comportamento Alterado

Em v4, `wire:model` NAO escuta eventos de elementos filhos (bubbling). Use `.deep` se necessario:

```blade
{{-- v3 --}}
<div wire:model="value"><input type="text"></div>

{{-- v4 - precisa de .deep --}}
<div wire:model.deep="value"><input type="text"></div>
```

**Modificadores `.blur` e `.change` mudaram (v4.1):**

Agora controlam QUANDO o estado client-side sincroniza, nao apenas timing de rede.
Para manter comportamento v3, adicione `.live` antes:

```blade
{{-- v3 --}}
<input wire:model.blur="title">

{{-- v4 equivalente ao v3 --}}
<input wire:model.live.blur="title">
```

**Ordem dos modificadores de wire:model:**
1. Antes de `.live`: controla sync client-side (`.blur`, `.change`, `.enter`)
2. `.live`: ativa sync de rede em tempo real
3. Depois de `.live`: controla timing de rede (`.debounce.500ms`, `.throttle.1000ms`)

### wire:transition — Usa View Transitions API

Nao usa mais Alpine transitions. Modificadores `.opacity`, `.scale`, `.duration` removidos:

```blade
{{-- v4 - usa View Transitions API nativa --}}
@if ($showAlert)
    <div wire:transition>Alerta!</div>
@endif
```

### Configuracoes Renomeadas

| v3 | v4 |
|----|----|
| `layout` | `component_layout` |
| `lazy_placeholder` | `component_placeholder` |
| `smart_wire_keys` (false) | `smart_wire_keys` (true) |

### Streaming — Assinatura Reordenada

```php
// v3
$this->stream($content, $target);

// v4
$this->stream($content, el: '#container');
```

### @entangle Deprecado

```blade
{{-- DEPRECADO --}}
<div x-data="{ value: @entangle('property') }">

{{-- v4 - usar $wire diretamente --}}
<div x-data="{ value: $wire.property }">
```

### Polling e Live Nao-Bloqueantes

- `wire:poll` NAO bloqueia mais outras requests
- `wire:model.live` requests rodam em PARALELO

---

## 2. Islands — Renderizacao Parcial

Islands criam regioes isoladas que atualizam INDEPENDENTEMENTE sem re-renderizar o componente pai. E o recurso mais importante do v4 para performance.

### Sintaxe Basica

```blade
@island
    <div>
        Revenue: {{ $this->revenue }}
        <button wire:click="$refresh">Refresh</button>
    </div>
@endisland
```

### Lazy Loading

```blade
{{-- Carrega quando entra no viewport --}}
@island(lazy: true)
    <div>Revenue: {{ $this->revenue }}</div>
@endisland

{{-- Carrega imediatamente apos render da pagina --}}
@island(defer: true)
    <div>Revenue: {{ $this->revenue }}</div>
@endisland
```

### Placeholder Customizado

```blade
@island(lazy: true)
    @placeholder
        <div class="animate-pulse h-32 bg-gray-200 rounded"></div>
    @endplaceholder
    <div>Revenue: {{ $this->revenue }}</div>
@endisland
```

### Islands Nomeadas

```blade
@island(name: 'revenue')
    <div>Revenue: {{ $this->revenue }}</div>
@endisland

{{-- Botao externo que atualiza apenas a island --}}
<button wire:click="$refresh" wire:island="revenue">Atualizar</button>
```

### Append/Prepend (Infinite Scroll)

```blade
@island(name: 'feed')
    @foreach ($this->activities as $activity)
        <x-activity-item :activity="$activity" />
    @endforeach
@endisland

<button wire:click="loadMore" wire:island.append="feed">Carregar mais</button>
```

### Islands Aninhadas

```blade
@island(name: 'revenue')
    <div>Total: {{ $this->revenue }}
        @island(name: 'breakdown')
            Mensal: {{ $this->monthlyBreakdown }}
        @endisland
    </div>
@endisland
```

### Parametros Avancados

| Parametro | Descricao |
|-----------|-----------|
| `lazy: true` | Carrega ao entrar no viewport |
| `defer: true` | Carrega apos render da pagina |
| `always: true` | Forca update junto com o pai |
| `skip: true` | Pula render inicial ate trigger explicito |
| `name: 'x'` | Nomeia a island para targeting externo |

### Restricoes de Islands

- **NAO** usar dentro de `@foreach`, `@if` ou outras estruturas de controle
- Coloque loops DENTRO da island, nao em volta
- Islands acessam propriedades/metodos via `$this->`, NAO variaveis de template externas
- Requests concorrentes em islands podem causar estado divergente (last response wins)

### Quando Usar

| Usar Islands | NAO usar Islands |
|--------------|------------------|
| Computacoes caras bloqueando page load | Conteudo estatico |
| Regioes independentes com interacoes isoladas | UI fortemente acoplada |
| Updates em tempo real afetando partes especificas | Componentes simples e rapidos |
| Gargalos de performance em componentes grandes | Regioes que dependem do estado do pai |

---

## 3. Novos Recursos v4

### Single-File Components (SFC) — Padrao

```php
<?php // resources/views/components/counter.blade.php
use Livewire\Component;

new class extends Component {
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
};
?>

<div>
    <h1>{{ $count }}</h1>
    <button wire:click="increment">+</button>
</div>

<style>
    /* CSS automaticamente scoped ao componente */
</style>

<script>
    // `this` e alias para $wire
</script>
```

### Multi-File Components (MFC)

```
resources/views/components/counter/
  counter.php       # Classe
  counter.blade.php # Template
  counter.css       # CSS scoped (opcional)
  counter.js        # JavaScript (opcional)
  counter.test.php  # Teste (opcional)
```

### Class-Based Components (Estilo v3 — Continua Funcionando)

```php
// app/Livewire/Counter.php
namespace App\Livewire;

use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    public function increment(): void { $this->count++; }

    public function render()
    {
        return view('livewire.counter');
    }
}
```

### Criacao via Artisan

```bash
php artisan make:livewire counter                    # SFC (padrao)
php artisan make:livewire post.create --mfc          # Multi-file
php artisan make:livewire CreatePost --class          # Class-based (v3 style)
php artisan make:livewire counter --test             # Com arquivo de teste
```

### wire:show — Visibilidade Otimista (Sem Rede)

```blade
<div wire:show="$dirty">Voce tem alteracoes nao salvas</div>
<div wire:show="$dirty('title')">Titulo modificado</div>
```

### wire:text — Texto Otimista

```blade
<span wire:text="$wire.likes">0</span>
<button wire:click="like" x-on:click="$wire.likes++">Curtir</button>
```

### data-loading — Loading States Automaticos

```blade
<button wire:click="save" class="data-loading:opacity-50">
    Salvar
    <svg class="not-in-data-loading:hidden">...</svg>
</button>
```

Funciona com Tailwind v4 `data-loading:` variant.

### wire:sort — Drag and Drop Nativo

```blade
<ul wire:sort="reorder">
    @foreach ($items as $item)
        <li wire:key="{{ $item->id }}" wire:sort:item="{{ $item->id }}">
            <span wire:sort:handle>&#8597;</span>
            {{ $item->title }}
        </li>
    @endforeach
</ul>
```

Metodo no componente:
```php
public function reorder(array $order): void
{
    foreach ($order as $position => $id) {
        Item::find($id)->update(['sort_order' => $position]);
    }
}
```

Suporta: `wire:sort:handle`, `wire:sort:ignore`, `wire:sort:group` (multi-lista).

### #[Async] — Fire-and-Forget

```php
#[Async]
public function logActivity(): void
{
    // Executa em paralelo, nao bloqueia UI
    ActivityLog::create([...]);
}
```

### #[Json] — Retorna Dados para JavaScript

```php
#[Json]
public function search(string $query): Collection
{
    return Post::where('title', 'like', "%{$query}%")->get();
}
```

```javascript
// No JavaScript do componente
let results = await this.search('livewire');
```

### #[Lazy] e #[Defer]

```php
#[Lazy]
class Revenue extends Component { /* carrega ao entrar no viewport */ }

#[Defer]
class Revenue extends Component { /* carrega apos render da pagina */ }
```

```blade
<livewire:revenue lazy />
<livewire:revenue defer />
```

### #[Computed] — Propriedades Computadas com Cache

```php
#[Computed]
public function posts(): Collection
{
    return Post::all();
}

#[Computed(persist: true)]  // Cache entre requests por componente
public function user(): User
{
    return User::find($this->userId);
}

#[Computed(persist: true, seconds: 7200)]  // Cache customizado

#[Computed(cache: true)]  // Cache global da aplicacao
public function settings(): array
{
    return Setting::all()->pluck('value', 'key')->toArray();
}
```

### Component Slots e Attributes

```blade
{{-- Chamada com slot --}}
<livewire:card :$post>
    <h2>{{ $post->title }}</h2>
    <button wire:click="delete({{ $post->id }})">Deletar</button>
</livewire:card>

{{-- Attribute forwarding --}}
<livewire:post.show :$post class="mt-4" />
```

Dentro do componente:
```blade
<div {{ $attributes }}>
    {{ $slot }}
</div>
```

### wire:ref — Referencia para Componente

```blade
<livewire:modal wire:ref="modal" />

{{-- No componente pai --}}
$this->dispatch('close')->to(ref: 'modal');
```

### $errors no JavaScript

```blade
<div wire:show="$errors.has('email')">
    <span wire:text="$errors.first('email')"></span>
</div>
```

### wire:transition com Direcao

```php
#[Transition(type: 'forward')]
public function next(): void { $this->step++; }

#[Transition(type: 'backward')]
public function previous(): void { $this->step--; }
```

### Renderless Actions

```blade
{{-- Pula re-render para acoes que sao puro side-effect --}}
<button wire:click.renderless="trackClick">Click</button>
```

### Preserve Scroll

```blade
<button wire:click.preserve-scroll="loadMore">Carregar mais</button>
```

---

## 4. Form Objects

### Criacao

```bash
php artisan livewire:form PostForm
```

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\Post;
use Livewire\Attributes\Validate;
use Livewire\Form;

final class PostForm extends Form
{
    #[Validate('required|min:5')]
    public string $title = '';

    #[Validate('required|min:10')]
    public string $content = '';

    public ?Post $post = null;

    public function setPost(Post $post): void
    {
        $this->post = $post;
        $this->title = $post->title;
        $this->content = $post->content;
    }

    public function store(): void
    {
        $this->validate();
        Post::create($this->only(['title', 'content']));
        $this->reset();
    }

    public function update(): void
    {
        $this->validate();
        $this->post->update($this->only(['title', 'content']));
    }
}
```

### Uso no Componente

```php
public PostForm $form;

public function save(): void
{
    $this->form->store();
    $this->redirect('/posts');
}
```

**IMPORTANTE:** Mesmo com Form Objects, a VIEW deve usar componentes Filament via `InteractsWithForms`, nao HTML puro:

```php
// No componente — SEMPRE usar InteractsWithForms
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;

final class CreatePost extends Component implements HasForms
{
    use InteractsWithForms;

    public PostForm $form;

    public function filamentForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')->label(__('posts.fields.title'))->required(),
                Textarea::make('content')->label(__('posts.fields.content'))->required(),
            ])
            ->statePath('form');
    }
}
```

```blade
{{-- View usando Filament form --}}
<div>
    <form wire:submit="save">
        {{ $this->filamentForm }}
        <x-filament::button type="submit">
            {{ __('common.actions.save') }}
        </x-filament::button>
    </form>
</div>
```

---

## 5. JavaScript Interop

### Objeto $wire

```javascript
// Propriedades
$wire.propertyName           // Ler
$wire.propertyName = value   // Mutar (deferred)
$wire.$set('name', value)    // Mutar (sync imediato)
$wire.$get('name')           // Ler por nome
$wire.$toggle('name')        // Toggle boolean

// Metodos
$wire.methodName()           // Chamar metodo PHP
$wire.$call('method', ...args)
$wire.$refresh()             // Re-render
$wire.$commit()              // Forcar commit

// Eventos
$wire.$dispatch('event', data)
$wire.$dispatchTo('component', 'event', data)
$wire.$on('event', callback)

// Watchers
$wire.$watch('property', callback)

// Islands
$wire.$island('name').$method()
$wire.$island('name', { mode: 'append' }).$method()

// Utilidades
$wire.$el     // Elemento DOM raiz
$wire.$id     // ID do componente
$wire.$parent // Componente pai
```

### Alpine.js (Incluso no Livewire)

```blade
<div x-data="{ open: false }">
    <button x-on:click="open = !open">Toggle</button>
    <div x-show="open">
        <span x-text="$wire.title"></span>
        <button x-on:click="$wire.save()">Salvar</button>
    </div>
</div>
```

### $js Actions — JavaScript no Componente

```blade
<button wire:click="$js.bookmark">Bookmark</button>

<script>
    this.$js.bookmark = () => {
        this.bookmarked = !this.bookmarked;
        this.save();
    }
</script>
```

### Interceptors (3 Niveis)

```javascript
// Nivel de Action
$wire.intercept('save', ({ onSuccess, onError }) => {
    onSuccess(() => showToast('Salvo!'));
    onError(() => showToast('Erro', 'error'));
});

// Nivel de Message
$wire.interceptMessage(({ message, cancel, onSend, onSuccess }) => { });

// Nivel de Request (HTTP)
Livewire.interceptRequest(({ request, onError }) => {
    onError(({ response, preventDefault }) => {
        if (response.status === 419) {
            preventDefault();
            window.location.reload();
        }
    });
});
```

### @assets para Libs Externas

```blade
@assets
<script src="https://cdn.example.com/chart.js"></script>
@endassets

<script>
    new Chart($wire.$el.querySelector('.chart-canvas'), { ... });
</script>
```

### @js Directive — PHP para JS

```blade
<div x-data="{ items: @js($collection) }">
```

### Executar JS do PHP

```php
$this->js("alert('Salvo!')");
$this->js('$wire.$refresh()');
```

---

## 6. Validacao

### Attribute-Based (Valida em Cada Update)

```php
#[Validate('required|min:5')]
public string $title = '';

// Desabilitar auto-validacao
#[Validate('required', onUpdate: false)]
public string $title = '';
```

### Method-Based (Valida em $this->validate())

```php
protected function rules(): array
{
    return [
        'title' => ['required', Rule::unique('posts')->ignore($this->post)],
        'content' => 'required|min:5',
    ];
}
```

### Real-Time Validation

```blade
<input wire:model.live="title">           {{-- Valida a cada keystroke --}}
<input wire:model.live.blur="title">      {{-- Valida ao sair do campo --}}
<input wire:model.live.debounce.300ms="title"> {{-- Debounce --}}
```

---

## 7. File Uploads

```php
use Livewire\WithFileUploads;
use Livewire\Attributes\Validate;

class UploadPhoto extends Component
{
    use WithFileUploads;

    #[Validate('image|max:1024')]
    public $photo;

    #[Validate(['photos.*' => 'image|max:1024'])]
    public array $photos = [];

    public function save(): void
    {
        $this->photo->store(path: 'photos');
    }
}
```

```blade
<input type="file" wire:model="photo">

{{-- Preview --}}
@if ($photo)
    <img src="{{ $photo->temporaryUrl() }}">
@endif

{{-- Multiplos --}}
<input type="file" wire:model="photos" multiple>

{{-- Cancelar --}}
<button wire:click="$cancelUpload('photo')">Cancelar</button>
```

---

## 8. Regras do Projeto

### REGRA #1: SEMPRE Usar Componentes Filament na UI

**OBRIGATORIO:** Em todo componente Livewire customizado, usar SEMPRE os componentes nativos do Filament para formularios e UI. Nunca usar HTML puro (`<input>`, `<select>`, `<button>`) quando existir equivalente Filament.

```php
// PROIBIDO - HTML puro
<input type="text" wire:model="name" class="border rounded px-3 py-2">
<select wire:model="status">
    <option value="active">Ativo</option>
</select>
<button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Salvar</button>

// OBRIGATORIO - Componentes Filament
// Usar InteractsWithForms + Form schema:
TextInput::make('name')->label(__('fields.name'))->required()
Select::make('status')->options(StatusEnum::class)
// Ou componentes Blade do Filament:
<x-filament::button type="submit">{{ __('common.actions.save') }}</x-filament::button>
<x-filament::input.wrapper>
    <x-filament::input type="text" wire:model="search" />
</x-filament::input.wrapper>
```

**Por que:**
- Consistencia visual em 100% da aplicacao
- Tema, cores e estilos centralizados
- Dark mode automatico
- Acessibilidade built-in
- Validacao visual integrada

**Componentes Filament disponiveis para uso em Livewire:**

| Necessidade | Componente Filament |
|-------------|-------------------|
| Formulario completo | `InteractsWithForms` + `Form` schema |
| Tabela de dados | `InteractsWithTable` + `Table` schema |
| Botao | `<x-filament::button>` |
| Input de texto | `TextInput::make()` via Form schema |
| Select/Dropdown | `Select::make()` via Form schema |
| Toggle/Checkbox | `Toggle::make()` / `Checkbox::make()` via Form schema |
| Date picker | `DatePicker::make()` via Form schema |
| File upload | `FileUpload::make()` via Form schema |
| Rich editor | `RichEditor::make()` via Form schema |
| Notificacao | `Filament\Notifications\Notification` |
| Modal/Action | `Filament\Actions\Action` |
| Badge | `<x-filament::badge>` |
| Loading | `<x-filament::loading-indicator>` |
| Section/Card | `Filament\Schemas\Components\Section` via Form schema |
| Tabs | `Filament\Schemas\Components\Tabs` via Form schema |
| Icon | `<x-filament::icon icon="heroicon-o-..." />` |

**Abordagem preferida:** Usar `InteractsWithForms` para qualquer componente que tenha campos de entrada. Isso garante que TODOS os campos usem componentes Filament nativos.

### Formato Padrao para Componentes Custom

Neste projeto, usar **class-based components** (estilo v3) para componentes que integram com Filament, pois e o formato usado pelo Filament internamente.

Para componentes standalone simples (widgets de dashboard, modais, etc.), usar SFC ou class-based conforme complexidade.

### Nomenclatura

```
app/Livewire/
├── Components/            # Componentes reutilizaveis
│   ├── ChartWidget.php
│   └── DataTable.php
├── Forms/                 # Form Objects
│   └── PostForm.php
├── Modals/                # Modais
│   └── ConfirmDelete.php
└── Pages/                 # Full-page components (se nao Filament)
    └── Dashboard.php
```

### Checklist para Novo Componente

- [ ] **UI usa SOMENTE componentes Filament (nunca HTML puro para forms)**
- [ ] Classe criada em `app/Livewire/`
- [ ] View em `resources/views/livewire/`
- [ ] Propriedades tipadas com `declare(strict_types=1)`
- [ ] `InteractsWithForms` se tem campos de entrada
- [ ] Validacoes definidas (`#[Validate]` ou `rules()`)
- [ ] Labels usando `__()` para traducao
- [ ] Botoes usando `<x-filament::button>` (nunca `<button>` puro)
- [ ] Notificacoes usando `Filament\Notifications\Notification`
- [ ] Testes criados em `tests/Feature/Livewire/`
- [ ] Islands usadas onde performance importa
- [ ] `#[Computed]` para dados derivados (nao recalcular no render)
- [ ] `#[Lazy]` ou `#[Defer]` se componente e pesado
