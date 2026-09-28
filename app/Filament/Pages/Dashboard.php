<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ReportDateBasis;
use App\Filament\Resources\AnalyticalReports\Actions\RequestAnalyticalReportAction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

final class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function getTitle(): string|Htmlable
    {
        return __('dashboard.title');
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return ['md' => 2];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    Select::make('company_id')
                        ->label(__('dashboard.filters.company'))
                        ->options(fn (): array => $this->companyOptions())
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('branch_id', null))
                        ->placeholder(__('dashboard.filters.all')),
                    Select::make('branch_id')
                        ->label(__('dashboard.filters.branch'))
                        ->options(fn (Get $get): array => $this->branchOptions($get('company_id')))
                        ->searchable()
                        ->native(false)
                        ->placeholder(__('dashboard.filters.all')),
                    DatePicker::make('period_start')
                        ->label(__('dashboard.filters.period_start'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(fn (): string => today(config('app.timezone'))->startOfMonth()->toDateString()),
                    DatePicker::make('period_end')
                        ->label(__('dashboard.filters.period_end'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->minDate(fn (Get $get): mixed => $get('period_start'))
                        ->default(fn (): string => today(config('app.timezone'))->endOfMonth()->toDateString()),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            RequestAnalyticalReportAction::make()
                ->fillForm(fn (): array => [
                    'company_id' => $this->filters['company_id'] ?? null,
                    'branch_id' => $this->filters['branch_id'] ?? null,
                    'period_start' => $this->filters['period_start'] ?? today(config('app.timezone'))->startOfMonth()->toDateString(),
                    'period_end' => $this->filters['period_end'] ?? today(config('app.timezone'))->endOfMonth()->toDateString(),
                    'date_basis' => ReportDateBasis::DueDate->value,
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function companyOptions(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        $query = $user->role->seesAllBranches()
            ? Company::query()->active()
            : Company::query()->whereHas('branches', fn (Builder $q): Builder => $q->visibleTo($user));

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function branchOptions(mixed $companyId): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return Branch::query()
            ->visibleTo($user)
            ->when(filled($companyId), fn (Builder $q): Builder => $q->where('company_id', $companyId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
