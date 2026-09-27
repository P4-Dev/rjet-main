<?php

declare(strict_types=1);

namespace App\Listeners\Cnab;

use App\Events\Cnab\CnabFileDownloaded;
use App\Events\Cnab\CnabFileGenerated;
use App\Events\Cnab\CnabFileGenerationFailed;
use App\Models\CnabFile;
use Illuminate\Support\Facades\Log;

final class LogCnabFileActivity
{
    public function handleGenerated(CnabFileGenerated $event): void
    {
        Log::info('CNAB file generated.', [
            ...$this->context($event->file),
            'items_count' => $event->file->items_count,
            'total_amount' => (string) $event->file->total_amount,
            'checksum' => $event->file->checksum,
        ]);
    }

    public function handleFailed(CnabFileGenerationFailed $event): void
    {
        Log::warning('CNAB file generation failed.', [
            ...$this->context($event->file),
            'failure_reason' => $event->file->failure_reason,
        ]);
    }

    public function handleDownloaded(CnabFileDownloaded $event): void
    {
        Log::info('CNAB file downloaded.', [
            ...$this->context($event->file),
            'user_id' => $event->user->getKey(),
            'ip_address' => $event->ipAddress,
            'checksum' => $event->file->checksum,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(CnabFile $file): array
    {
        return [
            'cnab_file_id' => $file->getKey(),
            'payment_settlement_id' => $file->payment_settlement_id,
            'file_sequence' => $file->file_sequence,
        ];
    }
}
