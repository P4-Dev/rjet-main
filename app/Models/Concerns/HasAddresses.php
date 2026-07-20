<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Address;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAddresses
{
    /**
     * @return MorphMany<Address, $this>
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }
}
