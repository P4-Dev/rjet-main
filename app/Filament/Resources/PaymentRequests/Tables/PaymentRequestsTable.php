<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Tables;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Filament\Resources\PaymentRequests\Actions\ApprovePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\DeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\ForceDeletePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\RejectPaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\RestorePaymentRequestAction;
use App\Filament\Resources\PaymentRequests\Actions\ResubmitForApprovalAction;
use App\Filament\Resources\PaymentRequests\Actions\SendForApprovalAction;
use App\Filament\Resources\PaymentRequests\Actions\TransitionStatusAction;
use App\Models\Company;
use App\Models\PaymentRequest;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PaymentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'branch.company',
                'supplier',
                'costCenter',
                'approvals',
                'bankDetails',
                'attachments',
            ]))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(15)
            ->striped()
            ->columns([
                TextColumn::make('branch.company.name')
                    ->label(__('payment_requests.fields.company'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('branch.name')
                    ->label(__('payment_requests.fields.branch_id'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label(__('payment_requests.fields.supplier_id'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('costCenter.name')
                    ->label(__('payment_requests.fields.cost_center_id'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label(__('common.fields.status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('approval_state')
                    ->label(__('payment_requests.fields.approval_state'))
                    ->badge()
                    ->state(fn (PaymentRequest $record): string => $record->approvalState())
                    ->formatStateUsing(fn (string $state): string => __("payment_requests.approval_states.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'awaiting' => 'warning',
                        'returned' => 'danger',
                        'approved_ready' => 'success',
                        'no_rule' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('payment_method')
                    ->label(__('payment_requests.fields.payment_method'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('net_amount')
                    ->label(__('payment_requests.fields.net_amount'))
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make()->money('BRL')->label(__('payment_requests.fields.net_amount'))),

                TextColumn::make('gross_amount')
                    ->label(__('payment_requests.fields.gross_amount'))
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('due_date')
                    ->label(__('payment_requests.fields.due_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                IconColumn::make('has_attachments')
                    ->label(__('payment_requests.fields.has_attachments'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('common.fields.created_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('common.fields.status'))
                    ->options(PaymentRequestStatus::class)
                    ->multiple(),

                Filter::make('awaiting_my_approval')
                    ->label(__('payment_requests.filters.awaiting_my_approval'))
                    ->query(function (Builder $query): Builder {
                        $user = Filament::auth()->user();

                        return $user !== null
                            ? $query->awaitingApprovalFor($user)
                            : $query->whereRaw('1 = 0');
                    })
                    ->visible(fn (): bool => Filament::auth()->user()?->canApprove() ?? false),

                SelectFilter::make('approval_status')
                    ->label(__('payment_requests.filters.approval_status'))
                    ->options(ApprovalStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereHas('approvals', function (Builder $q) use ($value): void {
                            $q->where('status', $value)
                                ->whereRaw('approvals.assigned_at = (
                                    SELECT MAX(a2.assigned_at) FROM approvals a2
                                    WHERE a2.payment_request_id = payment_requests.id
                                )');
                        });
                    }),

                SelectFilter::make('payment_method')
                    ->label(__('payment_requests.fields.payment_method'))
                    ->options(PaymentMethod::class),

                SelectFilter::make('branch')
                    ->label(__('payment_requests.fields.branch_id'))
                    ->relationship(
                        'branch',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->visibleTo(Filament::auth()->user()),
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('company')
                    ->label(__('payment_requests.fields.company'))
                    ->options(function (): array {
                        $user = Filament::auth()->user();
                        $query = Company::query()->active()->orderBy('name');

                        if ($user !== null && ! $user->role->seesAllBranches()) {
                            $query->whereHas(
                                'branches.users',
                                fn (Builder $query): Builder => $query->whereKey($user->getKey()),
                            );
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $companyId): Builder => $query->whereHas(
                            'branch',
                            fn (Builder $query): Builder => $query->where('company_id', $companyId),
                        ),
                    )),

                SelectFilter::make('supplier')
                    ->label(__('payment_requests.fields.supplier_id'))
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('due_date')
                    ->schema([
                        DatePicker::make('due_from')
                            ->label(__('payment_requests.filters.due_from'))
                            ->native(false),
                        DatePicker::make('due_until')
                            ->label(__('payment_requests.filters.due_until'))
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['due_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '>=', $date))
                        ->when($data['due_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        ($data['due_from'] ?? null) ? __('payment_requests.filters.due_from').': '.Carbon::parse($data['due_from'])->format('d/m/Y') : null,
                        ($data['due_until'] ?? null) ? __('payment_requests.filters.due_until').': '.Carbon::parse($data['due_until'])->format('d/m/Y') : null,
                    ])),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('payment_requests.filters.created_from'))
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label(__('payment_requests.filters.created_until'))
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_filter([
                        ($data['created_from'] ?? null) ? __('payment_requests.filters.created_from').': '.Carbon::parse($data['created_from'])->format('d/m/Y') : null,
                        ($data['created_until'] ?? null) ? __('payment_requests.filters.created_until').': '.Carbon::parse($data['created_until'])->format('d/m/Y') : null,
                    ])),

                TernaryFilter::make('has_attachments')
                    ->label(__('payment_requests.fields.has_attachments')),

                TrashedFilter::make()
                    ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ApprovePaymentRequestAction::make(),
                    RejectPaymentRequestAction::make(),
                    ResubmitForApprovalAction::make(),
                    SendForApprovalAction::make(),
                    TransitionStatusAction::make(),
                    DeletePaymentRequestAction::make(),
                    RestorePaymentRequestAction::make(),
                    ForceDeletePaymentRequestAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
                        ->before(function (DeleteBulkAction $action): void {
                            $user = Filament::auth()->user();

                            foreach ($action->getSelectedRecords() as $record) {
                                abort_unless(
                                    $record instanceof PaymentRequest
                                        && ($user?->can('delete', $record) ?? false),
                                    403,
                                );
                            }
                        }),
                    RestoreBulkAction::make()
                        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
                        ->before(function (RestoreBulkAction $action): void {
                            $user = Filament::auth()->user();

                            foreach ($action->getSelectedRecords() as $record) {
                                abort_unless(
                                    $record instanceof PaymentRequest
                                        && ($user?->can('restore', $record) ?? false),
                                    403,
                                );
                            }
                        }),
                    ForceDeleteBulkAction::make()
                        ->visible(fn (): bool => Filament::auth()->user()?->isAdm() ?? false)
                        ->before(function (ForceDeleteBulkAction $action): void {
                            $user = Filament::auth()->user();

                            foreach ($action->getSelectedRecords() as $record) {
                                abort_unless(
                                    $record instanceof PaymentRequest
                                        && ($user?->can('forceDelete', $record) ?? false),
                                    403,
                                );
                            }
                        }),
                ]),
            ]);
    }
}
