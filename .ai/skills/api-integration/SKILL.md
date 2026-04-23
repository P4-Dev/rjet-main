# Skill: Integração com APIs Externas

## ⚠️ IMPORTANTE: Sempre Consultar Documentação Atual

**ANTES de implementar qualquer integração:**

1. **Busque na internet** a documentação oficial e mais recente da API
2. **Verifique** endpoints, autenticação, headers e formatos de request/response
3. **Confirme** se há SDKs oficiais disponíveis para PHP/Laravel
4. **Identifique** rate limits, webhooks e boas práticas do provider

```
Exemplo de busca:
- "Asaas API documentation"
- "Stripe API PHP SDK"
- "Twilio Laravel integration"
- "{Provider} webhook signature validation"
```

**Por que isso é importante:**
- APIs mudam frequentemente (endpoints, autenticação, campos)
- Documentação do provider é a fonte da verdade
- Evita implementar baseado em informações desatualizadas
- Identifica SDKs oficiais que simplificam a integração

---

## Quando Usar

Use este skill quando precisar:
- Integrar com API externa (REST, GraphQL)
- Criar Integrations (classes de integração)
- Configurar HTTP clients
- Lidar com autenticação de APIs
- Processar webhooks

## Estrutura de Integration

### Estrutura de Pastas

```
app/Integrations/
├── Contracts/
│   ├── PaymentIntegration.php
│   └── NotificationIntegration.php
├── Asaas/
│   └── AsaasPaymentIntegration.php
├── Stripe/
│   └── StripePaymentIntegration.php
└── Twilio/
    └── TwilioNotificationIntegration.php
```

### Interface (Contract)

```php
<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\DTOs\{Input}Data;
use App\DTOs\{Output}Data;

interface {Nome}Integration
{
    public function execute({Input}Data $data): {Output}Data;
}
```

### Implementação

```php
<?php

declare(strict_types=1);

namespace App\Integrations\{Provider};

use App\DTOs\{Input}Data;
use App\DTOs\{Output}Data;
use App\Exceptions\{Provider}Exception;
use App\Integrations\Contracts\{Nome}Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class {Provider}{Nome}Integration implements {Nome}Integration
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.{provider}.url'))
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.{provider}.token'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->retry(3, 100, function (\Exception $e, PendingRequest $request) {
                // Só retry em erros de rede ou 5xx
                return $e instanceof RequestException 
                    && $e->response->serverError();
            })
            ->throw(); // Lança exceção em erros
    }

    public function execute({Input}Data $data): {Output}Data
    {
        try {
            $response = $this->client->post('/endpoint', $data->toArray());

            return {Output}Data::fromArray($response->json());
        } catch (RequestException $e) {
            throw new {Provider}Exception(
                message: $e->response->json('message', 'Erro na API'),
                code: $e->response->status(),
                previous: $e,
            );
        }
    }
}
```

### Registro no Container

```php
// app/Providers/AppServiceProvider.php

public function register(): void
{
    $this->app->bind(
        {Nome}Integration::class,
        {Provider}{Nome}Integration::class,
    );
}
```

## Configuração

### Config File

```php
// config/services.php

return [
    // ...
    
    '{provider}' => [
        'url' => env('{PROVIDER}_API_URL'),
        'token' => env('{PROVIDER}_API_TOKEN'),
        'timeout' => env('{PROVIDER}_TIMEOUT', 30),
    ],
];
```

### Environment

```env
{PROVIDER}_API_URL=https://api.provider.com/v1
{PROVIDER}_API_TOKEN=your-token-here
{PROVIDER}_TIMEOUT=30
```

## DTOs para API

### Request DTO

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CreatePaymentData
{
    public function __construct(
        public int $amount,
        public string $currency,
        public string $customer_id,
        public ?string $description = null,
        public ?array $metadata = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer_id' => $this->customer_id,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null);
    }
}
```

### Response DTO

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PaymentResultData
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public ?string $error_message = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new static(
            id: $data['id'],
            status: $data['status'],
            amount: $data['amount'],
            error_message: $data['error']['message'] ?? null,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }
}
```

