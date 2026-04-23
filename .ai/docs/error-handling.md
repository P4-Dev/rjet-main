# Error Handling e Logging - Guideline

> **Regras para tratamento de erros, exceções customizadas e logging estruturado.**

---

## 1. Hierarquia de Exceções

```
app/Exceptions/
├── BusinessException.php          # Erros de regra de negócio (user-facing)
├── IntegrationException.php       # Erros de APIs externas
├── {Dominio}/
│   ├── OrderException.php         # Erros específicos do domínio
│   └── PaymentException.php
```

---

## 2. Exceção Base de Negócio

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $userMessage = null,
        int $code = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Mensagem segura para exibir ao usuário.
     */
    public function getUserMessage(): string
    {
        return $this->userMessage ?? __('errors.generic');
    }
}
```

---

## 3. Exceção de Domínio

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

final class OrderException extends BusinessException
{
    public static function cannotCancel(string $orderId): self
    {
        return new self(
            message: "Cannot cancel order {$orderId}: already shipped",
            userMessage: __('orders.errors.cannot_cancel'),
        );
    }

    public static function insufficientStock(string $productId, int $requested, int $available): self
    {
        return new self(
            message: "Insufficient stock for product {$productId}: requested {$requested}, available {$available}",
            userMessage: __('orders.errors.insufficient_stock', [
                'requested' => $requested,
                'available' => $available,
            ]),
        );
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            message: "Invalid status transition: {$from} -> {$to}",
            userMessage: __('orders.errors.invalid_transition'),
        );
    }
}
```

### Uso

```php
// No Service
if ($order->status === 'shipped') {
    throw OrderException::cannotCancel($order->id);
}

// No Controller/Action Filament
try {
    $this->service->cancel($order);
} catch (OrderException $e) {
    Notification::make()
        ->title($e->getUserMessage())
        ->danger()
        ->send();
}
```

---

## 4. Exceção de Integração

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Client\Response;

final class IntegrationException extends \Exception
{
    public function __construct(
        public readonly string $provider,
        string $message,
        int $code = 0,
        public readonly ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", $code, $previous);
    }

    public static function fromResponse(string $provider, Response $response): self
    {
        return new self(
            provider: $provider,
            message: $response->json('message', 'Unknown error'),
            code: $response->status(),
            context: [
                'status' => $response->status(),
                'body' => $response->json(),
            ],
        );
    }

    public function report(): void
    {
        logger()->error($this->getMessage(), [
            'provider' => $this->provider,
            'code' => $this->code,
            'context' => $this->context,
        ]);
    }
}
```

---

## 5. Handler Global

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    // Render JSON para rotas de API
    $exceptions->shouldRenderJsonWhen(function (Request $request) {
        return $request->is('api/*') || $request->expectsJson();
    });

    // Reportar exceções de integração com contexto
    $exceptions->report(function (IntegrationException $e) {
        // Já logado pelo método report() da exceção
        // Adicionar alertas externos (Sentry, Slack, etc.) aqui
    })->stop();

    // Não reportar exceções de negócio (são esperadas)
    $exceptions->report(function (BusinessException $e) {
        // Logar apenas em debug
        if (config('app.debug')) {
            logger()->debug($e->getMessage());
        }
    })->stop();
})
```

---

## 6. Logging Estruturado

### Níveis de Log

| Nível | Quando Usar | Exemplo |
|-------|-------------|---------|
| `emergency` | Sistema inutilizável | Database down |
| `critical` | Ação imediata necessária | Payment gateway offline |
| `error` | Erro em runtime | Job falhou, API retornou 500 |
| `warning` | Situação inesperada mas tratada | Retry de API, cache miss |
| `info` | Eventos importantes do negócio | Pedido criado, pagamento recebido |
| `debug` | Informação de debug | Query executada, dados recebidos |

### Contexto Sempre Estruturado

```php
// PROIBIDO - string sem contexto
logger()->error("Order failed: " . $order->id);

// OBRIGATÓRIO - contexto estruturado
logger()->error('Order processing failed', [
    'order_id' => $order->id,
    'status' => $order->status,
    'error' => $exception->getMessage(),
    'trace' => $exception->getTraceAsString(),
]);
```

### Logs de Negócio

```php
// Eventos importantes sempre logados
logger()->info('Order created', [
    'order_id' => $order->id,
    'customer_id' => $order->customer_id,
    'total' => $order->total,
    'items_count' => $order->items()->count(),
]);

logger()->info('Payment received', [
    'order_id' => $order->id,
    'amount' => $payment->amount,
    'gateway' => $payment->gateway,
    'transaction_id' => $payment->transaction_id,
]);
```

---

## 7. Canais de Log

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'stderr'],
    ],

    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'days' => 14,
    ],

    // Canal específico para integrações
    'integrations' => [
        'driver' => 'daily',
        'path' => storage_path('logs/integrations.log'),
        'days' => 30,
    ],

    // Canal para auditoria
    'audit' => [
        'driver' => 'daily',
        'path' => storage_path('logs/audit.log'),
        'days' => 90,
    ],
],
```

```php
// Uso de canal específico
Log::channel('integrations')->error('Payment gateway error', [...]);
Log::channel('audit')->info('User updated order', [...]);
```

---

## 8. Error Handling em Filament

### Em Actions

```php
use Filament\Actions\Action;
use Filament\Notifications\Notification;

Action::make('approve')
    ->action(function (Order $record) {
        try {
            app(OrderService::class)->approve($record);

            Notification::make()
                ->title(__('orders.messages.approved'))
                ->success()
                ->send();
        } catch (BusinessException $e) {
            Notification::make()
                ->title($e->getUserMessage())
                ->danger()
                ->send();
        }
    })
```

### Em Services Chamados pelo Filament

```php
// O Service lança exceção
public function approve(Order $order): Order
{
    if (! $order->canBeApproved()) {
        throw OrderException::invalidTransition($order->status, 'approved');
    }

    // ...
}

// O Resource/Page captura e mostra ao usuário
```

---

## 9. Traduções de Erros

```php
// lang/pt_BR/errors.php
return [
    'generic' => 'Ocorreu um erro. Tente novamente.',
    'not_found' => 'Recurso não encontrado.',
    'unauthorized' => 'Você não tem permissão para esta ação.',
    'validation' => 'Os dados informados são inválidos.',
    'server' => 'Erro interno do servidor.',
];

// lang/en/errors.php
return [
    'generic' => 'An error occurred. Please try again.',
    'not_found' => 'Resource not found.',
    'unauthorized' => 'You are not authorized for this action.',
    'validation' => 'The provided data is invalid.',
    'server' => 'Internal server error.',
];
```

---

## 10. Checklist

- [ ] Exceções de negócio estendem `BusinessException`
- [ ] Static factory methods para cada cenário de erro
- [ ] `getUserMessage()` retorna mensagem segura para UI
- [ ] Exceções de integração usam `IntegrationException`
- [ ] Logging sempre estruturado (array de contexto)
- [ ] Logs de negócio em `info` (pedido criado, pagamento)
- [ ] Logs de erro em `error` com trace
- [ ] Handler global configurado em `bootstrap/app.php`
- [ ] Filament Actions com try/catch + Notification
- [ ] Traduções em `lang/{locale}/errors.php`
