<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Models\PaymentRequest;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

final class PaymentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('payment_requests.sections.identification'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('branch.company.name')
                            ->label(__('payment_requests.fields.company')),
                        TextEntry::make('branch.name')
                            ->label(__('payment_requests.fields.branch_id')),
                        TextEntry::make('supplier.name')
                            ->label(__('payment_requests.fields.supplier_id')),
                        TextEntry::make('costCenter.name')
                            ->label(__('payment_requests.fields.cost_center_id')),
                        TextEntry::make('appropriation.name')
                            ->label(__('payment_requests.fields.appropriation_id'))
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->label(__('common.fields.notes'))
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),

                Section::make(__('payment_requests.sections.amounts'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('gross_amount')
                            ->label(__('payment_requests.fields.gross_amount'))
                            ->money('BRL'),
                        TextEntry::make('discount_amount')
                            ->label(__('payment_requests.fields.discount_amount'))
                            ->money('BRL'),
                        TextEntry::make('net_amount')
                            ->label(__('payment_requests.fields.net_amount'))
                            ->money('BRL')
                            ->weight(FontWeight::Bold),
                        TextEntry::make('due_date')
                            ->label(__('payment_requests.fields.due_date'))
                            ->date('d/m/Y'),
                    ]),

                Section::make(__('payment_requests.sections.payment'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payment_method')
                            ->label(__('payment_requests.fields.payment_method'))
                            ->badge(),
                        TextEntry::make('status')
                            ->label(__('common.fields.status'))
                            ->badge(),
                    ]),

                Section::make(__('payment_requests.sections.approval'))
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('approval_state')
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
                        TextEntry::make('pending_approver')
                            ->label(__('payment_requests.fields.approver'))
                            ->state(fn (PaymentRequest $record): ?string => $record->currentPendingApproval()?->approver?->name)
                            ->placeholder('—'),
                        TextEntry::make('pending_due_at')
                            ->label(__('payment_requests.fields.due_at'))
                            ->state(fn (PaymentRequest $record) => $record->currentPendingApproval()?->due_at)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('pending_escalated_at')
                            ->label(__('payment_requests.fields.escalated_at'))
                            ->state(fn (PaymentRequest $record) => $record->currentPendingApproval()?->escalated_at
                                ?? $record->latestApproval()?->escalated_at)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('latest_reason')
                            ->label(__('approvals.fields.reason'))
                            ->state(fn (PaymentRequest $record): ?string => $record->latestApproval()?->reason)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        IconEntry::make('has_approved_for_launch')
                            ->label(__('payment_requests.fields.has_approved_for_launch'))
                            ->boolean()
                            ->state(fn (PaymentRequest $record): bool => $record->hasApprovedForLaunch()),
                    ]),

                Section::make(__('payment_requests.sections.settlement'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('bankDetails.deposit_type')
                            ->label(__('payment_request_bank_details.fields.deposit_type'))
                            ->badge()
                            ->visible(fn (PaymentRequest $record): bool => $record->payment_method === PaymentMethod::Deposit),
                        TextEntry::make('bankDetails.digitable_line')
                            ->label(__('payment_request_bank_details.fields.digitable_line'))
                            ->copyable()
                            ->columnSpanFull()
                            ->visible(fn (PaymentRequest $record): bool => $record->payment_method === PaymentMethod::Boleto),
                        TextEntry::make('bankDetails.barcode')
                            ->label(__('payment_request_bank_details.fields.barcode'))
                            ->copyable()
                            ->visible(fn (PaymentRequest $record): bool => $record->payment_method === PaymentMethod::Boleto),
                        TextEntry::make('bankDetails.pix_key_type')
                            ->label(__('payment_request_bank_details.fields.pix_key_type'))
                            ->badge()
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Pix),
                        TextEntry::make('bankDetails.pix_key')
                            ->label(__('payment_request_bank_details.fields.pix_key'))
                            ->copyable()
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Pix),
                        TextEntry::make('bankDetails.pix_qr_code')
                            ->label(__('payment_request_bank_details.fields.pix_qr_code'))
                            ->copyable()
                            ->columnSpanFull()
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Pix),
                        TextEntry::make('bankDetails.holder_name')
                            ->label(__('payment_request_bank_details.fields.holder_name'))
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                        TextEntry::make('bankDetails.holder_document')
                            ->label(__('payment_request_bank_details.fields.holder_document'))
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                        TextEntry::make('bankDetails.bank.name')
                            ->label(__('payment_request_bank_details.fields.bank_id'))
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                        TextEntry::make('bankDetails.agency')
                            ->label(__('payment_request_bank_details.fields.agency'))
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                        TextEntry::make('bankDetails.account_number')
                            ->label(__('payment_request_bank_details.fields.account_number'))
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                        TextEntry::make('bankDetails.account_type')
                            ->label(__('payment_request_bank_details.fields.account_type'))
                            ->badge()
                            ->visible(fn (PaymentRequest $record): bool => $record->bankDetails?->deposit_type === DepositType::Transfer),
                    ]),

                Section::make(__('common.sections.attachments'))
                    ->columns(1)
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->label(__('common.sections.attachments'))
                            ->schema([
                                TextEntry::make('original_name')
                                    ->label(__('attachments.fields.original_name')),
                                TextEntry::make('type')
                                    ->label(__('attachments.fields.type'))
                                    ->badge(),
                                TextEntry::make('mime_type')
                                    ->label(__('attachments.fields.mime_type')),
                                TextEntry::make('size')
                                    ->label(__('attachments.fields.size'))
                                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 0, ',', '.').' KB'),
                            ])
                            ->columns(4)
                            ->placeholder(__('payment_requests.messages.no_attachments')),
                    ]),

                Section::make(__('common.sections.history'))
                    ->columns(1)
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('statusHistories')
                            ->label(__('common.sections.history'))
                            ->schema([
                                TextEntry::make('from_status')
                                    ->label(__('payment_request_status_history.fields.from_status'))
                                    ->badge()
                                    ->placeholder('—'),
                                TextEntry::make('to_status')
                                    ->label(__('payment_request_status_history.fields.to_status'))
                                    ->badge(),
                                TextEntry::make('changedBy.name')
                                    ->label(__('payment_request_status_history.fields.changed_by'))
                                    ->placeholder('—'),
                                TextEntry::make('created_at')
                                    ->label(__('payment_request_status_history.fields.created_at'))
                                    ->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(4),
                    ]),

                Section::make(__('common.sections.audit'))
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('creator.name')
                            ->label(__('common.fields.created_by'))
                            ->placeholder('—'),
                        TextEntry::make('editor.name')
                            ->label(__('common.fields.updated_by'))
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('common.fields.created_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('common.fields.updated_at'))
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('deleted_at')
                            ->label(__('common.fields.deleted_at'))
                            ->dateTime('d/m/Y H:i')
                            ->visible(fn (PaymentRequest $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
