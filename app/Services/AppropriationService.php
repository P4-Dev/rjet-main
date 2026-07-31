<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppropriationException;
use App\Models\Appropriation;

final class AppropriationService
{
    /**
     * @throws AppropriationException
     */
    public function delete(Appropriation $appropriation): void
    {
        $this->ensureDeletable($appropriation);

        $appropriation->delete();
    }

    /**
     * @throws AppropriationException
     */
    public function ensureDeletable(Appropriation $appropriation): void
    {
        if ($appropriation->paymentRequests()->exists()) {
            throw AppropriationException::cannotDeleteWithPaymentRequests((string) $appropriation->getKey());
        }
    }
}
