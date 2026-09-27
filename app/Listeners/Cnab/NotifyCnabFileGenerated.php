<?php

declare(strict_types=1);

namespace App\Listeners\Cnab;

use App\Events\Cnab\CnabFileGenerated;
use App\Notifications\CnabFileGeneratedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyCnabFileGenerated implements ShouldQueue
{
    public function handle(CnabFileGenerated $event): void
    {
        $event->file->loadMissing('creator');
        $creator = $event->file->creator;

        if ($creator === null) {
            return;
        }

        $creator->notify(new CnabFileGeneratedNotification($event->file));
    }
}
