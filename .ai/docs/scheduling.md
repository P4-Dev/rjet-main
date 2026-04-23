# Scheduling e Artisan Commands - Guideline

> **Regras para agendamento de tarefas e criação de Artisan Commands no Laravel 12.**

---

## 1. Regra Fundamental

No **Laravel 12**, o agendamento de tarefas é configurado em `routes/console.php` -- **NÃO** existe mais `app/Console/Kernel.php`.

| Componente | Responsabilidade |
|-----------|-----------------|
| `routes/console.php` | Definir o schedule (frequências, constraints) |
| Artisan Command | Lógica interativa, formatação de output, ponto de entrada |
| Job | Processamento assíncrono, retries, filas |
| Service | Lógica de negócio reutilizável |

### Princípio

```
routes/console.php  →  Artisan Command (thin)  →  Service / Job (lógica real)
```

Commands devem ser **finos**: delegam para Services ou Jobs. O schedule apenas **orquestra** quando cada command executa.

---

## 2. Artisan Commands

### Criação

```bash
php artisan make:command SendWeeklyReport --no-interaction
```

### Estrutura

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;

final class SendWeeklyReport extends Command
{
    /**
     * Signature com argumentos e opções tipados.
     */
    protected $signature = 'report:weekly
        {--team= : ID do time (opcional, envia para todos se omitido)}
        {--dry-run : Simula o envio sem disparar emails}';

    protected $description = 'Envia o relatório semanal para os times';

    public function handle(ReportService $reportService): int
    {
        $teamId = $this->option('team')
            ? (int) $this->option('team')
            : null;

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('Modo dry-run ativado. Nenhum email será enviado.');
        }

        $count = $reportService->sendWeekly($teamId, $isDryRun);

        $this->info("Relatórios enviados: {$count}");

        return self::SUCCESS;
    }
}
```

### Regras para Commands

1. **Auto-registro**: No Laravel 12, commands em `app/Console/Commands/` são descobertos automaticamente. Nenhum registro manual necessário.
2. **Dependency Injection**: Use type-hint no `handle()` -- o container resolve automaticamente.
3. **Return codes**: Sempre retorne `self::SUCCESS` (0), `self::FAILURE` (1) ou `self::INVALID` (2).
4. **Command fino**: Delegue lógica para Service ou Job. O command apenas recebe input e formata output.
5. **Output formatado**: Use `$this->info()`, `$this->warn()`, `$this->error()`, `$this->table()`.

### Output Formatado

```php
public function handle(UserService $userService): int
{
    $users = $userService->getInactiveUsers();

    if ($users->isEmpty()) {
        $this->warn('Nenhum usuário inativo encontrado.');

        return self::SUCCESS;
    }

    $this->table(
        ['ID', 'Nome', 'Último Login'],
        $users->map(fn ($user) => [
            $user->id,
            $user->name,
            $user->last_login_at?->diffForHumans() ?? 'Nunca',
        ]),
    );

    $this->info("Total: {$users->count()} usuários inativos.");

    return self::SUCCESS;
}
```

---

## 3. Schedule Configuration (Laravel 12)

O schedule é definido em `routes/console.php` usando a facade `Schedule`.

```php
<?php

// routes/console.php

use Illuminate\Support\Facades\Schedule;

// Tarefas agendadas
Schedule::command('report:weekly')
    ->weeklyOn(1, '08:00')           // Segunda às 08:00
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/report-weekly.log'));

