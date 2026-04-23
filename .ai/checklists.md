# Checklists Unificados - Laravel 12 + Filament v5

> Documento de referencia consolidado com todos os checklists do projeto,
> organizados por tipo de artefato. Cada item referencia a guideline de origem.

---

## 1. Model

- [ ] `declare(strict_types=1)` no topo do arquivo (architecture)
- [ ] Classe `final` (architecture)
- [ ] Traits: `HasFactory`, `HasUuid`, `SoftDeletes` (architecture)
- [ ] Metodo `casts()` configurado para Enums e tipos especiais (architecture)
- [ ] `$fillable` definido com todos os campos editaveis (architecture)
- [ ] Relationships com return types explicitos (`BelongsTo`, `HasMany`, etc.) (architecture)
- [ ] Scopes para queries comuns (`scopePending`, `scopeActive`, etc.) (architecture)
- [ ] Accessors e Mutators via `Attribute` quando necessario (architecture)
- [ ] Factory criada com `definition()` e estados uteis (architecture)
- [ ] Seeder criado se necessario (architecture)
- [ ] Observer registrado via `#[ObservedBy]` se houver lifecycle hooks (events)
- [ ] Traducoes em `lang/{locale}/{resource}.php` para labels do model (localization)
- [ ] `Relation::enforceMorphMap()` configurado se usar morphTo (database)
- [ ] Type hints em todos os parametros e retornos (architecture)
- [ ] Propriedades imutaveis com `readonly` quando aplicavel (architecture)

---

## 2. Migration

- [ ] PK e UUID: `$table->uuid('id')->primary()` (database)
- [ ] `$table->timestamps()` presente (database)
- [ ] `$table->softDeletes()` quando aplicavel (database)
- [ ] Tabela na 3NF ou desnormalizacao documentada com comentario (database)
- [ ] Booleanos com prefixo `is_` ou `has_`: `$table->boolean('is_active')` (database)
- [ ] Datas de evento com sufixo `_at`: `$table->timestamp('published_at')` (database)
- [ ] Rastreamento de autoria com sufixo `_by`: `$table->foreignUuid('created_by')` (database)
- [ ] Ordenacao usa `sort_order`: `$table->unsignedInteger('sort_order')` (database)
- [ ] Status/Tipo SEMPRE como `$table->string('status', 20)` — `$table->enum()` e PROIBIDO (database, enums)
- [ ] Valores monetarios com `decimal(10, 2)` — nunca `float` ou `double` (database)
- [ ] Indices em campos de busca, filtro e ordenacao (`->index()`) (database, performance)
- [ ] Indice em `status`, `type`, `is_active`, `is_default` (database)
- [ ] Indice unico em `slug`, `code`, `email` quando aplicavel (database)
- [ ] Indices compostos para queries frequentes (`['status', 'created_at']`) (performance)
- [ ] Foreign keys com `->constrained()` e `cascadeOnDelete` ou `nullOnDelete` (database)
- [ ] Morph com `uuidMorphs()` que ja cria indice (database)
- [ ] Ao modificar coluna, incluir TODOS os atributos previamente definidos (architecture)

---

## 3. Filament Resource

- [ ] `getModelLabel()` e `getPluralModelLabel()` usam `__()` (localization)
- [ ] `getNavigationGroup()` usa `__('navigation.groups.xxx')` (localization)
- [ ] **Form:** labels de todos os campos usam `__()` (localization)
- [ ] **Form:** validacao definida nos campos (`->required()`, `->email()`, etc.) (architecture)
- [ ] **Form:** Enum Selects usam `->options(EnumClass::class)` (enums)
- [ ] **Form:** File uploads com validacao (`->maxSize()`, `->acceptedFileTypes()`) (architecture)
- [ ] **Form:** Sections e Tabs com titulos usando `__()` (localization)
- [ ] **Table:** Status exibido como badge (`->badge()`) com cores via HasColor (enums)
- [ ] **Table:** Filtros implementados (SelectFilter por status, etc.) (architecture)
- [ ] **Table:** Campos de busca configurados (`->searchable()`) (architecture)
- [ ] **Table:** Campos ordenaveis configurados (`->sortable()`) (architecture)
- [ ] **Table:** Labels de colunas usam `__()` (localization)
- [ ] **Actions:** Confirmacao (`->requiresConfirmation()`) para acoes destrutivas (architecture)
- [ ] **Actions:** Labels e modalHeading usam `__()` (localization)
- [ ] **Actions:** Try/catch com `Notification` para erros de negocio (error-handling)
- [ ] Policy registrada e aplicada ao Resource (architecture)
- [ ] Eager loading via `$table->modifyQueryUsing(fn ($q) => $q->with([...]))` (performance)
- [ ] Notifications de sucesso/erro usam `__()` (localization)
- [ ] Stats de widgets usam `__()` e cache quando possivel (localization, performance)
- [ ] Arquivo `lang/pt_BR/{resource}.php` criado (localization)
- [ ] Arquivo `lang/en/{resource}.php` criado (localization)
- [ ] Campos comuns verificados em `common.php` antes de duplicar (localization)

