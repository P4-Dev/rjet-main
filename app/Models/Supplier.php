<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PersonType;
use App\Models\Concerns\HasAddresses;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasContacts;
use App\Models\Concerns\HasUuid;
use App\Observers\SupplierObserver;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(SupplierObserver::class)]
final class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasAddresses, HasBlameable, HasContacts, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_type',
        'document',
        'name',
        'legal_name',
        'email',
        'phone',
        'default_payment_method',
        'notes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'person_type' => PersonType::class,
            'default_payment_method' => PaymentMethod::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SupplierCompanyPaymentMethod, $this>
     */
    public function companyPaymentMethods(): HasMany
    {
        return $this->hasMany(SupplierCompanyPaymentMethod::class);
    }

    /**
     * @return HasMany<PaymentRequest, $this>
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * @return HasOne<SupplierBankDetails, $this>
     */
    public function bankDetails(): HasOne
    {
        return $this->hasOne(SupplierBankDetails::class);
    }

    public function paymentMethodFor(Company $company): PaymentMethod
    {
        $override = $this->companyPaymentMethods()
            ->where('company_id', $company->getKey())
            ->first();

        return $override?->payment_method ?? $this->default_payment_method;
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopePf(Builder $query): Builder
    {
        return $query->where('person_type', PersonType::Pf);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopePj(Builder $query): Builder
    {
        return $query->where('person_type', PersonType::Pj);
    }
}
