<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'zip_code',
        'country',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