---

## 4. Service

- [ ] Classe `final` (architecture)
- [ ] `declare(strict_types=1)` (architecture)
- [ ] Constructor injection (dependency injection, nunca `app()` inline) (architecture)
- [ ] Recebe DTOs, nao Request diretamente (architecture)
- [ ] `DB::transaction()` para operacoes de escrita multiplas (architecture)
- [ ] Events dispatchados no Service, nao no Controller (events)
- [ ] Events dispatchados apos transaction (ou listener com `$afterCommit = true`) (events)
- [ ] Cache invalidado apos create/update/delete (performance)
- [ ] Retorna Models ou colecoes tipadas, nunca arrays (architecture)
- [ ] Excecoes de negocio lanca `BusinessException` ou subclasse (error-handling)
- [ ] Logging estruturado para operacoes importantes (error-handling)
- [ ] Type hints em todos os parametros e retornos (architecture)

---

## 5. Enum

- [ ] Backed por `string` (nunca `int`) (enums)
- [ ] Migration usa APENAS `$table->string()` — `$table->enum()` e PROIBIDO (enums, database)
- [ ] Model tem `casts()` configurado para o Enum (enums)
- [ ] Implementa `HasLabel` (obrigatorio para Filament) (enums)
- [ ] Implementa `HasColor` se usado em badges/status (enums)
- [ ] Implementa `HasIcon` se precisa de icone visual (enums)
- [ ] Labels usam `__('enums.xxx.yyy')` para traducoes (enums, localization)
- [ ] `canTransitionTo()` implementado se enum representa estado com transicoes (enums)
- [ ] `allowedTransitions()` para uso em Filament Select dinamico (enums)
- [ ] Arquivo `lang/pt_BR/enums.php` atualizado com novos valores (enums)
- [ ] Arquivo `lang/en/enums.php` atualizado com novos valores (enums)
- [ ] Keys em TitleCase: `case Pending = 'pending'` (architecture)
- [ ] Testes para values, labels, colors e transicoes (enums, testing)

---

## 6. Job

- [ ] Implementa `ShouldQueue` (queues)
- [ ] `$tries` definido (numero de tentativas) (queues)
- [ ] `$timeout` definido (segundos) (queues)
- [ ] `$backoff` definido (segundos entre tentativas, pode ser array exponencial) (queues)
- [ ] Metodo `failed()` implementado para tratamento de erro (queues)
- [ ] Middleware configurado quando necessario (`WithoutOverlapping`, `RateLimited`) (queues)
- [ ] `ShouldBeUnique` se job nao pode duplicar (queues)
- [ ] Queue correta atribuida (`high`, `default`, `low`) (queues)
- [ ] Job e idempotente (pode rodar mais de uma vez sem efeito colateral) (queues)
- [ ] Classe `final` com `declare(strict_types=1)` (architecture)
- [ ] Testes com `Queue::fake()` e `Bus::fake()` (queues, testing)
- [ ] Teste de execucao sincrona com `dispatchSync()` (queues, testing)
- [ ] Teste do metodo `failed()` (queues, testing)

---

## 7. Event + Listener

### Event

- [ ] Classe `final` com propriedades `readonly` (events)
- [ ] Nome no passado: `OrderCreated`, `PaymentReceived` (events)
- [ ] Usa traits `Dispatchable`, `InteractsWithSockets`, `SerializesModels` (events)
- [ ] Dados minimos: passa o Model, nao dados derivados (events)
- [ ] Sem logica no event (apenas mensageiro) (events)

