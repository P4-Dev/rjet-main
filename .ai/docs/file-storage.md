# Armazenamento de Arquivos - Guideline

> **Regras para upload, armazenamento, download e deleção de arquivos com Laravel Storage e Filament v5.**

---

## 1. Regra Fundamental

**Sempre usar a facade `Storage`**, nunca `file_put_contents()`, `move_uploaded_file()` ou manipulação direta do filesystem.

Laravel trabalha com **discos** configurados em `config/filesystems.php`:

| Disco | Uso | Visibilidade Padrão |
|-------|-----|---------------------|
| `local` | Arquivos privados (documentos internos, relatórios) | `private` |
| `public` | Arquivos acessíveis via URL (avatares, logos) | `public` |
| `s3` | Produção (qualquer tipo de arquivo) | `private` |

**Regras:**
- **Visibility `private` por padrão** (Filament v5 mudou para private)
- Usar `Storage::disk('nome')` para operações
- Disco de produção deve ser `s3` (ou equivalente como MinIO, DigitalOcean Spaces)
- Disco de desenvolvimento pode ser `local` ou `public`
- Configurar disco via `.env`, nunca hardcoded

```php
// Correto
Storage::disk('public')->put('avatars/foto.jpg', $contents);

// Errado - NUNCA fazer isso
file_put_contents(public_path('avatars/foto.jpg'), $contents);
```

---

## 2. Configuração de Discos

### Disco `local` (Privado)

```php
// config/filesystems.php
'local' => [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'throw' => false,
],
```

Arquivos em `storage/app/private` **não são acessíveis via URL**. Servir via controller com autenticação.

### Disco `public` (Link Simbólico)

```php
'public' => [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => env('APP_URL') . '/storage',
    'visibility' => 'public',
    'throw' => false,
],
```

Criar o link simbólico:

```bash
php artisan storage:link
```

### Disco `s3` (Produção)

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
],
```

### Disco Dinâmico por Ambiente

```php
// .env (desenvolvimento)
FILESYSTEM_DISK=public

// .env (produção)
FILESYSTEM_DISK=s3
```

```php
// Uso: sempre usar o disco padrão configurado
Storage::put('path/file.pdf', $contents); // usa FILESYSTEM_DISK
```

---

## 3. Upload de Arquivos

### Validação Obrigatória

**Todo upload deve ser validado.** Criar FormRequest dedicado:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'document' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,xls,xlsx',
                'max:10240', // 10MB
            ],
            'avatar' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048', // 2MB
                'dimensions:max_width=2000,max_height=2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => __('validation.custom.document.mimes'),
            'document.max' => __('validation.custom.document.max'),
            'avatar.max' => __('validation.custom.avatar.max'),
            'avatar.dimensions' => __('validation.custom.avatar.dimensions'),
        ];
    }
}
```

### `store()` vs `storeAs()`

```php
// store() - gera nome único com hash (RECOMENDADO)
$path = $request->file('document')->store('documents', 'public');
// resultado: documents/aB3xY9kL2mN4.pdf

// storeAs() - nome definido manualmente
$path = $request->file('document')->storeAs(
    'documents',
    $order->id . '.pdf',
    'public',
);
// resultado: documents/uuid-do-order.pdf
```

**Preferir `store()` com hash** para evitar colisões e problemas com caracteres especiais.

### Organização em Pastas por Tipo

```
storage/app/public/
├── avatars/           # Fotos de perfil
├── logos/             # Logos de empresas
├── documents/         # Documentos gerais
├── invoices/          # Notas fiscais
├── reports/           # Relatórios gerados
└── attachments/       # Anexos genéricos (morph)
```

### Controller de Upload

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

final class DocumentController extends Controller
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $path = $request->file('document')->store('documents');

        $document = Document::create([
            'name' => $request->file('document')->getClientOriginalName(),
            'path' => $path,
            'disk' => config('filesystems.default'),
            'mime_type' => $request->file('document')->getMimeType(),
            'size' => $request->file('document')->getSize(),
        ]);

        return response()->json($document, 201);
    }
}
```

---

## 4. Filament FileUpload

### Configuração Básica

```php
use Filament\Forms\Components\FileUpload;

FileUpload::make('avatar')
    ->image()
    ->disk('public')
    ->directory('avatars')
    ->visibility('public')
    ->maxSize(2048)         // 2MB
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->required(),
```

### Upload de Documentos

```php
FileUpload::make('document')
    ->disk('public')
    ->directory('documents')
    ->visibility('private')   // PADRÃO no Filament v5
    ->maxSize(10240)          // 10MB
    ->acceptedFileTypes([
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ])
    ->downloadable()
    ->openable()
    ->previewable(false)
    ->required(),
```

### Manipulação de Imagem (Resize, Crop)

```php
FileUpload::make('photo')
    ->image()
    ->disk('public')
    ->directory('photos')
    ->visibility('public')
    ->imageEditor()                     // Habilita editor visual
    ->imageCropAspectRatio('1:1')       // Forçar proporção
    ->imageResizeTargetWidth(500)       // Largura máxima
    ->imageResizeTargetHeight(500)      // Altura máxima
    ->imageResizeMode('cover')
    ->maxSize(5120),                    // 5MB
