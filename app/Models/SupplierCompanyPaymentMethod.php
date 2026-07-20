<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\SupplierCompanyPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SupplierCompanyPaymentMethod extends Model
{
    /** @use HasFactory<SupplierCompanyPaymentMethodFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'company_id',
        'payment_method',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
