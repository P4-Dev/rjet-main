<?php

declare(strict_types=1);

namespace App\Actions\Cnab;

use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\CnabFileService;

final class RequestCnabFileGenerationAction
{
    public function __construct(
        private readonly CnabFileService $fileService,
    ) {}

    /**
     * @throws PaymentSettlementException
     * @throws CnabException
     */
    public function __invoke(PaymentSettlement $settlement, User $actor): CnabFile
    {
        if (! $actor->can('generateCnab', $settlement)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->fileService->request($settlement, $actor);
    }
}
