<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalyticalReports\Schemas;

use App\Enums\PaymentRequestStatus;
use App\Enums\ReportDateBasis;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class AnalyticalReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * @return list<Component>
     */
    public static function components(): array
    {
        return [
            Select::make('date_basis')
                ->label(__('analytical_reports.fields.date_basis'))
                ->options(ReportDateBasis::class)
                ->default(ReportDateBasis::DueDate)
                ->native(false)
                ->required(),
            DatePicker::make('period_start')
                ->label(__('analytical_reports.fields.period_start'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(fn (): string => today(config('app.timezone'))->startOfMonth()->toDateString())
                ->required(),
            DatePicker::make('period_end')
                ->label(__('analytical_reports.fields.period_end'))
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(fn (): string => today(config('app.timezone'))->endOfMonth()->toDateString())
                ->required()
                ->afterOrEqual('period_start'),
            Select::make('company_id')
                ->label(__('analytical_reports.fields.company_id'))
                ->options(fn (): array => Company::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('branch_id', null))
                ->placeholder(__('analytical_reports.fields.all'))
                ->nullable(),
            Select::make('branch_id')
                ->label(__('analytical_reports.fields.branch_id'))
                ->options(fn (Get $get): array => self::branchOptions($get('company_id')))
                ->searchable()
                ->native(false)
                ->placeholder(__('analytical_reports.fields.all'))
                ->nullable(),
            Select::make('statuses')
                ->label(__('analytical_reports.fields.statuses'))
                ->multiple()
                ->options(PaymentRequestStatus::class)
                ->placeholder(__('analytical_reports.fields.all_statuses'))
                ->nullable(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function branchOptions(mixed $companyId): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return Branch::query()
            ->visibleTo($user)
            ->when(filled($companyId), fn (Builder $q): Builder => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
