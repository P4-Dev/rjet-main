<?php

declare(strict_types=1);

namespace App\Actions\Cnab;

use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Models\CnabFile;
use App\Models\User;
use App\Services\CnabFileService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadCnabFileAction
{
    public function __construct(
        private readonly CnabFileService $fileService,
    ) {}

    /**
     * @throws PaymentSettlementException
     * @throws CnabException
     */
    public function __invoke(CnabFile $file, User $actor, ?string $ipAddress = null): StreamedResponse
    {
        if (! $actor->can('download', $file)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->fileService->download($file, $actor, $ipAddress);
    }
}