### Listener

- [ ] `ShouldQueue` para I/O externo (email, API, etc.) (events)
- [ ] `$afterCommit = true` se queued e executado dentro de transaction (events)
- [ ] `$queue`, `$tries`, `$backoff` definidos se queued (events)
- [ ] Metodo `failed()` implementado se queued (events)
- [ ] Classe `final` com `declare(strict_types=1)` (architecture)

### Dispatch

- [ ] Events dispatchados no Service, nunca no Controller (events)
- [ ] Subscriber criado para dominios com 3+ events relacionados (events)
- [ ] Observer usado apenas para lifecycle do Model (cache, slug, defaults) (events)
- [ ] Testes com `Event::fake()` para verificar dispatch (events, testing)
- [ ] Testes de listener isolados (sem fake, testando handle() direto) (events, testing)

---

## 8. Notification

- [ ] Implementa `ShouldQueue` (nunca sincrona em producao) (notifications)
- [ ] Queue atribuida no constructor (`$this->onQueue('default')`) (notifications)
- [ ] Labels e textos usam `__()` para traducoes (notifications, localization)
- [ ] `via()` retorna canais configurados com base nas preferencias do usuario (notifications)
- [ ] `toMail()` com subject, greeting, line, action usando `__()` (notifications)
- [ ] Template de email usando Markdown Laravel (`x-mail::message`) (notifications)
- [ ] `toArray()` / `toDatabase()` com dados estruturados para in-app (notifications)
- [ ] Filament: `Notification::make()->sendToDatabase()` para bell icon (notifications)
- [ ] Arquivo `lang/pt_BR/notifications.php` criado/atualizado (notifications)
- [ ] Arquivo `lang/en/notifications.php` criado/atualizado (notifications)
- [ ] Testes com `Notification::fake()` (notifications, testing)
- [ ] Teste de conteudo do email (subject, dados) (notifications, testing)
- [ ] Teste de dados do database notification (notifications, testing)

---

## 9. API Endpoint

### Antes de Criar

- [ ] Versao da API definida (v1, v2, ...) (api)
- [ ] Recursos e verbos HTTP definidos (api)
- [ ] Autenticacao definida: Sanctum (interno) ou Passport (terceiros) (api)
- [ ] Rate limiting configurado (api)

### Implementacao

- [ ] Rota versionada em `routes/api/v{n}.php`: `/api/v1/...` (api)
- [ ] Controller em `app/Http/Controllers/Api/V{n}/` (api)
- [ ] Controller usa Form Request para validacao (nunca inline) (api)
- [ ] Controller usa API Resource para response (nunca Model direto) (api)
- [ ] Controller delega logica ao Service (api)
- [ ] API Resource em `app/Http/Resources/Api/V{n}/` (api)
- [ ] Resource: timestamps em ISO 8601 (`->toIso8601String()`) (api)
- [ ] Resource: relationships com `whenLoaded()` (api)
- [ ] Resource: campos sensiveis nunca expostos (api)
- [ ] Form Request em `app/Http/Requests/Api/V{n}/` (api)
- [ ] Sanctum/Passport middleware aplicado nas rotas (api)
- [ ] Rate limiting aplicado (`throttle:api`, `throttle:auth`) (api)
- [ ] Paginacao configurada com maximo de 100 por pagina (api, performance)
- [ ] Eager loading de relationships no Controller (api, performance)
- [ ] Policy aplicada quando necessario (api)

### Documentacao

- [ ] Anotacoes Swagger/OpenAPI no Controller (api)
- [ ] Schema definido no Resource (api)
- [ ] `php artisan l5-swagger:generate` executado (api)

### Testes

- [ ] Teste de listagem com paginacao (api, testing)
- [ ] Teste de criacao com validacao (api, testing)
- [ ] Teste de atualizacao (api, testing)
- [ ] Teste de delecao (api, testing)
- [ ] Teste de autenticacao (401) (api, testing)
- [ ] Teste de autorizacao (403) (api, testing)
- [ ] Teste de not found (404) (api, testing)
- [ ] Teste de filtros e sorting (api, testing)

