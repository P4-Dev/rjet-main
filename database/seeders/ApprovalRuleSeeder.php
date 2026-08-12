<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

final class ApprovalRuleSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->update(['approval_sla_business_days' => 2]);

        $operadorA = User::query()->where('email', 'operador@rjet.com')->first()
            ?? User::query()->where('role', UserRole::Operador)->where('can_approve', true)->orderBy('created_at')->first();

        $operadorB = User::query()
            ->where('role', UserRole::Operador)
            ->where('can_approve', true)
            ->when($operadorA, fn ($q) => $q->whereKeyNot($operadorA->getKey()))
            ->orderBy('created_at')
            ->first()
            ?? $operadorA;

        $adm = User::query()->where('email', 'admin@rjet.com')->first()
            ?? User::query()->where('role', UserRole::Adm)->orderBy('created_at')->first();

        if ($operadorA === null || $adm === null) {
            return;
        }

        if (! $adm->can_approve) {
            $adm->forceFill(['can_approve' => true])->saveQuietly();
        }

        Branch::query()
            ->whereHas('company', fn ($q) => $q->whereIn('name', ['Altitude', 'Glow']))
            ->orderBy('name')
            ->get()
            ->each(function (Branch $branch) use ($operadorA, $operadorB, $adm): void {
                $this->seedRule($branch, '0.00', '5000.00', $operadorA);
                $this->seedRule($branch, '5000.01', '50000.00', $operadorB ?? $adm);
                $this->seedRule($branch, '50001.00', null, $adm);
            });
    }

    private function seedRule(Branch $branch, string $min, ?string $max, User $approver): void
    {
        ApprovalRule::query()->updateOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'min_amount' => $min,
                'max_amount' => $max,
            ],
            [
                'approver_user_id' => $approver->getKey(),
                'is_active' => true,
            ],
        );
    }
}
