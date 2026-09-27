<?php

declare(strict_types=1);

namespace App\Actions\Cnab;

use App\Services\CnabFileService;

final class RecoverStuckCnabFilesAction
{
    public function __construct(private readonly CnabFileService $cnabFileService) {}

    /**
     * @return array{requeued: int, failed: int}
     */
    public function __invoke(bool $dryRun = false): array
    {
        return $this->cnabFileService->recoverStuck($dryRun);
    }
}
