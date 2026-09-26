<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

final class ImportBatchObserver
{
    public function forceDeleted(ImportBatch $importBatch): void
    {
        if (blank($importBatch->path) || blank($importBatch->disk)) {
            return;
        }

        Storage::disk($importBatch->disk)->delete($importBatch->path);
    }
}
