<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSettlements\Pages;

use App\Filament\Resources\PaymentSettlements\PaymentSettlementResource;
use App\Filament\Resources\PaymentSettlements\Tables\EligiblePaymentRequestsTable;
use App\Models\PaymentSettlement;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Settlement selection page (not a CreateRecord): pick eligible requests, then the bulk action creates the draft.
 */
final class CreatePaymentSettlement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PaymentSettlementResource::class;

    public static function canAccess(array $parameters = []): bool
    {
        return Filament::auth()->user()?->can('create', PaymentSettlement::class) ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function getTitle(): string
    {
        return __('payment_settlements.pages.create.title');
    }

    public function table(Table $table): Table
    {
        return EligiblePaymentRequestsTable::configure($table);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }
}
