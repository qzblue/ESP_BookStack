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

    public function assign(Request $request, int $pageId): RedirectResponse
    {
        $this->ensureAdmin();

        $data = $this->validateAssign($request);
        $maintainer = User::query()->findOrFail($data['maintainer_user_id']);
        $page = $this->findPage($pageId);

        $this->service->assign($page, $maintainer, (int) $data['period_days']);
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

    protected function validateAssign(Request $request): array
    {
        return Validator::make($request->all(), [
            'maintainer_user_id' => ['required', 'integer', 'exists:users,id'],
            'period_days' => ['required', 'integer', 'min:1', 'max:365'],
        ])->validate();
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