### Traducoes

- [ ] Mensagens de validacao em `lang/pt_BR/api.php` (api, localization)
- [ ] Mensagens de validacao em `lang/en/api.php` (api, localization)

---

## 10. Livewire Component

- [ ] Tags SEMPRE fechadas: `<livewire:name />` (livewire)
- [ ] Nunca usar `@entangle` (deprecado) — usar `$wire` diretamente (livewire)
- [ ] `InteractsWithForms` se tem campos de entrada (livewire)
- [ ] `InteractsWithActions` se usa actions/modais (livewire)
- [ ] UI usa SOMENTE componentes Filament (nunca HTML puro para forms) (livewire)
- [ ] Botoes usando `<x-filament::button>` (nunca `<button>` puro) (livewire)
- [ ] Notificacoes usando `Filament\Notifications\Notification` (livewire)
- [ ] Class-based para componentes que integram com Filament (livewire)
- [ ] `declare(strict_types=1)` com propriedades tipadas (architecture)
- [ ] Labels usando `__()` para traducao (localization)
- [ ] Validacoes definidas (`#[Validate]` ou `rules()`) (livewire)
- [ ] `wire:model.live.blur` para equivalente ao v3 `.blur` (livewire)
- [ ] Islands usadas onde performance importa (livewire)
- [ ] `#[Computed]` para dados derivados (nao recalcular no render) (livewire, performance)
- [ ] `#[Lazy]` ou `#[Defer]` se componente e pesado (livewire, performance)
- [ ] Classe em `app/Livewire/`, view em `resources/views/livewire/` (livewire)
- [ ] Testes criados em `tests/Feature/Livewire/` com `Livewire::test()` (livewire, testing)

---

## 11. Factory

- [ ] `definition()` com dados realistas via Faker (testing)
- [ ] Nunca dados hardcoded no definition — usar `fake()->word()`, etc. (testing)
- [ ] States para variantes comuns: `pending()`, `shipped()`, `admin()` (testing)
- [ ] `afterCreating()` para criar relationships automaticamente (testing)
- [ ] `recycle()` para compartilhar parent models entre factories (testing)
- [ ] Dados monetarios com `fake()->randomFloat(2, 10, 1000)` (testing)
- [ ] UUIDs com `fake()->uuid()` ou `Str::uuid()` (testing)
- [ ] Enum values com `fake()->randomElement(EnumClass::cases())` (testing)

---

## 12. Command (Artisan)

- [ ] Signature com `{argument}` e `{--option}` definidos (architecture)
- [ ] `$description` preenchido com descricao clara (architecture)
- [ ] `handle()` delega logica ao Service ou despacha Job (architecture)
- [ ] `withoutOverlapping()` se command e scheduled (architecture)
- [ ] Output via `$this->info()`, `$this->error()`, `$this->table()` (architecture)
- [ ] Progress bar para operacoes longas (`$this->withProgressBar()`) (architecture)
- [ ] Retorna `Command::SUCCESS` ou `Command::FAILURE` (architecture)
- [ ] Testes com `$this->artisan('command:name')->assertSuccessful()` (testing)

---

## 13. Test (Pest)

- [ ] Sintaxe `describe` / `it` / `expect` (testing)
- [ ] `beforeEach` para setup comum (testing)
- [ ] Usa factories para criar models (nunca `Model::create()` direto) (testing)
- [ ] Usa estados de factory quando disponiveis (`->pending()`, `->admin()`) (testing)
- [ ] `actingAs()` para autenticacao (testing)
- [ ] Fakes configurados quando necessario: `Event::fake()`, `Queue::fake()`, `Notification::fake()`, `Storage::fake()`, `Mail::fake()` (testing)
- [ ] `Livewire::test()` para componentes Filament/Livewire (testing)
- [ ] Cobre happy path (caminho feliz) (testing)
- [ ] Cobre edge cases (casos limite) (testing)
- [ ] Cobre validacao (campos obrigatorios, formatos) (testing)
- [ ] Cobre autorizacao (acesso negado para usuarios sem permissao) (testing)
- [ ] Cobre falhas (transicoes invalidas, dados inexistentes) (testing)
- [ ] Nomes descritivos: `it('creates an order with items')`, nao `it('works')` (testing)
- [ ] Um arquivo de teste por classe/contexto (testing)
- [ ] Assertions fluentes com `expect()` (testing)
- [ ] `assertDatabaseHas()` / `assertDatabaseMissing()` para persistencia (testing)
- [ ] `Http::fake()` para integracoes externas (testing)
- [ ] `Http::assertSent()` para verificar chamadas externas (testing)

