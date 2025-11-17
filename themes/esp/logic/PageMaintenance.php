<?php

namespace EspTheme\Logic;

use BookStack\Entities\Models\Page;
use BookStack\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $page_id
 * @property string $page_type
 * @property int $maintainer_user_id
 * @property int $period_days
 * @property int $period_hours
 * @property int $period_minutes
 * @property Carbon $next_due_at
 * @property Carbon|null $last_reviewed_at
 * @property string $status
 * @property int|null $last_approved_revision_id
 * @property string|null $last_rejected_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PageMaintenance extends Model
{
    public const STATUS_UP_TO_DATE = 'UP_TO_DATE';
    public const STATUS_DUE_SOON = 'DUE_SOON';
    public const STATUS_OVERDUE = 'OVERDUE';
    public const STATUS_IN_UPDATE = 'IN_UPDATE';
    public const STATUS_IN_REVIEW = 'IN_REVIEW';

    protected $table = 'page_maintenances';

    protected $fillable = [
        'page_id',
        'page_type',
        'maintainer_user_id',
        'period_days',
        'period_hours',
        'period_minutes',
        'next_due_at',
        'last_reviewed_at',
        'status',
        'last_approved_revision_id',
        'last_rejected_reason',
    ];

    protected $attributes = [];

    protected $casts = [
        'next_due_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PageMaintenance $maintenance): void {
            if (empty($maintenance->page_type)) {
                $maintenance->page_type = (new Page())->getMorphClass();
            }
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id', 'id')->withTrashed();
    }

    public function maintainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maintainer_user_id');
    }
}
