<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportTargetField;
use App\Models\Concerns\HasUuid;
use Database\Factories\ImportBatchErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImportBatchError extends Model
{
    /** @use HasFactory<ImportBatchErrorFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_batch_id',
        'row_number',
        'target_field',
        'message',
        'raw_values',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_values' => 'array',
            'created_at' => 'datetime',
            'target_field' => ImportTargetField::class,
            'row_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
