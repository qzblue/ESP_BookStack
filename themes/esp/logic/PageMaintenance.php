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
 * @property int $maintainer_user_id
 * @property int $period_days
 * @property Carbon $next_due_at
 * @property Carbon|null $last_reviewed_at
 * @property string $status
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
        'maintainer_user_id',
        'period_days',
        'next_due_at',
        'last_reviewed_at',
        'status',
        'last_rejected_reason',
    ];

    protected $casts = [
        'next_due_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function maintainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maintainer_user_id');
    }
}
