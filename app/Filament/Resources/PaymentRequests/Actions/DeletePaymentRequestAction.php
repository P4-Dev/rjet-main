<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;

final class DeletePaymentRequestAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
            ->using(function (PaymentRequest $record): void {
                abort_unless(
                    Filament::auth()->user()?->can('delete', $record) ?? false,
                    403,
                );

                app(PaymentRequestService::class)->delete($record);
            });
    }
}
