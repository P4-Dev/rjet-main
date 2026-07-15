<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Carbon;

final class CompanyObserver
{
    /**
     * Instante de exclusão capturado por company, usado como limite do restore
     * em cascata (evita restaurar filhos excluídos independentemente antes).
     *
     * @var array<string, Carbon|null>
     */
    private array $restoreThresholds = [];

    /**
     * Teardown administrativo: soft-delete das contas de cada filial e, em
     * seguida, das filiais — sempre via query de relacionamento (bulk), o que
     * ignora eventos de Model por linha e nunca aciona o guard do BranchService.
     */
    public function deleted(Company $company): void
    {
        $company->branches()->get()->each(function (Branch $branch): void {
            $branch->bankAccounts()->delete();
        });

        $company->branches()->delete();
    }

    public function restoring(Company $company): void
    {
        // Em `restoring` o deleted_at ainda reflete o instante da exclusão.
        $this->restoreThresholds[(string) $company->getKey()] = $company->deleted_at;
    }

    /**
     * Restaura filiais e contas excluídas a partir do instante da exclusão da
     * company (heurística de 2 níveis). Toda a cascata compartilha o mesmo
     * limite, pois contas e filiais foram excluídas juntas no teardown.
     */
    public function restored(Company $company): void
    {
        $threshold = $this->restoreThresholds[(string) $company->getKey()] ?? $company->updated_at;

        $company->branches()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->get()
            ->each(function (Branch $branch) use ($threshold): void {
                $branch->restore();

                $branch->bankAccounts()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();
            });

        unset($this->restoreThresholds[(string) $company->getKey()]);
    }

    public function forceDeleted(Company $company): void
    {
        $company->branches()->withTrashed()->get()->each(function (Branch $branch): void {
            $branch->bankAccounts()->withTrashed()->forceDelete();
        });

        $company->branches()->withTrashed()->forceDelete();
    }
}
