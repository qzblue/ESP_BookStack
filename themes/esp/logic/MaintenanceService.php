<?php

namespace EspTheme\Logic;

use BookStack\Entities\Models\Page;
use BookStack\Permissions\Permission;
use BookStack\Users\Models\User;
use EspTheme\Logic\Notifications\MaintenanceStatusNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class MaintenanceService
{
    public const DUE_SOON_THRESHOLD_DAYS = 7;

    protected string $timezone;
    protected string $pageMorphClass;
    protected bool $tableExists;
    protected bool $hasPageTypeColumn;
    protected bool $hasPeriodHourColumn;
    protected bool $hasPeriodMinuteColumn;

    public function __construct()
    {
        $this->timezone = config('app.timezone', 'Asia/Shanghai') ?: 'Asia/Shanghai';
        $this->pageMorphClass = (new Page())->getMorphClass();
        $this->tableExists = Schema::hasTable('page_maintenances');
        $this->hasPageTypeColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'page_type');
        $this->hasPeriodHourColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'period_hours');
        $this->hasPeriodMinuteColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'period_minutes');
    }

    protected function readyOrAbort(): void
    {
        if (!$this->tableExists) {
            abort(500, trans('esp::maintenance.messages.missing_table'));
        }
    }

    protected function baseQuery(): Builder
    {
        $this->readyOrAbort();

        $query = PageMaintenance::query();

        if ($this->hasPageTypeColumn) {
            $query->where('page_type', $this->pageMorphClass);
        }

        return $query;
    }

    public function getMaintenanceForPage(Page $page): ?PageMaintenance
    {
        if (!$this->tableExists) {
            return null;
        }

        return $this->baseQuery()
            ->with('maintainer')
            ->where('page_id', $page->id)
            ->first();
    }

    public function assign(Page $page, User $maintainer, int $periodDays, int $periodHours = 0, int $periodMinutes = 0): PageMaintenance
    {
        $periodDays = max(0, $periodDays);
        $periodHours = max(0, $periodHours);
        $periodMinutes = max(0, $periodMinutes);
        if ($periodDays === 0 && $periodHours === 0 && $periodMinutes === 0) {
            $periodMinutes = 1;
        }

        $queryAttributes = ['page_id' => $page->id];
        if ($this->hasPageTypeColumn) {
            $queryAttributes['page_type'] = $this->pageMorphClass;
        }

        $maintenance = $this->baseQuery()->firstOrNew($queryAttributes);

        if ($this->hasPageTypeColumn) {
            $maintenance->page_type = $this->pageMorphClass;
        }
        $maintenance->maintainer_user_id = $maintainer->id;
        $maintenance->period_days = $periodDays;
        if ($this->hasPeriodHourColumn) {
            $maintenance->period_hours = $periodHours;
        }
        if ($this->hasPeriodMinuteColumn) {
            $maintenance->period_minutes = $periodMinutes;
        }

        $now = Carbon::now($this->timezone);

        if (!$maintenance->exists) {
            $maintenance->status = PageMaintenance::STATUS_UP_TO_DATE;
            $maintenance->last_reviewed_at = $now;
        }

        $maintenance->next_due_at = $this->calculateNextDue($now, $periodDays, $periodHours, $periodMinutes);
        if ($maintenance->status === PageMaintenance::STATUS_IN_REVIEW) {
            // Keep review state but clear any previous rejection context.
            $maintenance->last_rejected_reason = null;
        } elseif ($maintenance->status !== PageMaintenance::STATUS_IN_UPDATE) {
            // Reset back to up to date for any other state when settings change.
            $maintenance->status = PageMaintenance::STATUS_UP_TO_DATE;
            $maintenance->last_reviewed_at = $now;
            $maintenance->last_rejected_reason = null;
        }
        $maintenance->save();

        return $maintenance;
    }

    public function startUpdate(Page $page, User $user): PageMaintenance
    {
        $maintenance = $this->getOrFail($page);
        $this->assertMaintainer($maintenance, $user);
        if (!in_array($maintenance->status, [PageMaintenance::STATUS_DUE_SOON, PageMaintenance::STATUS_OVERDUE], true)) {
            abort(422, 'Page is not due for maintenance.');
        }

        $maintenance->status = PageMaintenance::STATUS_IN_UPDATE;
        $maintenance->save();

        return $maintenance;
    }

    public function submitReview(Page $page, User $user): PageMaintenance
    {
        $maintenance = $this->getOrFail($page);
        $this->assertMaintainer($maintenance, $user);
        if ($maintenance->status !== PageMaintenance::STATUS_IN_UPDATE) {
            abort(422, 'Page must be in update before submitting for review.');
        }

        $maintenance->status = PageMaintenance::STATUS_IN_REVIEW;
        $maintenance->last_rejected_reason = null;
        $maintenance->save();

        $this->notifyAdmins(
            trans('esp::maintenance.notifications.submitted_subject', ['page' => $page->name]),
            trans('esp::maintenance.notifications.submitted_body', ['page' => $page->name]),
            $page->getUrl()
        );

        return $maintenance;
    }

    public function approve(Page $page, User $admin): PageMaintenance
    {
        $maintenance = $this->getOrFail($page);
        $this->assertAdmin($admin);
        if ($maintenance->status !== PageMaintenance::STATUS_IN_REVIEW) {
            abort(422, 'Only maintenance in review can be approved.');
        }

        $now = Carbon::now($this->timezone);
        $maintenance->status = PageMaintenance::STATUS_UP_TO_DATE;
        $maintenance->last_reviewed_at = $now;
        $maintenance->next_due_at = $this->calculateNextDue(
            $now,
            $maintenance->period_days,
            $this->hasPeriodHourColumn ? ($maintenance->period_hours ?? 0) : 0,
            $this->hasPeriodMinuteColumn ? ($maintenance->period_minutes ?? 0) : 0
        );
        $maintenance->last_rejected_reason = null;
        $maintenance->save();

        $this->notifyMaintainer(
            $maintenance,
            trans('esp::maintenance.notifications.approved_subject', ['page' => $page->name]),
            trans('esp::maintenance.notifications.approved_body', ['page' => $page->name]),
            $page->getUrl()
        );

        return $maintenance;
    }

    public function reject(Page $page, User $admin, string $reason): PageMaintenance
    {
        $maintenance = $this->getOrFail($page);
        $this->assertAdmin($admin);
        if ($maintenance->status !== PageMaintenance::STATUS_IN_REVIEW) {
            abort(422, 'Only maintenance in review can be rejected.');
        }

        $maintenance->status = PageMaintenance::STATUS_IN_UPDATE;
        $maintenance->last_rejected_reason = $reason;
        $maintenance->save();

        $this->notifyMaintainer(
            $maintenance,
            trans('esp::maintenance.notifications.rejected_subject', ['page' => $page->name]),
            trans('esp::maintenance.notifications.rejected_body', ['page' => $page->name, 'reason' => $reason]),
            $page->getUrl('/edit')
        );

        return $maintenance;
    }

    public function getTasksForUser(User $user): array
    {
        if (!$this->tableExists) {
            return [
                'maintainer' => collect(),
                'review' => collect(),
            ];
        }

        $baseQuery = $this->baseQuery()->with(['page.book', 'page.chapter', 'maintainer']);

        $maintainerTasks = (clone $baseQuery)
            ->where('maintainer_user_id', $user->id)
            ->whereIn('status', [
                PageMaintenance::STATUS_DUE_SOON,
                PageMaintenance::STATUS_OVERDUE,
                PageMaintenance::STATUS_IN_UPDATE,
                PageMaintenance::STATUS_IN_REVIEW,
            ])->orderBy('next_due_at')
            ->get();

        $reviewTasks = collect();
        if ($this->userCanAdminister($user)) {
            $reviewTasks = (clone $baseQuery)
                ->where('status', PageMaintenance::STATUS_IN_REVIEW)
                ->orderBy('updated_at', 'desc')
                ->get();
        }

        return [
            'maintainer' => $maintainerTasks,
            'review' => $reviewTasks,
        ];
    }

    public function getHeaderSummaryForUser(User $user): array
    {
        if (!$this->tableExists) {
            return [
                'count' => 0,
                'label' => trans('esp::maintenance.header_maintainer', ['count' => 0]),
            ];
        }

        if ($this->userCanAdminister($user)) {
            $count = $this->baseQuery()
                ->where('status', PageMaintenance::STATUS_IN_REVIEW)
                ->count();

            return [
                'count' => $count,
                'label' => trans('esp::maintenance.header_admin', ['count' => $count]),
            ];
        }

        $count = $this->baseQuery()
            ->where('maintainer_user_id', $user->id)
            ->whereIn('status', [
                PageMaintenance::STATUS_DUE_SOON,
                PageMaintenance::STATUS_OVERDUE,
                PageMaintenance::STATUS_IN_UPDATE,
                PageMaintenance::STATUS_IN_REVIEW,
            ])->count();

        return [
            'count' => $count,
            'label' => trans('esp::maintenance.header_maintainer', ['count' => $count]),
        ];
    }

    public function runDailyCheck(): array
    {
        $now = Carbon::now($this->timezone);
        $dueSoonLimit = (clone $now)->addDays(self::DUE_SOON_THRESHOLD_DAYS);
        $updated = [];

        if (!$this->tableExists) {
            return $updated;
        }

        /** @var EloquentCollection<int, PageMaintenance> $records */
        $records = $this->baseQuery()
            ->with(['maintainer', 'page'])
            ->get();
        foreach ($records as $maintenance) {
            $originalStatus = $maintenance->status;

            $nextDue = $maintenance->next_due_at?->copy()->setTimezone($this->timezone);

            if ($nextDue && $nextDue->lessThan($now) && !in_array($maintenance->status, [PageMaintenance::STATUS_IN_UPDATE, PageMaintenance::STATUS_IN_REVIEW], true)) {
                $maintenance->status = PageMaintenance::STATUS_OVERDUE;
            } elseif ($nextDue && $nextDue->lessThanOrEqualTo($dueSoonLimit) && $maintenance->status === PageMaintenance::STATUS_UP_TO_DATE) {
                $maintenance->status = PageMaintenance::STATUS_DUE_SOON;
            } elseif ($nextDue && $nextDue->greaterThan($dueSoonLimit) && $maintenance->status === PageMaintenance::STATUS_DUE_SOON) {
                $maintenance->status = PageMaintenance::STATUS_UP_TO_DATE;
            }

            if ($maintenance->status !== $originalStatus) {
                $maintenance->save();
                $updated[] = $maintenance;

                if ($maintenance->status === PageMaintenance::STATUS_DUE_SOON) {
                    $this->notifyMaintainer(
                        $maintenance,
                        trans('esp::maintenance.notifications.due_soon_subject', ['page' => $maintenance->page?->name ?? 'Page']),
                        trans('esp::maintenance.notifications.due_soon_body', [
                            'page' => $maintenance->page?->name ?? 'Page',
                            'days' => self::DUE_SOON_THRESHOLD_DAYS,
                        ]),
                        $maintenance->page?->getUrl() ?? url('/')
                    );
                }

                if ($maintenance->status === PageMaintenance::STATUS_OVERDUE) {
                    $this->notifyMaintainer(
                        $maintenance,
                        trans('esp::maintenance.notifications.overdue_subject', ['page' => $maintenance->page?->name ?? 'Page']),
                        trans('esp::maintenance.notifications.overdue_body', ['page' => $maintenance->page?->name ?? 'Page']),
                        $maintenance->page?->getUrl() ?? url('/')
                    );
                    $this->notifyAdmins(
                        trans('esp::maintenance.notifications.overdue_subject', ['page' => $maintenance->page?->name ?? 'Page']),
                        trans('esp::maintenance.notifications.overdue_body_admin', ['page' => $maintenance->page?->name ?? 'Page']),
                        $maintenance->page?->getUrl() ?? url('/')
                    );
                }
            }
        }

        return $updated;
    }

    public function getStatusOptions(): array
    {
        return [
            PageMaintenance::STATUS_UP_TO_DATE => trans('esp::maintenance.status.up_to_date'),
            PageMaintenance::STATUS_DUE_SOON => trans('esp::maintenance.status.due_soon'),
            PageMaintenance::STATUS_OVERDUE => trans('esp::maintenance.status.overdue'),
            PageMaintenance::STATUS_IN_UPDATE => trans('esp::maintenance.status.in_update'),
            PageMaintenance::STATUS_IN_REVIEW => trans('esp::maintenance.status.in_review'),
        ];
    }

    protected function notifyMaintainer(PageMaintenance $maintenance, string $subject, string $message, string $url): void
    {
        $maintainer = $maintenance->maintainer;
        if (!$maintainer) {
            return;
        }

        Notification::send($maintainer, new MaintenanceStatusNotification($subject, $message, $url));
    }

    protected function notifyAdmins(string $subject, string $message, string $url): void
    {
        $admins = User::query()->whereHas('roles', function ($query) {
            $query->where('system_name', 'admin');
        })->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new MaintenanceStatusNotification($subject, $message, $url));
    }

    protected function getOrFail(Page $page): PageMaintenance
    {
        $this->readyOrAbort();
        $maintenance = $this->getMaintenanceForPage($page);
        if (!$maintenance) {
            abort(404, 'Maintenance record not found');
        }

        return $maintenance;
    }

    protected function assertMaintainer(PageMaintenance $maintenance, User $user): void
    {
        if ($maintenance->maintainer_user_id !== $user->id) {
            abort(403, 'Only the assigned maintainer can perform this action');
        }
    }

    public function userCanAdminister(User $user): bool
    {
        return $user->hasSystemRole('admin')
            || $user->can(Permission::SettingsManage->value)
            || $user->can(Permission::UsersManage->value);
    }

    protected function assertAdmin(User $user): void
    {
        if (!$this->userCanAdminister($user)) {
            abort(403, 'Only administrators can perform this action');
        }
    }

    protected function calculateNextDue(Carbon $base, int $days, int $hours, int $minutes): Carbon
    {
        return (clone $base)
            ->addDays($days)
            ->addHours($hours)
            ->addMinutes($minutes);
    }
}
