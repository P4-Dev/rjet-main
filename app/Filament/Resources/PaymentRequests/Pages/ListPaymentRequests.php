<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $user = Filament::auth()->user();

        $tabs = [
            'all' => Tab::make(__('payment_requests.tabs.all')),
        ];

        if ($user?->canApprove()) {
            $tabs['awaiting_my_approval'] = Tab::make(__('payment_requests.tabs.awaiting_my_approval'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingApprovalFor($user))
                ->badge(fn (): int => PaymentRequest::query()
                    ->visibleTo($user)
                    ->awaitingApprovalFor($user)
                    ->count())
                ->badgeColor('warning');
        }

        if ($user?->isAdm()) {
            $tabs['all_pending_approvals'] = Tab::make(__('payment_requests.tabs.all_pending_approvals'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->awaitingAnyApproval())
                ->badge(fn (): int => PaymentRequest::query()->awaitingAnyApproval()->count())
                ->badgeColor('warning');
        }

        $tabs['returned'] = Tab::make(__('payment_requests.tabs.returned'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->returnedToRequester());

        return $tabs;
    }
}