```

### Upload Múltiplo

```php
FileUpload::make('attachments')
    ->multiple()
    ->disk('public')
    ->directory('attachments')
    ->visibility('private')
    ->maxSize(10240)
    ->maxFiles(5)
    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
    ->reorderable()
    ->downloadable(),
```

### Preview e Callbacks

```php
FileUpload::make('cover')
    ->image()
    ->disk('public')
    ->directory('covers')
    ->visibility('public')
    ->imagePreviewHeight('200')
    ->afterStateUpdated(function ($state, $set): void {
        // Ação após upload
    })
    ->deleteUploadedFileUsing(function ($file): void {
        // Ação quando arquivo é removido do form
        Storage::disk('public')->delete($file);
    }),
```

---

## 5. Spatie Media Library (Alternativa para Collections)

Se o projeto usa `spatie/laravel-medialibrary`, a abordagem muda para collections:

### Configuração no Model

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('documents')
            ->useDisk('s3')
            ->singleFile(); // Apenas 1 arquivo na collection
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);

        $this->addMediaConversion('preview')
            ->width(600)
            ->height(400);
    }
}
```

### Filament com SpatieMediaLibraryFileUpload

```php
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

SpatieMediaLibraryFileUpload::make('images')
    ->collection('images')
    ->multiple()
    ->maxFiles(10)
    ->image()
    ->imageEditor()
    ->reorderable(),

SpatieMediaLibraryFileUpload::make('manual')
    ->collection('documents')
    ->acceptedFileTypes(['application/pdf'])
    ->maxSize(20480),
```

---

## 6. Download e URLs

### URL Pública (Disco `public`)

```php
// URL direta
$url = Storage::disk('public')->url($document->path);
// https://app.com/storage/documents/aB3xY9.pdf
```

### URL Temporária (S3 - Arquivos Privados)

```php
// URL assinada com expiração
$url = Storage::disk('s3')->temporaryUrl(
    $document->path,
    now()->addMinutes(30),
);
```

### Download via Controller (Arquivos Privados)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless(
            Storage::disk($document->disk)->exists($document->path),
            404,
            __('messages.file_not_found'),
        );

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->name,
        );
    }
}
```

### Streaming para Arquivos Grandes

```php
// Para arquivos grandes (> 50MB), usar streaming
return Storage::disk($document->disk)->download(
    $document->path,
    $document->name,
    ['Content-Type' => $document->mime_type],
);

// Ou response stream manual
return response()->stream(function () use ($document): void {
    $stream = Storage::disk($document->disk)->readStream($document->path);
    fpassthru($stream);
    fclose($stream);
}, 200, [
    'Content-Type' => $document->mime_type,
    'Content-Disposition' => 'attachment; filename="' . $document->name . '"',
]);
```

---

## 7. Deleção de Arquivos

### Deletar Arquivo ao Deletar Model (Observer)

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

final class DocumentObserver
{
    public function deleting(Document $document): void
    {
        if ($document->path) {
            Storage::disk($document->disk)->delete($document->path);
        }
    }
}
```

### Registrar Observer

```php
// No Model
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(DocumentObserver::class)]
final class Document extends Model
{
    // ...
}
```

### Alternativa: Model Event no `booted()`

```php
protected static function booted(): void
{
    static::deleting(function (Document $document): void {
        if ($document->path) {
            Storage::disk($document->disk ?? config('filesystems.default'))->delete($document->path);
        }
    });
}
```

### Cleanup de Arquivos Orfãos

Criar um Job/Command para limpar arquivos que não possuem mais registro no banco:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanOrphanFilesCommand extends Command
{
    protected $signature = 'files:clean-orphans {--dry-run}';

    protected $description = 'Remove arquivos órfãos do storage que não possuem registro no banco';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $files = $disk->allFiles('documents');
        $storedPaths = Document::pluck('path')->toArray();
        $orphans = array_diff($files, $storedPaths);

        $this->info(count($orphans) . ' arquivo(s) órfão(s) encontrado(s).');

        if ($this->option('dry-run')) {
            foreach ($orphans as $orphan) {
                $this->line("  [DRY RUN] {$orphan}");
            }

            return self::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $disk->delete($orphan);
            $this->line("  Deletado: {$orphan}");
        }

        return self::SUCCESS;
    }
}
```

Agendar no `routes/console.php`:

```php
use App\Console\Commands\CleanOrphanFilesCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CleanOrphanFilesCommand::class)->weekly();
```

---

## 8. Testes

### Fake Disk

```php
<?php

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('Document Upload', function () {
    beforeEach(function () {
        Storage::fake('public');
    });

    it('uploads a document successfully', function () {
        $file = UploadedFile::fake()->create('contract.pdf', 1024, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/documents', [
                'document' => $file,
            ]);

        $response->assertCreated();

        Storage::disk('public')->assertExists('documents/' . $file->hashName());
    });

    it('rejects files exceeding max size', function () {
        $file = UploadedFile::fake()->create('huge.pdf', 20480, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/documents', [
                'document' => $file,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);
    });

    it('rejects invalid mime types', function () {
        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($this->user)
            ->postJson('/api/documents', [
                'document' => $file,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);
    });

    it('deletes file when document model is deleted', function () {
        $file = UploadedFile::fake()->create('temp.pdf', 512, 'application/pdf');
        $path = $file->store('documents', 'public');

        $document = Document::factory()->create([
            'path' => $path,
            'disk' => 'public',
        ]);

        Storage::disk('public')->assertExists($path);

        $document->delete();

        Storage::disk('public')->assertMissing($path);
    });
});
```

### Teste de Imagem com Dimensões

```php
it('uploads image with correct dimensions', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('avatar.jpg', 500, 500)->size(1024);

    $response = $this->actingAs($this->user)
        ->postJson('/api/users/avatar', [
            'avatar' => $file,
        ]);

    $response->assertOk();
    Storage::disk('public')->assertExists('avatars/' . $file->hashName());
});

