<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Observers\BlameableObserver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasBlameable
{
    protected static function bootHasBlameable(): void
    {
        static::observe(BlameableObserver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
