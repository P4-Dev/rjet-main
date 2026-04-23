# Filas e Jobs - Guideline

> **Regras para processamento assíncrono com queues e jobs.**

---

## 1. Quando Usar Jobs

| Cenário | Usar Job? | Motivo |
|---------|-----------|--------|
| Enviar email/notificação | Sim | I/O externo, pode falhar |
| Processar upload de arquivo | Sim | Demorado |
| Gerar relatório/PDF | Sim | CPU intensivo |
| Integração com API externa | Sim | I/O externo, retry |
| Atualizar 1 campo no banco | Não | Instantâneo |
| Validar formulário | Não | Síncrono, rápido |
| Disparar evento interno | Depende | Se listener é pesado, sim |

---

## 2. Estrutura do Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de tentativas antes de falhar.
     */
    public int $tries = 3;

    /**
     * Timeout em segundos.
     */
    public int $timeout = 60;

    /**
     * Backoff entre tentativas (segundos).
     * Exponencial: 10s, 30s, 60s
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly Order $order,
    ) {}

    /**
     * Middleware do job.
     */
    public function middleware(): array
    {
        return [
            // Evita execução simultânea do mesmo order
            new WithoutOverlapping($this->order->id),
        ];
    }

    public function handle(): void
    {
        // Lógica do job
        $this->order->update(['status' => 'processing']);

        // ... processamento ...

        $this->order->update(['status' => 'completed']);
    }

    /**
     * Executado quando todas as tentativas falharam.
     */
    public function failed(\Throwable $exception): void
    {
        $this->order->update(['status' => 'failed']);

        logger()->error('ProcessOrderJob failed', [
            'order_id' => $this->order->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

---

## 3. Dispatch Patterns

### Dispatch Básico

```php
// Dispatch imediato (vai para a fila)
ProcessOrderJob::dispatch($order);

// Dispatch com delay
ProcessOrderJob::dispatch($order)->delay(now()->addMinutes(5));

// Dispatch para queue específica
ProcessOrderJob::dispatch($order)->onQueue('high');

// Dispatch síncrono (para testes/debug)
ProcessOrderJob::dispatchSync($order);
```

### Queues por Prioridade

```yaml
# Convenção de filas
queues:
  high: "Processamento urgente (pagamentos, auth)"
  default: "Processamento padrão (emails, notificações)"
  low: "Relatórios, cleanup, tarefas em background"
```

```php
// No Job
public $queue = 'high';

// Ou no dispatch
ProcessPaymentJob::dispatch($payment)->onQueue('high');
GenerateReportJob::dispatch($params)->onQueue('low');
```

---

## 4. Batch (Processamento em Lote)

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

$batch = Bus::batch([
    new ProcessOrderJob($order1),
    new ProcessOrderJob($order2),
    new ProcessOrderJob($order3),
])
->name('Process Orders Batch')
->then(function (Batch $batch) {
    // Todos concluídos com sucesso
    logger()->info("Batch {$batch->id} completed");
})
->catch(function (Batch $batch, \Throwable $e) {
    // Primeiro erro
    logger()->error("Batch {$batch->id} failed: {$e->getMessage()}");
})
->finally(function (Batch $batch) {
    // Sempre executado (sucesso ou falha)
})
->allowFailures()  // Continua mesmo se um job falhar
->onQueue('default')
->dispatch();
```

---

## 5. Chain (Sequência)

```php
use Illuminate\Support\Facades\Bus;

Bus::chain([
    new ValidateOrderJob($order),
    new ProcessPaymentJob($order),
    new SendConfirmationJob($order),
    new NotifyWarehouseJob($order),
])->onQueue('high')->dispatch();
```

---

## 6. Rate Limiting em Jobs

```php
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

// No AppServiceProvider
RateLimiter::for('api-requests', function ($job) {
    return Limit::perMinute(30);
});

// No Job
public function middleware(): array
{
    return [new RateLimited('api-requests')];
}

public function retryUntil(): \DateTime
{
    return now()->addHours(1);
}
```

---

## 7. Unique Jobs

```php
use Illuminate\Contracts\Queue\ShouldBeUnique;

final class GenerateReportJob implements ShouldQueue, ShouldBeUnique
{
    // Garante que apenas 1 instância roda por vez

    public int $uniqueFor = 3600; // Lock por 1h

    public function uniqueId(): string
    {
        return $this->report->id;
    }
}
```

---

## 8. Failed Jobs

### Configuração

```php
// config/queue.php
'failed' => [
    'driver' => 'database-uuids',
    'database' => env('DB_CONNECTION', 'mysql'),
    'table' => 'failed_jobs',
],
```

### Comandos

```bash
# Listar failed jobs
php artisan queue:failed

# Retry um job específico
php artisan queue:retry {uuid}

# Retry todos
php artisan queue:retry all

# Limpar failed jobs antigos
php artisan queue:prune-failed --hours=48
```

---

## 9. Horizon (Recomendado para Produção)

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'maxProcesses' => 10,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'queue' => ['high', 'default', 'low'],
        ],
    ],
    'local' => [
        'supervisor-1' => [
            'maxProcesses' => 3,
            'queue' => ['high', 'default', 'low'],
        ],
    ],
],
```

---

## 10. Testes de Jobs

```php
<?php

use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

describe('ProcessOrderJob', function () {
    it('dispatches when order is created', function () {
        Bus::fake();

        $order = Order::factory()->create();

        Bus::assertDispatched(ProcessOrderJob::class, function ($job) use ($order) {
            return $job->order->id === $order->id;
        });
    });

    it('processes order successfully', function () {
        $order = Order::factory()->create(['status' => 'pending']);

        ProcessOrderJob::dispatchSync($order);

        expect($order->fresh()->status)->toBe('completed');
    });

    it('handles failure', function () {
        Queue::fake();

        $order = Order::factory()->create();

        $job = new ProcessOrderJob($order);
        $job->failed(new \Exception('Test error'));

        expect($order->fresh()->status)->toBe('failed');
    });
});
```

---

## 11. Checklist

- [ ] Job implementa `ShouldQueue`
- [ ] `$tries`, `$timeout`, `$backoff` definidos
- [ ] `failed()` implementado para tratamento de erro
- [ ] Middleware (`WithoutOverlapping`, `RateLimited`) quando necessário
- [ ] `ShouldBeUnique` para jobs que não podem duplicar
- [ ] Queue correta atribuída (`high`, `default`, `low`)
- [ ] Testes criados (dispatch + execução + falha)
- [ ] Se Docker: worker configurado no Supervisor
