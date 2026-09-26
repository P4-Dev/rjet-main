<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Exceptions\ImportException;
use App\Models\ImportBatch;
use App\Models\ImportTemplateVersion;
use App\Models\User;
use App\Services\ImportBatchService;
use Illuminate\Http\UploadedFile;

final class StartImportBatchAction
{
    public function __construct(
        private readonly ImportBatchService $importBatchService,
    ) {}

    /**
     * @throws ImportException
     */
    public function __invoke(UploadedFile $file, ImportTemplateVersion $version, User $actor): ImportBatch
    {
        return $this->importBatchService->start($file, $version, $actor);
    }
}
