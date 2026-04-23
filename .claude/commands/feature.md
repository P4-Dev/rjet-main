---
description: Cria uma feature completa (Model → DTO → Service → Resource → Tests)
---

# /feature $ARGUMENTS

Crie uma feature completa para **$ARGUMENTS** seguindo os padrões do projeto.

## Passos

### 1. Análise Inicial

Antes de começar:
- Leia `PROJECT.md` para entender os padrões do projeto
- **Leia "Preferências de Comunicação e Estilo de Código"** em PROJECT.md — idioma de resposta, nível de detalhe, comentários (nível e idioma), convenção de variáveis. **Siga rigorosamente em todo código e resposta gerados.**
- **Verifique `usar_docker` e `container_app`** — se Docker, prefixar comandos com `docker compose exec {container}`
- Use `search-docs` para verificar sintaxe atual do Laravel e Filament
- Use `schema` para verificar tabelas existentes

### 2. Planejamento

Defina:
- Campos do Model (types, casts, fillable)
- Relationships (belongsTo, hasMany, etc)
- Enum para status se aplicável
- Validações necessárias
- **Agrupamento por domínio:** verifique `agrupar_por_dominio` em PROJECT.md para cada tipo de arquivo. Se `true`, criar subpasta com nome do domínio (ex: `app/Jobs/Order/`, `app/Events/Order/`, `app/Actions/Order/`)

### 3. Criação na Ordem

#### 3.1 Migration

**Recomendado:** Use `/migrate {tabela}` para gerar a migration automaticamente seguindo `database.md`.

Ou manualmente:
```bash
php artisan make:migration create_{table}_table
```
- Use UUID se configurado em PROJECT.md
- Adicione soft deletes se configurado
- Adicione foreign keys

#### 3.2 Model
- Aplique traits conforme PROJECT.md
- Configure casts
- Defina relationships
- Adicione scopes úteis

#### 3.3 DTO (se usar_dtos = true)
- Crie em `app/DTOs/{Nome}Data.php`
- Métodos: `fromArray`, `fromRequest`, `toArray`

#### 3.4 Service (se usar_services = true)
- Crie em `app/Services/{Nome}Service.php`
- Métodos: `create`, `update`, `delete`
- Use transactions
- Dispare events

#### 3.5 Arquivos de Tradução (OBRIGATÓRIO)
- Crie `lang/pt_BR/{resource}.php` com labels, fields, sections, actions, messages
- Crie `lang/en/{resource}.php` com as mesmas chaves em inglês
- Se `lang/pt_BR/common.php` não existir, crie-o também
- Consulte `.ai/docs/localization.md` para templates

#### 3.6 Enum (se houver status/type)
- Crie em `app/Enums/{Nome}Status.php`
- Implemente `HasLabel`, `HasColor`, `HasIcon` para Filament
- Labels com `__('enums.{nome}.{valor}')` — consulte `.ai/docs/enums.md`
- Adicione `canTransitionTo()` se enum tem transições de estado
- Atualize `lang/pt_BR/enums.php` e `lang/en/enums.php`

#### 3.7 Policy (se usar_policies = true)
- Crie via `php artisan make:policy {Nome}Policy --model={Nome}`
- Implemente: `viewAny`, `view`, `create`, `update`, `delete`

#### 3.8 Filament Resource (v5 — Estrutura OBRIGATÓRIA)

**ANTES de criar o Resource, LEIA `.ai/skills/filament/SKILL.md` INTEIRO.**

```bash
php artisan make:filament-resource {Model} --generate --soft-deletes --view --panel={panel} --no-interaction
```

O comando já gera a estrutura v5 correta com classes separadas (pasta PLURAL, Resource DENTRO):

```
{Models}/                                ← PLURAL (ex: Users/, ServiceCategories/)
├── {Model}Resource.php                  ← DENTRO da pasta, final, LIMPO
├── Schemas/{Model}Form.php              ← final class com configure(Schema)
├── Schemas/{Model}Infolist.php          ← final class com configure(Schema) — view page
├── Tables/{Models}Table.php             ← final class com configure(Table), nome PLURAL
├── Pages/ (Create, Edit, List, View{Model})
└── RelationManagers/                    ← se Model tem hasMany/belongsToMany
```

**Após geração, customize:**

1. **Resource principal delega (PROIBIDO componentes inline):**
   ```php
   public static function form(Schema $schema): Schema
   {
       return {Model}Form::configure($schema);
   }

   public static function infolist(Schema $schema): Schema
   {
       return {Model}Infolist::configure($schema);
   }

   public static function table(Table $table): Table
   {
       return {Models}Table::configure($table);
   }
   ```

2. **Table usa API v5:**
   - `->recordActions()` (NÃO `->actions()`)
   - `->toolbarActions()` com `BulkActionGroup` (NÃO `->bulkActions()`)

3. **Soft Deletes:** `getRecordRouteBindingEloquentQuery()` (NÃO `getEloquentQuery()`)

4. **Todas as classes são `final`**

5. **`$navigationIcon` DEVE usar `Heroicon::OutlinedXxx` enum (NÃO string)**

6. **Labels com `__()`, grupo de navegação**

7. **Relation Managers:** Se o Model tem relações hasMany/belongsToMany, gerar Relation Managers com `make:filament-relation-manager`

8. **Referência completa:** `.ai/skills/filament/SKILL.md` + `vendor/filament/blueprint/`

#### 3.9 Factory e Seeder
```bash
php artisan make:factory {Nome}Factory
php artisan make:seeder {Nome}Seeder
```
- Criar states para variações (pending, active, admin, etc.)
- Consulte `.ai/docs/factories-seeders.md` para padrões