Schedule::command('users:cleanup-inactive')
    ->dailyAt('02:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('cache:prune-stale-tags')
    ->hourly();

// Job direto no schedule (sem command intermediário)
Schedule::job(new PruneExpiredTokensJob)
    ->daily()
    ->timezone('America/Sao_Paulo');
```

### Regras de Configuração

1. **Sempre defina `timezone()`** explicitamente -- nunca dependa do timezone do servidor.
2. **`withoutOverlapping()`** obrigatório para tarefas que demoram mais de 1 minuto.
3. **`onOneServer()`** obrigatório em deploys multi-servidor (requer cache driver compartilhado: Redis, database).
4. **Não use closures** no schedule para lógica complexa -- crie um Command.

---

## 4. Frequências Comuns

| Método | Frequência | Caso de Uso |
|--------|-----------|-------------|
| `->everyMinute()` | A cada 1 min | Health checks, filas de prioridade alta |
| `->everyFiveMinutes()` | A cada 5 min | Sync de dados externos, polling de API |
| `->everyFifteenMinutes()` | A cada 15 min | Atualização de cache, métricas |
| `->hourly()` | A cada hora | Limpeza de temporários, aggregações |
| `->hourlyAt(17)` | A cada hora no min 17 | Distribuir carga (evitar minuto 0) |
| `->dailyAt('02:00')` | Diário às 02:00 | Backups, relatórios diários, pruning |
| `->twiceDaily(1, 13)` | Às 01:00 e 13:00 | Sync bi-diário |
| `->weeklyOn(1, '08:00')` | Segunda às 08:00 | Relatórios semanais |
| `->monthlyOn(1, '03:00')` | Dia 1 às 03:00 | Cobrança mensal, relatórios mensais |
| `->quarterly()` | Trimestral | Relatórios trimestrais |
| `->yearly()` | Anual | Renovações anuais |

### Constraints Adicionais

```php
Schedule::command('emails:send-promo')
    ->dailyAt('10:00')
    ->timezone('America/Sao_Paulo')
    ->weekdays()                      // Apenas dias úteis
    ->environments(['production']);    // Apenas em produção

Schedule::command('maintenance:optimize')
    ->sundays()
    ->at('04:00')
    ->timezone('America/Sao_Paulo')
    ->when(fn (): bool => config('app.env') === 'production');
```

---

## 5. Overlap Prevention

### `withoutOverlapping()`

Previne que a mesma tarefa execute em paralelo no mesmo servidor.

```php
Schedule::command('import:products')
    ->hourly()
    ->withoutOverlapping()              // Lock padrão: 24 horas
    ->withoutOverlapping(60);           // Lock expira em 60 minutos
```

O lock usa o **cache driver** configurado. Se o processo morrer inesperadamente, o lock expira após o tempo definido.

### `onOneServer()`

Em deploys com múltiplos servidores (load balancer), garante que apenas **um** servidor execute a tarefa.

```php
Schedule::command('report:daily')
    ->dailyAt('06:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer();                    // Requer cache compartilhado (Redis, database)
```

**Requisito**: O cache driver deve ser compartilhado entre servidores (Redis, database, Memcached). **Não funciona** com `file` ou `array`.

### Mutex

O Laravel usa um sistema de mutex para gerenciar locks. Para resolver locks travados:

```bash
# Listar tarefas agendadas e seus status
php artisan schedule:list

# Limpar locks manualmente (se necessário)
php artisan cache:clear
```

---

## 6. Output e Logging

### Redirecionar Output

```php
// Append ao arquivo de log (recomendado)
Schedule::command('import:products')
    ->hourly()
    ->appendOutputTo(storage_path('logs/import-products.log'));

// Sobrescrever log a cada execução
Schedule::command('import:products')
    ->hourly()
    ->sendOutputTo(storage_path('logs/import-products.log'));

// Enviar output por email (requer mail configurado)
Schedule::command('report:daily')
    ->dailyAt('06:00')
    ->emailOutputTo('admin@empresa.com.br');

// Email apenas se houver output
Schedule::command('cleanup:expired')
    ->daily()
    ->emailOutputOnFailure('devops@empresa.com.br');
```

### Callbacks

```php
Schedule::command('import:products')
    ->hourly()
    ->before(function (): void {
        logger()->info('Iniciando importação de produtos');
    })
    ->after(function (): void {
        logger()->info('Importação de produtos concluída');
    })
    ->onSuccess(function (): void {
        logger()->info('Importação bem-sucedida');
    })
    ->onFailure(function (): void {
        logger()->error('Importação falhou');
    });
```

### Logging Estruturado no Command

```php
public function handle(ProductImporter $importer): int
{
    $startTime = now();

    logger()->info('import:products started', [
        'timestamp' => $startTime->toIso8601String(),
    ]);

    try {
        $result = $importer->run();

        logger()->info('import:products completed', [
            'imported' => $result->imported,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
            'duration_seconds' => now()->diffInSeconds($startTime),
        ]);

        $this->info("Importação concluída: {$result->imported} importados, {$result->skipped} ignorados.");

        return self::SUCCESS;
    } catch (\Throwable $e) {
        logger()->error('import:products failed', [
            'error' => $e->getMessage(),
            'duration_seconds' => now()->diffInSeconds($startTime),
        ]);

        $this->error("Falha na importação: {$e->getMessage()}");

        return self::FAILURE;
    }
}
```

---

## 7. Health Checks

### Verificar Schedule

```bash
# Listar todas as tarefas agendadas
php artisan schedule:list

# Executar o scheduler manualmente (debug)
php artisan schedule:run

# Testar uma tarefa específica
php artisan schedule:test
```

### Monitorar Execuções Perdidas

```php
// routes/console.php

// Ping externo para monitoramento (Healthchecks.io, Oh Dear, etc.)
Schedule::command('import:products')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->pingBefore('https://hc-ping.com/uuid-da-tarefa/start')
    ->thenPing('https://hc-ping.com/uuid-da-tarefa')
    ->pingOnFailure('https://hc-ping.com/uuid-da-tarefa/fail');
```

### Health Check Interno

```php
Schedule::call(function (): void {
    $lastRun = cache()->get('scheduler:last_run');

    if ($lastRun && now()->diffInMinutes($lastRun) > 5) {
        logger()->warning('Scheduler may be stalled', [
            'last_run' => $lastRun->toIso8601String(),
            'gap_minutes' => now()->diffInMinutes($lastRun),
        ]);
    }

    cache()->put('scheduler:last_run', now(), 3600);
})->everyMinute();
```

---

## 8. Commands vs Jobs - Matriz de Decisão

| Critério | Artisan Command | Job (Queue) |
|---------|:--------------:|:-----------:|
| Execução via CLI / Schedule | ✅ | |
| Interação com usuário (prompts, tabelas) | ✅ | |
| Processamento assíncrono | | ✅ |
| Retry automático em falha | | ✅ |
| Disparado por evento do sistema | | ✅ |
| Rate limiting por job | | ✅ |
| Batch processing | | ✅ |
| Monitoramento via Horizon | | ✅ |

### Quando Usar Cada Um

```
Pergunta: "Precisa rodar em horário fixo e gerar output?"
  → Artisan Command (agendado no Schedule)

Pergunta: "Precisa rodar em background após uma ação do usuário?"
  → Job (dispatched da controller/service)

Pergunta: "Precisa rodar em horário fixo E em background?"
  → Command agenda o Job: Schedule::command() → Command::handle() → Job::dispatch()
```

### Exemplo: Command Dispara Job

```php
// O Command orquestra, o Job processa
final class ProcessDailyOrders extends Command
{
    protected $signature = 'orders:process-daily';

    protected $description = 'Processa pedidos do dia anterior';

    public function handle(): int
    {
        $orders = Order::query()
            ->where('status', 'pending')
            ->whereDate('created_at', now()->subDay())
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Nenhum pedido pendente.');

            return self::SUCCESS;
        }

        $orders->each(fn (Order $order) => ProcessOrderJob::dispatch($order));

        $this->info("Dispatched {$orders->count()} jobs para processamento.");

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
Schedule::command('orders:process-daily')
    ->dailyAt('01:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->onOneServer();
```

---

## 9. Docker / Supervisor

### Container de Cron

Em ambientes Docker, o scheduler precisa de um processo cron dedicado.

**Opção 1: Crontab no container**

```dockerfile
# Dockerfile (ou entrypoint)
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" \
    | crontab -
```

**Opção 2: Supervisor com `schedule:work`** (recomendado)

```ini
; /etc/supervisor/conf.d/scheduler.conf
[program:scheduler]
process_name=%(program_name)s
command=php /var/www/html/artisan schedule:work
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/scheduler.log
stopwaitsecs=3600
```

`schedule:work` executa o scheduler em foreground, rodando `schedule:run` a cada minuto. Ideal para containers Docker onde cron não é confiável.

### Supervisor para Worker de Filas (complementar)

```ini
; /etc/supervisor/conf.d/queue-worker.conf
[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker.log
stopwaitsecs=3600
```

### Docker Compose

```yaml
services:
  scheduler:
    build: .
    command: php artisan schedule:work
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - redis
    restart: unless-stopped

  queue-worker:
    build: .
    command: php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - redis
    restart: unless-stopped
```

---

## 10. Testes

### Testar Artisan Command

```php
<?php

use App\Models\User;

it('lists inactive users', function () {
    $inactive = User::factory()->create([
        'last_login_at' => now()->subMonths(3),
    ]);

    $active = User::factory()->create([
        'last_login_at' => now(),
    ]);

    $this->artisan('users:cleanup-inactive --dry-run')
        ->expectsOutput("Total: 1 usuários inativos.")
        ->assertSuccessful();
});

it('returns failure on error', function () {
    // Simular falha no service
    $this->mock(UserService::class)
        ->shouldReceive('getInactiveUsers')
        ->andThrow(new \RuntimeException('Database error'));

    $this->artisan('users:cleanup-inactive')
        ->assertFailed();
});
```

### Testar Output do Command

```php
<?php

use Illuminate\Support\Facades\Artisan;

it('displays table output', function () {
    User::factory()->count(3)->create([
        'last_login_at' => now()->subYear(),
    ]);

    Artisan::call('users:cleanup-inactive', ['--dry-run' => true]);

    $output = Artisan::output();

    expect($output)
        ->toContain('Total: 3 usuários inativos.');
});
```

### Testar Schedule

```php
<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules weekly report on Monday at 08:00', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command, 'report:weekly'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 8 * * 1')
        ->and($event->timezone)->toBe('America/Sao_Paulo')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules daily cleanup at 02:00', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())
        ->filter(fn ($event) => str_contains($event->command, 'users:cleanup-inactive'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 2 * * *');
});
```

### Testar Command que Dispara Job

```php
<?php

use App\Jobs\ProcessOrderJob;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;

it('dispatches jobs for pending orders', function () {
    Bus::fake();

    $pendingOrders = Order::factory()
        ->count(3)
        ->create([
            'status' => 'pending',
            'created_at' => now()->subDay(),
        ]);

    $this->artisan('orders:process-daily')
        ->expectsOutputToContain('Dispatched 3 jobs')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(ProcessOrderJob::class, 3);
});

it('does nothing when no pending orders', function () {
    Bus::fake();

    $this->artisan('orders:process-daily')
        ->expectsOutputToContain('Nenhum pedido pendente')
        ->assertSuccessful();

    Bus::assertNothingDispatched();
});
```

---

## 11. Checklist

- [ ] Schedule definido em `routes/console.php` (NÃO em Kernel)
- [ ] Commands auto-registrados em `app/Console/Commands/` (sem registro manual)
- [ ] Commands são finos: delegam para Service ou Job
- [ ] `handle()` usa dependency injection e retorna `self::SUCCESS` / `self::FAILURE`
- [ ] `timezone('America/Sao_Paulo')` definido explicitamente em cada tarefa
- [ ] `withoutOverlapping()` em tarefas que demoram mais de 1 minuto
- [ ] `onOneServer()` em deploys multi-servidor
- [ ] Cache driver compartilhado (Redis) para `onOneServer()` funcionar
- [ ] Logging estruturado no command (início, fim, duração, erros)
- [ ] Ping externo configurado para tarefas críticas (Healthchecks.io, etc.)
- [ ] Supervisor com `schedule:work` no Docker
- [ ] Testes cobrindo: output do command, schedule configurado, jobs dispatched
