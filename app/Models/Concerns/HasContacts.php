<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasContacts
{
    /**
     * @return MorphMany<Contact, $this>
     */
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }
}