#### 3.10 API REST (se solicitado)
Se a feature precisa de API REST, consulte `.ai/docs/api.md` e skill `api-rest`:
- Rotas em `routes/api/v{n}.php`
- Controller em `app/Http/Controllers/Api/V{n}/{Nome}Controller.php`
- Form Requests em `app/Http/Requests/Api/V{n}/`
- API Resource em `app/Http/Resources/Api/V{n}/{Nome}Resource.php`
- Anotações Swagger nos controllers/resources
- `php artisan l5-swagger:generate`

#### 3.11 Error Handling (se domínio complexo)
Se a feature tem regras de negócio que podem falhar:
- Crie exceção de domínio em `app/Exceptions/{Nome}Exception.php` estendendo `BusinessException`
- Static factory methods para cada cenário de erro
- Consulte `.ai/docs/error-handling.md`

#### 3.12 Events e Listeners (se aplicável)
Se a feature dispara eventos de negócio:
- Crie Events em `app/Events/{Nome}{Acao}.php`
- Crie Listeners em `app/Listeners/`
- Listeners com `ShouldQueue` para I/O externo
- Consulte `.ai/docs/events.md`

#### 3.13 Jobs (se processamento assíncrono)
Se a feature precisa de processamento em background:
- Crie Jobs em `app/Jobs/{Verbo}{Nome}Job.php`
- Configure `$tries`, `$timeout`, `$backoff`
- Consulte `.ai/docs/queues.md`

#### 3.14 Notifications (se notificar usuários)
Se a feature envia notificações:
- Crie Notifications em `app/Notifications/{Nome}{Acao}Notification.php`
- Implemente `ShouldQueue`
- Filament in-app com `sendToDatabase()`
- Consulte `.ai/docs/notifications.md`

#### 3.15 Componentes Livewire Custom (se necessário)
Se a feature precisa de componentes customizados (charts, dashboards, drag-and-drop, modais):
- Consulte `.ai/docs/livewire.md` para Livewire v4
- Siga o skill `livewire-components` para templates
- Componentes em `app/Livewire/`
- Views em `resources/views/livewire/`
- Use Islands para performance em componentes complexos
- Testes em `tests/Feature/Livewire/`

#### 3.16 Relation Managers (se Model tem hasMany/belongsToMany)

Se o Model tem relações hasMany/belongsToMany:
- Gerar com: `php artisan make:filament-relation-manager {Model}Resource {relationship} {titleAttribute} --generate --soft-deletes --panel={panel} --no-interaction`
- Registrar em `getRelations()` no Resource principal
- Consulte `.ai/skills/filament/SKILL.md` para exemplo completo

#### 3.17 Soft Deletes (se entidade de dominio)
Se a feature é uma entidade de domínio (Customer, Order, Product):
- Adicionar trait `SoftDeletes` no Model
- Cascade manual via Observer se tem relações dependentes
- Filament: `TrashedFilter`, `RestoreAction`, `ForceDeleteAction`
- Consulte `.ai/docs/soft-deletes.md`

#### 3.18 File Storage (se tem uploads)
Se a feature lida com uploads de arquivos:
- Configurar disco no `config/filesystems.php`
- Filament FileUpload com validação de tipos e tamanho
- Observer para cleanup de arquivos ao delete
- Consulte `.ai/docs/file-storage.md`

#### 3.19 Code Quality
Antes de finalizar:
- Executar `vendor/bin/pint --dirty --format agent` — consulte `.ai/docs/pint.md`
- Executar `vendor/bin/phpstan analyse` — consulte `.ai/docs/phpstan.md`
- Consultar `.ai/checklists.md` para checklist completo

#### 3.20 Testes com Pest
Crie via `php artisan make:test --pest Filament/{Nome}ResourceTest`:
- Testes de listagem
- Testes de criação
- Testes de edição
- Testes de deleção
- Testes de autorização

Se API REST foi criada, crie também `tests/Feature/Api/V{n}/{Nome}ApiTest.php`:
- Testes de CRUD via API
- Testes de autenticação (401)
- Testes de validação (422)
- Testes de filtros e paginação

### 4. Verificação

Após criar tudo:
```bash
php artisan migrate
php artisan test --filter={Nome}
```

## Output Esperado

Liste todos os arquivos criados no formato:
```
✅ Arquivos criados:
- database/migrations/xxxx_create_{table}_table.php
- app/Models/{Nome}.php
- app/DTOs/{Nome}Data.php
- app/Services/{Nome}Service.php
- app/Enums/{Nome}Status.php
- app/Policies/{Nome}Policy.php
- app/Filament/Resources/{Models}/{Model}Resource.php (DENTRO da pasta plural)
- app/Filament/Resources/{Models}/Schemas/{Model}Form.php
- app/Filament/Resources/{Models}/Schemas/{Model}Infolist.php
- app/Filament/Resources/{Models}/Tables/{Models}Table.php
- app/Filament/Resources/{Models}/Pages/...
- app/Filament/Resources/{Models}/RelationManagers/ (se hasMany/belongsToMany)
- lang/pt_BR/{nomes}.php
- lang/en/{nomes}.php
- database/factories/{Nome}Factory.php
- database/seeders/{Nome}Seeder.php
- tests/Feature/Filament/{Nome}ResourceTest.php
(Se API REST):
- routes/api/v1.php (atualizado)
- app/Http/Controllers/Api/V1/{Nome}Controller.php
- app/Http/Requests/Api/V1/Store{Nome}Request.php
- app/Http/Requests/Api/V1/Update{Nome}Request.php
- app/Http/Resources/Api/V1/{Nome}Resource.php
- tests/Feature/Api/V1/{Nome}ApiTest.php
```

## Dicas

- Consulte skills de `architecture` e `filament` para detalhes
- Siga as convenções de nomenclatura do PROJECT.md
- Use o idioma configurado para labels
