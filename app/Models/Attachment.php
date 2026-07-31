<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use App\Observers\AttachmentObserver;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[ObservedBy(AttachmentObserver::class)]
final class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttachmentType::class,
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function temporaryUrl(int $minutes = 30): ?string
    {
        try {
            return Storage::disk($this->disk)->temporaryUrl(
                $this->path,
                now()->addMinutes($minutes),
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function humanSize(): string
    {
        return number_format($this->size / 1024, 0).' KB';
    }
}
