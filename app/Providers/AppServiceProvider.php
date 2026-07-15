<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;
use App\Models\User;
use App\Policies\BranchBankAccountPolicy;
use App\Policies\BranchPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;
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
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
