<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'mobile',
        'position',
        'department',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'is_default' => 'boolean',
        ];
    }

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }
}
