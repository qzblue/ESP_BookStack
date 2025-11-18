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
    protected bool $hasApprovedRevisionColumn;

    public function __construct()
    {
        $this->timezone = config('app.timezone', 'Asia/Shanghai') ?: 'Asia/Shanghai';
        $this->pageMorphClass = (new Page())->getMorphClass();
        $this->refreshSchemaState();
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    protected function refreshSchemaState(): void
    {
        $this->tableExists = Schema::hasTable('page_maintenances');
        $this->hasPageTypeColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'page_type');
        $this->hasPeriodHourColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'period_hours');
        $this->hasPeriodMinuteColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'period_minutes');
        $this->hasApprovedRevisionColumn = $this->tableExists && Schema::hasColumn('page_maintenances', 'last_approved_revision_id');
    }

    protected function readyOrAbort(): void
    {
        $this->refreshSchemaState();
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

    protected function constrainToActivePages(Builder $query): Builder
    {
        return $query->whereHas('page', function (Builder $subQuery) {
            $subQuery->whereNull('deleted_at');
        });
    }

    public function getMaintenanceForPage(Page $page): ?PageMaintenance
    {
        if (!$this->tableExists) {
            return null;
        }

        return $this->baseQuery()
            ->with(['maintainer', 'page'])
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
            if ($this->hasApprovedRevisionColumn) {
                $maintenance->last_approved_revision_id = $page->currentRevision?->id;
            }
        }

        $maintenance->next_due_at = $this->calculateNextDue($now, $periodDays, $periodHours, $periodMinutes);
        if ($this->hasApprovedRevisionColumn && !$maintenance->last_approved_revision_id) {
            $maintenance->last_approved_revision_id = $page->currentRevision?->id;
        }
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
        if ($this->hasApprovedRevisionColumn) {
            $maintenance->last_approved_revision_id = $page->currentRevision?->id;
        }
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

        $baseQuery = $this->constrainToActivePages(
            $this->baseQuery()->with(['page.book', 'page.chapter', 'maintainer'])
        );

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
            $count = $this->constrainToActivePages($this->baseQuery())
                ->where('status', PageMaintenance::STATUS_IN_REVIEW)
                ->count();

            return [
                'count' => $count,
                'label' => trans('esp::maintenance.header_admin', ['count' => $count]),
            ];
        }

        $count = $this->constrainToActivePages($this->baseQuery())
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
        $records = $this->constrainToActivePages(
            $this->baseQuery()
                ->with(['maintainer', 'page'])
        )->get();
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

    /**
     * Sync maintenance state with page edits & revisions.
     * - Maintainer編輯：直接視為「提交審核」，發送待審通知給管理員。
     * - 管理員編輯：直接標記為最新版本並重置下次到期時間。
     */
    public function handlePageUpdated(Page $page, User $actor): void
    {
        if (!$this->tableExists) {
            return;
        }

        $maintenance = $this->getMaintenanceForPage($page);
        if (!$maintenance) {
            return;
        }

        $now = Carbon::now($this->timezone);

        if ($this->userCanAdminister($actor)) {
            $maintenance->status = PageMaintenance::STATUS_UP_TO_DATE;
            $maintenance->last_reviewed_at = $now;
            if ($this->hasApprovedRevisionColumn) {
                $maintenance->last_approved_revision_id = $page->currentRevision?->id;
            }
            $maintenance->next_due_at = $this->calculateNextDue(
                $now,
                $maintenance->period_days,
                $this->hasPeriodHourColumn ? ($maintenance->period_hours ?? 0) : 0,
                $this->hasPeriodMinuteColumn ? ($maintenance->period_minutes ?? 0) : 0
            );
            $maintenance->last_rejected_reason = null;
            $maintenance->save();

            // 提醒維護人：管理員已直接更新並結束本輪維護。
            $this->notifyMaintainer(
                $maintenance,
                trans('esp::maintenance.notifications.approved_subject', ['page' => $page->name]),
                trans('esp::maintenance.notifications.approved_body', ['page' => $page->name]),
                $page->getUrl()
            );

            return;
        }

        if ($maintenance->maintainer_user_id !== $actor->id) {
            return;
        }

        if ($maintenance->status !== PageMaintenance::STATUS_IN_REVIEW) {
            $maintenance->status = PageMaintenance::STATUS_IN_REVIEW;
            $maintenance->last_rejected_reason = null;
            $maintenance->last_reviewed_at = $now;
            $maintenance->next_due_at = $this->calculateNextDue(
                $now,
                $maintenance->period_days,
                $this->hasPeriodHourColumn ? ($maintenance->period_hours ?? 0) : 0,
                $this->hasPeriodMinuteColumn ? ($maintenance->period_minutes ?? 0) : 0
            );
            $maintenance->save();

            $this->notifyAdmins(
                trans('esp::maintenance.notifications.submitted_subject', ['page' => $page->name]),
                trans('esp::maintenance.notifications.submitted_body', ['page' => $page->name]),
                $page->getUrl()
            );
        }
    }

    public function shouldShowApprovedContent(PageMaintenance $maintenance, User $viewer): bool
    {
        return $this->hasApprovedRevisionColumn
            && $maintenance->status === PageMaintenance::STATUS_IN_REVIEW
            && !$this->userCanAdminister($viewer)
            && $maintenance->maintainer_user_id !== $viewer->id;
    }

    public function getApprovedRevision(PageMaintenance $maintenance): ?\BookStack\Entities\Models\PageRevision
    {
        $revisionQuery = $maintenance->page?->revisions();
        if (!$revisionQuery) {
            return null;
        }

        if ($this->hasApprovedRevisionColumn && $maintenance->last_approved_revision_id) {
            $match = (clone $revisionQuery)
                ->where('id', $maintenance->last_approved_revision_id)
                ->first();
            if ($match) {
                return $match;
            }
        }

        if ($maintenance->last_reviewed_at) {
            return (clone $revisionQuery)
                ->where('created_at', '<=', $maintenance->last_reviewed_at)
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();
        }

        return $revisionQuery->orderBy('created_at', 'asc')->first();
    }

    public function formatPeriod(?PageMaintenance $maintenance): string
    {
        if (!$maintenance) {
            return trans('esp::maintenance.card.minutes_format', ['value' => 0]);
        }

        $parts = [];

        if ($maintenance->period_days > 0) {
            $parts[] = trans('esp::maintenance.card.days_format', ['value' => $maintenance->period_days]);
        }

        if ($this->hasPeriodHourColumn && ($maintenance->period_hours ?? 0) > 0) {
            $parts[] = trans('esp::maintenance.card.hours_format', ['value' => $maintenance->period_hours]);
        }

        if ($this->hasPeriodMinuteColumn) {
            $minutes = $maintenance->period_minutes ?? 0;
            if ($minutes > 0 || empty($parts)) {
                $parts[] = trans('esp::maintenance.card.minutes_format', ['value' => $minutes]);
            }
        }

        return $parts ? implode(' ', $parts) : trans('esp::maintenance.card.minutes_format', ['value' => 0]);
    }

    public function formatDateTime(?Carbon $value): string
    {
        if (!$value) {
            return '—';
        }

        return $value->copy()->setTimezone($this->timezone)->format('Y-m-d H:i');
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

    public function userCanDocumentManage(User $user): bool
    {
        return $this->userCanAdminister($user)
            || $user->can(Permission::BookUpdateAll->value)
            || $user->can(Permission::ChapterUpdateAll->value)
            || $user->can(Permission::PageUpdateAll->value);
    }

    public function getOverviewRecords(User $user): EloquentCollection
    {
        if (!$this->tableExists || !$this->userCanDocumentManage($user)) {
            return new EloquentCollection();
        }

        return $this->constrainToActivePages(
            $this->baseQuery()->with(['page.book', 'page.chapter', 'maintainer'])
        )->orderBy('next_due_at')->get();
    }

    protected function calculateNextDue(Carbon $base, int $days, int $hours, int $minutes): Carbon
    {
        return (clone $base)
            ->setTimezone($this->timezone)
            ->addDays($days)
            ->addHours($hours)
            ->addMinutes($minutes);
    }
}
