<?php

declare(strict_types=1);

namespace App\Listeners\Cnab;

use App\Events\Cnab\CnabFileGenerationFailed;
use App\Notifications\CnabFileGenerationFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyCnabFileGenerationFailed implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(CnabFileGenerationFailed $event): void
    {
        $event->file->loadMissing('creator');
        $creator = $event->file->creator;

        if ($creator === null) {
            return;
        }

        $creator->notify(new CnabFileGenerationFailedNotification($event->file));
    }
}
