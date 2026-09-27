<?php

declare(strict_types=1);

namespace App\Listeners\Cnab;

use App\Events\Cnab\CnabFileGenerationRequested;
use App\Jobs\Cnab\GenerateCnabFileJob;

final class QueueCnabFileGeneration
{
    public function handle(CnabFileGenerationRequested $event): void
    {
        GenerateCnabFileJob::dispatch($event->file);
    }
}
