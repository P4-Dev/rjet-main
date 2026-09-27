<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CnabFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CnabFileObserver
{
    /**
     * The physical file is removed only after commit, so a rolled back force delete keeps it.
     */
    public function forceDeleted(CnabFile $file): void
    {
        if ($file->disk === null || $file->path === null) {
            return;
        }

        $disk = $file->disk;
        $path = $file->path;

        DB::afterCommit(function () use ($disk, $path): void {
            $storage = Storage::disk($disk);

            if ($storage->exists($path)) {
                $storage->delete($path);
            }
        });
    }
}