---

## 14. MCP Tools Usage

### Antes de Implementar

- [ ] `search-docs` para sintaxe e padroes atuais do pacote (mcp-tools)
- [ ] `schema` para verificar tabelas existentes (mcp-tools)
- [ ] `routes` para verificar rotas existentes (mcp-tools)
- [ ] `list-artisan-commands` para opcoes de geracao de arquivos (mcp-tools)

### Durante Implementacao

- [ ] `tinker` para testar logica complexa (mcp-tools)
- [ ] `database-query` para verificar dados (mcp-tools)
- [ ] `search-docs` para duvidas de sintaxe (mcp-tools)

### Depois de Implementar

- [ ] `get-absolute-url` para compartilhar URLs com o usuario (mcp-tools)
- [ ] `browser-logs` para verificar erros no frontend (mcp-tools)
- [ ] `tinker` para validar resultado no backend (mcp-tools)

---

## 15. Deployment (Docker)

### Dockerfile

- [ ] Multi-stage: Node (assets) + PHP-FPM (producao) (deployment)
- [ ] `--no-dev` no composer install (deployment)
- [ ] `--optimize --classmap-authoritative` no dump-autoload (deployment)
- [ ] Permissoes de storage/bootstrap/cache para www-data (deployment)
- [ ] Extensoes PHP necessarias instaladas (deployment)

### Docker Compose

- [ ] `expose` (nao `ports`) quando usar Traefik (deployment)
- [ ] Labels Traefik configuradas (router, entrypoint, TLS, port) (deployment)
- [ ] Volumes para dados persistentes (database, storage) (deployment)
- [ ] Network `traefik` externa (deployment)
- [ ] `restart: unless-stopped` (deployment)

### Supervisor

- [ ] PHP-FPM como programa gerenciado (deployment)
- [ ] Nginx como programa gerenciado (deployment)
- [ ] Queue workers (high/default/low) se usar filas (deployment, queues)
- [ ] Scheduler se usar scheduled commands (deployment, scheduling)
- [ ] `nodaemon=true` obrigatorio para container (deployment)

### GitHub Actions

- [ ] Trigger em `main`, `develop` e tags `v*.*.*` (deployment, git)
- [ ] Login no GHCR com `GITHUB_TOKEN` (deployment)
- [ ] Tags semanticas (latest, version, sha) (deployment)
- [ ] Cache de layers habilitado (`type=gha`) (deployment)

### Producao

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (deployment)
- [ ] `QUEUE_CONNECTION` nao e `sync` (deployment, queues)
- [ ] OPcache habilitado com `validate_timestamps=0` (deployment, performance)
- [ ] Health check endpoint `/health` configurado (deployment)

---

## Referencia Rapida de Guidelines

| Guideline | Caminho |
|-----------|---------|
| architecture | `.ai/docs/architecture.md` |
| testing | `.ai/docs/testing.md` |
| database | `.ai/docs/database.md` |
| localization | `.ai/docs/localization.md` |
| api | `.ai/docs/api.md` |
| livewire | `.ai/docs/livewire.md` |
| performance | `.ai/docs/performance.md` |
| queues | `.ai/docs/queues.md` |
| notifications | `.ai/docs/notifications.md` |
| error-handling | `.ai/docs/error-handling.md` |
| git | `.ai/docs/git.md` |
| enums | `.ai/docs/enums.md` |
| events | `.ai/docs/events.md` |
| file-storage | `.ai/docs/file-storage.md` |
| factories-seeders | `.ai/docs/factories-seeders.md` |
| scheduling | `.ai/docs/scheduling.md` |
| soft-deletes | `.ai/docs/soft-deletes.md` |
| phpstan | `.ai/docs/phpstan.md` |
| pint | `.ai/docs/pint.md` |
| mcp-tools | `.ai/docs/mcp-tools.md` |
