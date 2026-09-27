<?php

declare(strict_types=1);

namespace App\Actions\Cnab;

use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Models\CnabFile;
use App\Models\User;
use App\Services\CnabFileService;

final class RetryCnabFileGenerationAction
{
    public function __construct(
        private readonly CnabFileService $fileService,
    ) {}

    /**
     * @throws PaymentSettlementException
     * @throws CnabException
     */
    public function __invoke(CnabFile $file, User $actor): CnabFile
    {
        if (! $actor->can('retry', $file)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->fileService->retry($file, $actor);
    }
}
