<?php

namespace BookStack\Maintenance;

use BookStack\Entities\Models\PageRevision;
use BookStack\Entities\Repos\PageRepo;
use BookStack\Entities\Repos\RevisionRepo;
use BookStack\Http\Controller;
use BookStack\Permissions\Permission;
use BookStack\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class MaintenanceTaskController extends Controller
{
    public function __construct(
        protected PageRepo $pageRepo,
        protected RevisionRepo $revisionRepo,
    ) {
    }

    public function index()
    {
        $query = MaintenanceTask::query()->with(['page', 'user']);

        if (!user()->can(Permission::SettingsManage)) {
            $query->where('user_id', '=', user()->id);
        }

        $tasks = $query->orderByDesc('next_due_at')->paginate(20);
        $this->setPageTitle('維護任務');

        return view('maintenance.index', compact('tasks'));
    }

    public function assign(Request $request): RedirectResponse
    {
        $this->checkPermission(Permission::SettingsManage);

        $data = $request->validate([
            'page_id' => 'required|exists:pages,id',
            'user_id' => 'required|exists:users,id',
            'period'  => 'required|string',
        ]);

        $task = MaintenanceTask::firstOrNew(['page_id' => $data['page_id']]);
        $task->fill($data);
        $task->status = MaintenanceTask::STATUS_PENDING;
        $task->advanceToNextPeriod();
        $task->save();

        return redirect()->back()->with('success', '已指派維護任務');
    }

    public function submitReview(int $taskId): RedirectResponse
    {
        $task = MaintenanceTask::with('page')->findOrFail($taskId);

        if ($task->user_id !== user()->id) {
            $this->showPermissionError();
        }

        $draft = PageRevision::query()
            ->where('page_id', '=', $task->page_id)
            ->where('created_by', '=', user()->id)
            ->where('type', '=', 'update_draft')
            ->latest('updated_at')
            ->first();

        if (!$draft) {
            return redirect()->back()->with('error', '請先保存草稿再送出審核。');
        }

        $task->draft_revision_id = $draft->id;
        $task->status = MaintenanceTask::STATUS_SUBMITTED;
        $task->save();

        $admins = User::query()
            ->whereHas('roles', fn($query) => $query->where('system_name', '=', 'admin'))
            ->get();

        Notification::send($admins, new Notifications\ArticleReviewSubmitted($task));

        return redirect($task->page->getUrl())->with('success', '已送出審核');
    }

    public function approve(int $taskId): RedirectResponse
    {
        $this->checkPermission(Permission::SettingsManage);
        $task = MaintenanceTask::with('page', 'user')->findOrFail($taskId);

        if (!$task->draft_revision_id) {
            return redirect()->back()->with('error', '沒有可發布的草稿。');
        }

        $draft = PageRevision::query()->findOrFail($task->draft_revision_id);
        $publishRevision = $draft->replicate();
        $publishRevision->type = 'version';
        $publishRevision->created_by = user()->id;
        $publishRevision->save();

        $this->pageRepo->restoreRevision($task->page, $publishRevision->id);
        $this->revisionRepo->deleteDraftsForCurrentUser($task->page);

        $task->status = MaintenanceTask::STATUS_COMPLETED;
        $task->last_maintained_at = Carbon::now();
        $task->advanceToNextPeriod();
        $task->draft_revision_id = null;
        $task->save();

        $task->user->notify(new Notifications\ArticleUpdateApproved($task));

        return redirect()->route('maintenance.index')->with('success', '已審核並發布更新');
    }
}
