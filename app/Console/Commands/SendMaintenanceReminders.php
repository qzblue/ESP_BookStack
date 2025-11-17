<?php

namespace BookStack\Console\Commands;

use BookStack\Maintenance\MaintenanceTask;
use BookStack\Maintenance\Notifications\ArticleMaintenanceDue;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMaintenanceReminders extends Command
{
    protected $signature = 'maintenance:reminders';

    protected $description = '發送文章維護到期提醒';

    public function handle(): int
    {
        $tasks = MaintenanceTask::query()
            ->with('user', 'page')
            ->where('status', MaintenanceTask::STATUS_PENDING)
            ->where('next_due_at', '<=', Carbon::now())
            ->get();

        foreach ($tasks as $task) {
            $task->user?->notify(new ArticleMaintenanceDue($task));
        }

        $this->info('已發送維護提醒：' . $tasks->count());

        return self::SUCCESS;
    }
}
