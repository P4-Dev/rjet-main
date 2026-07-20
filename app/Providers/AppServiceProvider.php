<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Appropriation;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Models\User;
use App\Policies\AppropriationPolicy;
use App\Policies\BankPolicy;
use App\Policies\BranchBankAccountPolicy;
use App\Policies\BranchPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\SupplierCompanyPaymentMethodPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Company::class => CompanyPolicy::class,
        Branch::class => BranchPolicy::class,
        BranchBankAccount::class => BranchBankAccountPolicy::class,
        User::class => UserPolicy::class,
        CostCenter::class => CostCenterPolicy::class,
        Appropriation::class => AppropriationPolicy::class,
        Supplier::class => SupplierPolicy::class,
        SupplierCompanyPaymentMethod::class => SupplierCompanyPaymentMethodPolicy::class,
        Bank::class => BankPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Relation::enforceMorphMap([
            'supplier' => Supplier::class,
        ]);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
