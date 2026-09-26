<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportTargetField;
use App\Models\Concerns\HasUuid;
use Database\Factories\ImportTemplateMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImportTemplateMapping extends Model
{
    /** @use HasFactory<ImportTemplateMappingFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_template_version_id',
        'source_column',
        'target_field',
        'default_value',
        'sort_order',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_field' => ImportTargetField::class,
            'created_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportTemplateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ImportTemplateVersion::class, 'import_template_version_id');
    }
}