## Exceções Customizadas

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class {Provider}Exception extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Exception $previous = null,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function report(): void
    {
        // Log para monitoramento
        logger()->error('Provider API Error', [
            'message' => $this->message,
            'code' => $this->code,
            'errors' => $this->errors,
        ]);
    }
}
```

## Webhooks

### Controller

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Services\{Provider}WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class {Provider}WebhookController
{
    public function __construct(
        private readonly {Provider}WebhookService $service,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Validar assinatura
        if (!$this->verifySignature($request)) {
            abort(401, 'Invalid signature');
        }

        $this->service->handle(
            event: $request->input('type'),
            payload: $request->all(),
        );

        return response()->noContent();
    }

    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Signature');
        $payload = $request->getContent();
        $secret = config('services.{provider}.webhook_secret');

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
```

### Webhook Service

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaymentReceived;
use App\Events\PaymentFailed;

final class {Provider}WebhookService
{
    public function handle(string $event, array $payload): void
    {
        match ($event) {
            'payment.succeeded' => $this->handlePaymentSucceeded($payload),
            'payment.failed' => $this->handlePaymentFailed($payload),
            default => logger()->info("Unhandled webhook: {$event}"),
        };
    }

    private function handlePaymentSucceeded(array $payload): void
    {
        $payment = Payment::where('external_id', $payload['data']['id'])->first();

        if ($payment) {
            $payment->update(['status' => 'succeeded']);
            PaymentReceived::dispatch($payment);
        }
    }

    private function handlePaymentFailed(array $payload): void
    {
        $payment = Payment::where('external_id', $payload['data']['id'])->first();

        if ($payment) {
            $payment->update([
                'status' => 'failed',
                'error_message' => $payload['data']['error']['message'] ?? null,
            ]);
            PaymentFailed::dispatch($payment);
        }
    }
}
```

### Routes

```php
// routes/webhooks.php

Route::post('/webhooks/{provider}', {Provider}WebhookController::class)
    ->withoutMiddleware(['csrf', 'auth'])
    ->middleware('throttle:webhook');
```

## Testes

### Mocking HTTP

```php
<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'api.provider.com/*' => Http::response([
            'id' => 'pay_123',
            'status' => 'succeeded',
            'amount' => 1000,
        ]),
    ]);
});

it('creates payment via integration', function () {
    $integration = app(PaymentIntegration::class);
    
    $result = $integration->execute(new CreatePaymentData(
        amount: 1000,
        currency: 'brl',
        customer_id: 'cus_123',
    ));

    expect($result)
        ->id->toBe('pay_123')
        ->status->toBe('succeeded')
        ->isSuccessful()->toBeTrue();
});

it('handles API errors', function () {
    Http::fake([
        'api.provider.com/*' => Http::response([
            'error' => ['message' => 'Card declined'],
        ], 400),
    ]);

    expect(fn () => app(PaymentIntegration::class)->execute(...))
        ->toThrow(ProviderException::class);
});
```

### Webhook Testing

```php
it('processes webhook', function () {
    $payment = Payment::factory()->create(['external_id' => 'pay_123']);

    $this->postJson('/webhooks/provider', [
        'type' => 'payment.succeeded',
        'data' => ['id' => 'pay_123'],
    ], [
        'X-Signature' => hash_hmac('sha256', '...', config('services.provider.webhook_secret')),
    ])
    ->assertNoContent();

    expect($payment->fresh()->status)->toBe('succeeded');
});
```

## Checklist

### Antes de Implementar
- [ ] 🔍 **Buscar documentação atual na internet**
- [ ] Verificar endpoints e autenticação
- [ ] Identificar SDKs oficiais disponíveis
- [ ] Entender rate limits e boas práticas

### Implementação
- [ ] Interface (Contract) definida
- [ ] Integration implementada
- [ ] Bound no container
- [ ] Config com env vars
- [ ] DTOs para request/response
- [ ] Exceção customizada
- [ ] Retry configurado
- [ ] Timeout configurado
- [ ] Webhook handler (se aplicável)
- [ ] Testes com HTTP fake
