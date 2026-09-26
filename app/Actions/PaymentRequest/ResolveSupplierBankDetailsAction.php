<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequest;

use App\Models\Supplier;

final class ResolveSupplierBankDetailsAction
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(string $supplierId): ?array
    {
        $supplier = Supplier::query()->with('bankDetails')->find($supplierId);

        return $supplier?->bankDetails?->toPaymentRequestFormState();
    }
}
