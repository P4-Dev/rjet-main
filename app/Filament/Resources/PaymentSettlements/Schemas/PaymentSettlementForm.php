<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Schemas;

use App\Filament\Resources\CnabConfigs\Schemas\CnabConfigForm;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\PaymentSettlement;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

final class PaymentSettlementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::components());
    }

    /**
     * Used by the settlement bulk action modal; schema closures cannot inject `$records`, so the
     * selected payment requests are read from the mounted bulk action.
     *
     * @return array<int, mixed>
     */
    public static function components(): array
    {
        return [
            DatePicker::make('settlement_date')
                ->label(__('payment_settlements.fields.settlement_date'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(fn (): string => today(PaymentSettlement::TIMEZONE)->toDateString())
                ->required()
                ->helperText(__('payment_settlements.help.settlement_date'))
                ->live(),
            Select::make('branch_bank_account_id')
                ->label(__('payment_settlements.fields.branch_bank_account'))
                ->options(fn (mixed $livewire): array => self::accountOptions(self::selectedRecords($livewire)))
                ->default(fn (mixed $livewire): ?string => self::defaultAccountId(self::selectedRecords($livewire)))
                ->required(fn (mixed $livewire): bool => self::singleBranchId(self::selectedRecords($livewire)) !== null)
                ->native(false)
                ->live()
                ->hint(fn (Get $get): ?string => match (self::hasActiveCnabConfig($get('branch_bank_account_id'))) {
                    null => null,
                    true => __('payment_settlements.hints.cnab_available'),
                    false => __('payment_settlements.hints.cnab_unavailable'),
                })
                ->hintColor(fn (Get $get): ?string => match (self::hasActiveCnabConfig($get('branch_bank_account_id'))) {
                    null => null,
                    true => 'success',
                    false => 'warning',
                }),
            Textarea::make('notes')
                ->label(__('common.fields.notes'))
                ->rows(3)
                ->maxLength(1000),
        ];
    }

    public static function singleBranchId(?Collection $records): ?string
    {
        $branchIds = $records?->pluck('branch_id')->map(fn (mixed $id): string => (string) $id)->unique();

        return $branchIds !== null && $branchIds->count() === 1 ? $branchIds->first() : null;
    }

    private static function selectedRecords(mixed $livewire): ?Collection
    {
        $action = $livewire instanceof HasActions && method_exists($livewire, 'getMountedAction')
            ? $livewire->getMountedAction()
            : null;

        if ($action === null || ! $action->isBulk()) {
            return null;
        }

        return collect($action->getSelectedRecords()->all());
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(?Collection $records): array
    {
        $branchId = self::singleBranchId($records);

        if ($branchId === null) {
            return [];
        }

        return BranchBankAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereNotNull('bank_id')
            ->orderByDesc('is_default')
            ->orderBy('bank_code')
            ->get()
            ->mapWithKeys(fn (BranchBankAccount $account): array => [
                (string) $account->getKey() => CnabConfigForm::accountLabel($account),
            ])
            ->all();
    }

    private static function defaultAccountId(?Collection $records): ?string
    {
        $branchId = self::singleBranchId($records);

        if ($branchId === null) {
            return null;
        }

        $accountId = BranchBankAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->whereNotNull('bank_id')
            ->value('id');

        return $accountId !== null ? (string) $accountId : null;
    }

    private static function hasActiveCnabConfig(mixed $accountId): ?bool
    {
        if (blank($accountId)) {
            return null;
        }

        return CnabConfig::query()->active()->forAccount((string) $accountId)->exists();
    }
}
