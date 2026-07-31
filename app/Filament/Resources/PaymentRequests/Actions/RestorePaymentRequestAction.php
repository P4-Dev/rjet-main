<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Models\PaymentRequest;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;

final class RestorePaymentRequestAction
{
    public static function make(): RestoreAction
    {
        return RestoreAction::make()
            ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
            ->before(function (PaymentRequest $record): void {
                abort_unless(
                    Filament::auth()->user()?->can('restore', $record) ?? false,
                    403,
                );
            });
    }
}
