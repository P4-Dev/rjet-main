<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Actions;

use App\Models\PaymentRequest;
use Filament\Actions\ForceDeleteAction;
use Filament\Facades\Filament;

final class ForceDeletePaymentRequestAction
{
    public static function make(): ForceDeleteAction
    {
        return ForceDeleteAction::make()
            ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
            ->before(function (PaymentRequest $record): void {
                abort_unless(
                    Filament::auth()->user()?->can('forceDelete', $record) ?? false,
                    403,
                );
            });
    }
}
