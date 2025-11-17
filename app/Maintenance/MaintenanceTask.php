<?php

namespace BookStack\Maintenance;

use BookStack\App\Model;
use BookStack\Entities\Models\Page;
use BookStack\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceTask extends Model
{
    protected $fillable = [
        'page_id',
        'user_id',
        'period',
        'next_due_at',
        'last_maintained_at',
        'status',
        'draft_revision_id',
    ];

    protected $casts = [
        'next_due_at' => 'datetime',
        'last_maintained_at' => 'datetime',
    ];

    protected $appends = ['status_label'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_COMPLETED = 'completed';

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => '已送審',
            self::STATUS_COMPLETED => '已完成',
            default => '待維護',
        };
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->next_due_at !== null
            && $this->next_due_at->isPast();
    }

    public function advanceToNextPeriod(): void
    {
        $period = $this->period ?? 'monthly';
        $current = Carbon::now();

        switch ($period) {
            case 'weekly':
                $this->next_due_at = $current->copy()->addWeek();
                break;
            case 'monthly':
                $this->next_due_at = $current->copy()->addMonth();
                break;
            case 'quarterly':
                $this->next_due_at = $current->copy()->addMonths(3);
                break;
            default:
                if (is_numeric($period)) {
                    $this->next_due_at = $current->copy()->addDays((int) $period);
                } else {
                    $this->next_due_at = $current->copy()->addMonth();
                }
                break;
        }
    }
}
