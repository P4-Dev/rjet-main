<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CostCenterException;
use App\Models\CostCenter;

final class CostCenterService
{
    /**
     * @throws CostCenterException
     */
    public function delete(CostCenter $costCenter): void
    {
        $this->ensureDeletable($costCenter);

        $costCenter->delete();
    }

    /**
     * @throws CostCenterException
     */
    public function ensureDeletable(CostCenter $costCenter): void
    {
        if ($costCenter->paymentRequests()->exists()) {
            throw CostCenterException::cannotDeleteWithPaymentRequests((string) $costCenter->getKey());
        }
    }
}
