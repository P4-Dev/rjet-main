<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ApprovalReassignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApprovalReassignment extends Model
{
    /** @use HasFactory<ApprovalReassignmentFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'approval_id',
        'from_approver_user_id',
        'to_approver_user_id',
        'reason',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fromApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_approver_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_approver_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
