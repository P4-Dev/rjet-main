<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAttachments
{
    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('sort_order');
    }

    public function syncHasAttachmentsFlag(): void
    {
        if (! $this->isFillable('has_attachments') && ! array_key_exists('has_attachments', $this->getAttributes())) {
            return;
        }

        $this->forceFill(['has_attachments' => $this->attachments()->exists()])->saveQuietly();
    }
}
