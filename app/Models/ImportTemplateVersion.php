<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ImportTemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ImportTemplateVersion extends Model
{
    /** @use HasFactory<ImportTemplateVersionFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public $timestamps = false;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_template_id',
        'version',
        'is_current',
        'published_at',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ImportTemplate::class, 'import_template_id');
    }

    /**
     * @return HasMany<ImportTemplateMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(ImportTemplateMapping::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ImportBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
