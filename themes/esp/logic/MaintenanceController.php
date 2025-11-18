<?php

namespace EspTheme\Logic;

use BookStack\Entities\Models\Page;
use BookStack\Users\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class MaintenanceController
{
    public function __construct(protected MaintenanceService $service)
    {
    }

    public function listTasks(Request $request): View
    {
        $user = user();
        $tasks = $this->service->getTasksForUser($user);

        return view('esp::maintenance.tasks', [
            'taskSets' => $tasks,
            'service' => $this->service,
            'canAdminister' => $this->service->userCanAdminister($user),
        ]);
    }

    public function overview(Request $request): View
    {
        $user = user();
        $this->ensureDocumentManager();

        $status = $request->string('status')->toString() ?: null;
        $maintainerId = $request->integer('maintainer') ?: null;

        return view('esp::maintenance.overview', [
            'records' => $this->service->getOverviewRecords($user, $status, $maintainerId),
            'service' => $this->service,
            'canAdminister' => $this->service->userCanAdminister($user),
            'summary' => $this->service->getOverviewSummary($user),
            'selectedStatus' => $status,
            'selectedMaintainer' => $maintainerId,
            'maintainers' => $this->service->getMaintainerOptions(),
        ]);
    }

    public function assign(Request $request, int $pageId): RedirectResponse
    {
        $this->ensureAdmin();

        $data = $this->validateAssign($request);
        $maintainer = User::query()->findOrFail($data['maintainer_user_id']);
        $page = $this->findPage($pageId);

        $this->service->assign(
            $page,
            $maintainer,
            (int) $data['period_days'],
            (int) ($data['period_hours'] ?? 0),
            (int) ($data['period_minutes'] ?? 0)
        );
        session()->flash('success', trans('esp::maintenance.messages.assigned'));

        return Redirect::to($page->getUrl());
    }

    public function startUpdate(int $pageId): RedirectResponse
    {
        $page = $this->findPage($pageId);
        $this->service->startUpdate($page, user());
        session()->flash('success', trans('esp::maintenance.messages.started'));

        return Redirect::to($page->getUrl());
    }

    public function submitReview(int $pageId): RedirectResponse
    {
        $page = $this->findPage($pageId);
        $this->service->submitReview($page, user());
        session()->flash('success', trans('esp::maintenance.messages.submitted'));

        return Redirect::to($page->getUrl());
    }

    public function approve(int $pageId): RedirectResponse
    {
        $this->ensureAdmin();
        $page = $this->findPage($pageId);

        $this->service->approve($page, user());
        session()->flash('success', trans('esp::maintenance.messages.approved'));

        return Redirect::to($page->getUrl());
    }

    public function reject(Request $request, int $pageId): RedirectResponse
    {
        $this->ensureAdmin();

        $data = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $page = $this->findPage($pageId);
        $this->service->reject($page, user(), $data['reason']);
        session()->flash('error', trans('esp::maintenance.messages.rejected'));

        return Redirect::to($page->getUrl());
    }

    protected function ensureAdmin(): void
    {
        if (!$this->service->userCanAdminister(user())) {
            throw new AuthorizationException('Only administrators can perform this action');
        }
    }

    protected function ensureDocumentManager(): void
    {
        if (!$this->service->userCanDocumentManage(user())) {
            throw new AuthorizationException('Only administrators or document managers can perform this action');
        }
    }

    protected function validateAssign(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'maintainer_user_id' => ['required', 'integer', 'exists:users,id'],
            'period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'period_hours' => ['nullable', 'integer', 'min:0', 'max:23'],
            'period_minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $days = (int) $request->input('period_days', 0);
            $hours = (int) $request->input('period_hours', 0);
            $minutes = (int) $request->input('period_minutes', 0);

            if ($days <= 0 && $hours <= 0 && $minutes <= 0) {
                $validator->errors()->add('period_minutes', trans('esp::maintenance.messages.period_required'));
            }
        });

        return $validator->validate();
    }

    protected function findPage(int $pageId): Page
    {
        $page = Page::query()->find($pageId);

        if (!$page) {
            abort(404, 'Page not found');
        }

        return $page;
    }
}
