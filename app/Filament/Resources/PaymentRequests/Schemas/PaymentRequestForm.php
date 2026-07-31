<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Actions\PaymentRequest\ExtractBoletoDataAction;
use App\Actions\PaymentRequest\ResolveSupplierPaymentMethodAction;
use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PixKeyType;
use App\Models\Appropriation;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Rules\ValidCpfOrCnpj;
use App\Rules\ValidDigitableLine;
use App\Services\PaymentRequestService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class PaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('payment_requests.sections.identification'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('payment_requests.fields.branch_id'))
                            ->relationship(
                                name: 'branch',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->active()
                                    ->visibleTo(Filament::auth()->user())
                                    ->with('company'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Branch $record): string => "{$record->company->name} / {$record->name}"
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->default(fn (): ?string => self::defaultBranchId())
                            ->disabled(fn (): bool => self::shouldLockBranch())
                            ->dehydrated(true)
                            ->afterStateUpdated(function (Set $set): void {
                                $set('cost_center_id', null);
                                $set('appropriation_id', null);
                            }),

                        Select::make('supplier_id')
                            ->label(__('payment_requests.fields.supplier_id'))
                            ->relationship(
                                name: 'supplier',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->active()->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                if (blank($state)) {
                                    return;
                                }

                                $suggested = app(ResolveSupplierPaymentMethodAction::class)($state, $get('branch_id'));

                                if ($suggested !== null) {
                                    $set('payment_method', $suggested->value);
                                }
                            }),

                        Select::make('cost_center_id')
                            ->label(__('payment_requests.fields.cost_center_id'))
                            ->options(fn (Get $get): array => blank($get('branch_id'))
                                ? []
                                : CostCenter::query()
                                    ->active()
                                    ->forBranch($get('branch_id'))
                                    ->orderBy('sort_order')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('branch_id')))
                            ->dehydrated(true)
                            ->helperText(fn (Get $get): ?string => blank($get('branch_id'))
                                ? __('payment_requests.hints.select_branch_first')
                                : null),

                        Select::make('appropriation_id')
                            ->label(__('payment_requests.fields.appropriation_id'))
                            ->options(fn (Get $get): array => blank($get('branch_id'))
                                ? []
                                : Appropriation::query()
                                    ->active()
                                    ->forCompany(Branch::query()->whereKey($get('branch_id'))->value('company_id'))
                                    ->orderBy('sort_order')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => PaymentRequest::requiresAppropriationForBranch($get('branch_id')))
                            ->disabled(fn (Get $get): bool => blank($get('branch_id')))
                            ->dehydrated(true)
                            ->helperText(fn (Get $get): ?string => PaymentRequest::requiresAppropriationForBranch($get('branch_id'))
                                ? __('payment_requests.hints.appropriation_required')
                                : __('payment_requests.hints.appropriation_optional')),

                        Textarea::make('notes')
                            ->label(__('common.fields.notes'))
                            ->rows(3)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('payment_requests.sections.amounts'))
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->columns(2)
                    ->schema([
                        TextInput::make('gross_amount')
                            ->label(__('payment_requests.fields.gross_amount'))
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0.01)
                            ->prefix('R$')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculateNetAmount($get, $set);
                            }),

                        TextInput::make('discount_amount')
                            ->label(__('payment_requests.fields.discount_amount'))
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0)
                            ->prefix('R$')
                            ->required()
                            ->lte('gross_amount')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::recalculateNetAmount($get, $set);
                            }),

                        TextInput::make('net_amount')
                            ->label(__('payment_requests.fields.net_amount'))
                            ->numeric()
                            ->prefix('R$')
                            ->required()
                            ->readOnly()
                            ->dehydrated(true)
                            ->helperText(__('payment_requests.hints.net_amount_auto')),

                        DatePicker::make('due_date')
                            ->label(__('payment_requests.fields.due_date'))
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ]),

                Section::make(__('payment_requests.sections.payment'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->columns(1)
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('payment_method')
                                ->label(__('payment_requests.fields.payment_method'))
                                ->options(PaymentMethod::class)
                                ->required()
                                ->native(false)
                                ->live()
                                ->afterStateUpdated(function (PaymentMethod|string|null $state, Set $set): void {
                                    $method = $state instanceof PaymentMethod ? $state->value : $state;

                                    if ($method !== PaymentMethod::Boleto->value) {
                                        $set('bankDetails.digitable_line', null);
                                        $set('bankDetails.barcode', null);
                                    }

                                    if ($method !== PaymentMethod::Deposit->value) {
                                        $set('bankDetails.deposit_type', null);
                                        $set('bankDetails.pix_key_type', null);
                                        $set('bankDetails.pix_key', null);
                                        $set('bankDetails.pix_qr_code', null);
                                        $set('bankDetails.holder_document', null);
                                        $set('bankDetails.holder_name', null);
                                        $set('bankDetails.bank_id', null);
                                        $set('bankDetails.agency', null);
                                        $set('bankDetails.agency_digit', null);
                                        $set('bankDetails.account_number', null);
                                        $set('bankDetails.account_digit', null);
                                        $set('bankDetails.account_type', null);
                                    }
                                }),
                        ]),

                        Section::make(__('payment_requests.sections.settlement'))
                            ->description(__('payment_requests.hints.settlement'))
                            ->columns(2)
                            ->relationship('bankDetails')
                            ->schema([
                                Select::make('deposit_type')
                                    ->label(__('payment_request_bank_details.fields.deposit_type'))
                                    ->options(DepositType::class)
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isDeposit($get))
                                    ->required(fn (Get $get): bool => self::isDeposit($get))
                                    ->afterStateUpdated(function (DepositType|string|null $state, Set $set): void {
                                        $type = $state instanceof DepositType ? $state->value : $state;

                                        if ($type !== DepositType::Pix->value) {
                                            $set('pix_key_type', null);
                                            $set('pix_key', null);
                                            $set('pix_qr_code', null);
                                        }

                                        if ($type !== DepositType::Transfer->value) {
                                            $set('holder_document', null);
                                            $set('holder_name', null);
                                            $set('bank_id', null);
                                            $set('agency', null);
                                            $set('agency_digit', null);
                                            $set('account_number', null);
                                            $set('account_digit', null);
                                            $set('account_type', null);
                                        }
                                    }),

                                TextInput::make('digitable_line')
                                    ->label(__('payment_request_bank_details.fields.digitable_line'))
                                    ->maxLength(54)
                                    ->visible(fn (Get $get): bool => self::isBoleto($get))
                                    ->required(fn (Get $get): bool => self::isBoleto($get))
                                    ->rule(new ValidDigitableLine)
                                    ->helperText(__('payment_requests.hints.digitable_line'))
                                    ->columnSpanFull(),

                                TextInput::make('barcode')
                                    ->label(__('payment_request_bank_details.fields.barcode'))
                                    ->maxLength(48)
                                    ->visible(fn (Get $get): bool => self::isBoleto($get))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null),

                                Select::make('pix_key_type')
                                    ->label(__('payment_request_bank_details.fields.pix_key_type'))
                                    ->options(PixKeyType::class)
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isPix($get))
                                    ->required(fn (Get $get): bool => self::isPix($get) && blank($get('pix_qr_code')))
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('pix_key', null)),

                                TextInput::make('pix_key')
                                    ->label(__('payment_request_bank_details.fields.pix_key'))
                                    ->maxLength(100)
                                    ->visible(fn (Get $get): bool => self::isPix($get))
                                    ->required(fn (Get $get): bool => self::isPix($get) && blank($get('pix_qr_code')))
                                    ->rules(fn (Get $get): array => $get->enum('pix_key_type', PixKeyType::class, isNullable: true)?->validationRules() ?? [])
                                    ->dehydrateStateUsing(function (?string $state, Get $get): ?string {
                                        $type = $get->enum('pix_key_type', PixKeyType::class, isNullable: true);

                                        return in_array($type, [PixKeyType::Cpf, PixKeyType::Phone], true)
                                            ? (self::onlyDigits($state) ?: null)
                                            : $state;
                                    }),

                                Textarea::make('pix_qr_code')
                                    ->label(__('payment_request_bank_details.fields.pix_qr_code'))
                                    ->rows(3)
                                    ->visible(fn (Get $get): bool => self::isPix($get))
                                    ->required(fn (Get $get): bool => self::isPix($get) && (blank($get('pix_key_type')) || blank($get('pix_key'))))
                                    ->helperText(__('payment_requests.hints.pix_qr_code'))
                                    ->columnSpanFull(),

                                TextInput::make('holder_document')
                                    ->label(__('payment_request_bank_details.fields.holder_document'))
                                    ->maxLength(20)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->rule(new ValidCpfOrCnpj)
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null)
                                    ->helperText(__('payment_requests.hints.holder_document')),

                                TextInput::make('holder_name')
                                    ->label(__('payment_request_bank_details.fields.holder_name'))
                                    ->maxLength(150)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get)),

                                Select::make('bank_id')
                                    ->label(__('payment_request_bank_details.fields.bank_id'))
                                    ->relationship(
                                        name: 'bank',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query): Builder => $query->active()->orderBy('name'),
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Bank $record): string => "{$record->code} - {$record->name}"
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),

                                TextInput::make('agency')
                                    ->label(__('payment_request_bank_details.fields.agency'))
                                    ->maxLength(10)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null),

                                TextInput::make('agency_digit')
                                    ->label(__('payment_request_bank_details.fields.agency_digit'))
                                    ->maxLength(2)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get)),

                                TextInput::make('account_number')
                                    ->label(__('payment_request_bank_details.fields.account_number'))
                                    ->maxLength(20)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get))
                                    ->dehydrateStateUsing(fn (?string $state): ?string => self::onlyDigits($state) ?: null),

                                TextInput::make('account_digit')
                                    ->label(__('payment_request_bank_details.fields.account_digit'))
                                    ->maxLength(2)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),

                                Select::make('account_type')
                                    ->label(__('payment_request_bank_details.fields.account_type'))
                                    ->options(AccountType::class)
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => self::isTransfer($get))
                                    ->required(fn (Get $get): bool => self::isTransfer($get)),
                            ]),

                        FileUpload::make('attachment_files')
                            ->label(__('common.sections.attachments'))
                            ->multiple()
                            ->disk(fn (): string => (string) config('rjet.attachments.disk'))
                            ->directory('attachments/payment_request')
                            ->visibility('private')
                            ->maxSize(fn (): int => (int) config('rjet.attachments.max_kilobytes'))
                            ->maxFiles(fn (): int => (int) config('rjet.attachments.max_files'))
                            ->minFiles(fn (Get $get): ?int => self::isBoleto($get) ? 1 : null)
                            ->acceptedFileTypes(config('rjet.attachments.accepted_mime_types'))
                            ->required(fn (Get $get): bool => self::isBoleto($get))
                            ->downloadable()
                            ->openable()
                            ->previewable(false)
                            ->reorderable()
                            ->storeFileNamesIn('attachment_file_names')
                            ->hiddenOn('edit')
                            ->live()
                            ->columnSpanFull()
                            ->helperText(__('payment_requests.hints.attachments'))
                            ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                                if (! self::isBoleto($get) || blank($state)) {
                                    return;
                                }

                                $file = collect($state)->last();

                                if (! $file instanceof TemporaryUploadedFile) {
                                    return;
                                }

                                if ($file->getMimeType() !== 'application/pdf') {
                                    Notification::make()
                                        ->title(__('payment_requests.messages.ocr_image_not_supported'))
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $result = app(ExtractBoletoDataAction::class)->fromUploadedFile($file);

                                if (! $result->wasSuccessful) {
                                    Notification::make()
                                        ->title(__('payment_requests.messages.ocr_failed'))
                                        ->body($result->message)
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                if (blank($get('bankDetails.digitable_line')) && filled($result->digitableLine)) {
                                    $set('bankDetails.digitable_line', $result->digitableLine);
                                }

                                if (blank($get('gross_amount')) && filled($result->amount)) {
                                    $set('gross_amount', $result->amount);
                                    self::recalculateNetAmount($get, $set);
                                }

                                if (blank($get('due_date')) && $result->dueDate !== null) {
                                    $set('due_date', $result->dueDate->toDateString());
                                }

                                Notification::make()
                                    ->title(__('payment_requests.messages.ocr_succeeded'))
                                    ->success()
                                    ->send();
                            }),

                        Hidden::make('attachment_file_names')
                            ->dehydrated(true)
                            ->hiddenOn('edit'),
                    ]),
            ]);
    }

    private static function paymentMethod(Get $get): ?PaymentMethod
    {
        return $get->enum('../payment_method', PaymentMethod::class, isNullable: true)
            ?? $get->enum('payment_method', PaymentMethod::class, isNullable: true);
    }

    private static function depositType(Get $get): ?DepositType
    {
        return $get->enum('deposit_type', DepositType::class, isNullable: true);
    }

    private static function isBoleto(Get $get): bool
    {
        return self::paymentMethod($get) === PaymentMethod::Boleto;
    }

    private static function isDeposit(Get $get): bool
    {
        return self::paymentMethod($get) === PaymentMethod::Deposit;
    }

    private static function isPix(Get $get): bool
    {
        return self::isDeposit($get) && self::depositType($get) === DepositType::Pix;
    }

    private static function isTransfer(Get $get): bool
    {
        return self::isDeposit($get) && self::depositType($get) === DepositType::Transfer;
    }

    private static function recalculateNetAmount(Get $get, Set $set): void
    {
        $set('net_amount', app(PaymentRequestService::class)->calculateNetAmount(
            (string) ($get('gross_amount') ?? '0'),
            (string) ($get('discount_amount') ?? '0'),
        ));
    }

    private static function onlyDigits(?string $state): ?string
    {
        return $state === null ? null : preg_replace('/\D/', '', $state);
    }

    private static function defaultBranchId(): ?string
    {
        $user = Filament::auth()->user();

        if ($user === null || $user->role->seesAllBranches()) {
            return null;
        }

        return $user->defaultBranch()->value('branches.id')
            ?? ($user->branches()->count() === 1 ? $user->branches()->value('branches.id') : null);
    }

    private static function shouldLockBranch(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->isCliente() && $user->branches()->count() === 1;
    }
}