it('rejects image exceeding dimensions', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('avatar.jpg', 5000, 5000);

    $response = $this->actingAs($this->user)
        ->postJson('/api/users/avatar', [
            'avatar' => $file,
        ]);

    $response->assertUnprocessable();
});
```

### Teste de Download

```php
it('downloads document for authorized user', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->create('report.pdf', 512, 'application/pdf');
    $path = $file->store('documents', 'public');

    $document = Document::factory()->create([
        'path' => $path,
        'disk' => 'public',
        'name' => 'report.pdf',
    ]);

    $response = $this->actingAs($this->user)
        ->get("/documents/{$document->id}/download");

    $response->assertOk()
        ->assertDownload('report.pdf');
});

it('returns 404 for missing file', function () {
    $document = Document::factory()->create([
        'path' => 'documents/missing-file.pdf',
        'disk' => 'public',
    ]);

    $response = $this->actingAs($this->user)
        ->get("/documents/{$document->id}/download");

    $response->assertNotFound();
});
```

---

## 9. Segurança

### Nunca Confiar no Nome Original

```php
// ERRADO - vulnerável a path traversal e colisões
$path = $request->file('doc')->storeAs('documents', $request->file('doc')->getClientOriginalName());

// CORRETO - hash seguro
$path = $request->file('doc')->store('documents');
```

### Validar MIME Type Real (Não Apenas Extensão)

```php
// Validação por extensão pode ser burlada
'document' => ['file', 'mimes:pdf'], // checa extensão

// Validação por MIME type real (mais seguro)
'document' => ['file', 'mimetypes:application/pdf'], // checa conteúdo do arquivo

// Ideal: combinar ambos
'document' => [
    'file',
    'mimes:pdf',
    'mimetypes:application/pdf',
    'max:10240',
],
```

### Rate Limit para Uploads

```php
// bootstrap/app.php ou AppServiceProvider
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('uploads', function ($request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

```php
// routes/api.php
Route::post('/documents', [DocumentController::class, 'store'])
    ->middleware('throttle:uploads');
```

### Scan de Virus (Produção)

Para ambientes de produção com uploads de usuários externos, considerar ClamAV ou serviço equivalente:

```php
// Exemplo com ClamAV via job assíncrono
final class ScanUploadedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Document $document,
    ) {}

    public function handle(): void
    {
        // Integração com ClamAV ou serviço de scan
        $isClean = $this->scanFile($this->document->path);

        if (! $isClean) {
            Storage::disk($this->document->disk)->delete($this->document->path);
            $this->document->update(['status' => 'quarantined']);

            logger()->warning('Arquivo infectado detectado', [
                'document_id' => $this->document->id,
                'path' => $this->document->path,
            ]);
        }
    }

    private function scanFile(string $path): bool
    {
        // Implementar integração com ClamAV
        return true;
    }
}
```

### Headers de Segurança para Downloads

```php
return Storage::disk($document->disk)->download(
    $document->path,
    $document->name,
    [
        'Content-Type' => $document->mime_type,
        'Content-Disposition' => 'attachment; filename="' . $document->name . '"',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'private, no-cache',
    ],
);
```

---

## 10. Checklist

- [ ] Upload usa `Storage` facade (nunca `file_put_contents`)
- [ ] Disco configurado via `.env` (`FILESYSTEM_DISK`)
- [ ] Validação de upload: `mimes` + `mimetypes` + `max` size
- [ ] Nomes de arquivo gerados com hash (`store()`)
- [ ] Visibility definida explicitamente (`private` por padrão no Filament v5)
- [ ] Link simbólico criado (`php artisan storage:link`) se usar disco `public`
- [ ] Observer ou Event deleta arquivo ao deletar model
- [ ] Command de cleanup de arquivos órfãos (se aplicável)
- [ ] Testes com `Storage::fake()` e `UploadedFile::fake()`
- [ ] Testes cobrem: upload, validação, download, deleção
- [ ] Rate limit configurado para rotas de upload
- [ ] Scan de vírus para uploads em produção (se aceitar arquivos externos)
- [ ] Download de arquivos privados passa por autenticação/autorização
- [ ] Filament FileUpload com `disk()`, `directory()`, `visibility()`, `maxSize()` configurados
