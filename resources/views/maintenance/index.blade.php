@extends('layouts.simple')

@section('body')
    <div class="container small">
        <div class="card content-wrap auto-height">
            <div class="grid half gap-xl v-center">
                <div>
                    <h1 class="list-heading">文章維護任務</h1>
                    <p class="text-muted">查看並處理被指派的維護任務。</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>頁面</th>
                        <th>維護人員</th>
                        <th>週期</th>
                        <th>截止日</th>
                        <th>狀態</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($tasks as $task)
                        <tr class="{{ $task->isOverdue() ? 'text-neg' : '' }}">
                            <td><a href="{{ $task->page?->getUrl() }}">{{ $task->page?->name }}</a></td>
                            <td>{{ $task->user?->name }}</td>
                            <td>{{ $task->period }}</td>
                            <td>{{ optional($task->next_due_at)->format('Y-m-d') }}</td>
                            <td>{{ $task->status_label }}</td>
                            <td class="text-right">
                                @if($task->status === \BookStack\Maintenance\MaintenanceTask::STATUS_SUBMITTED && user()->can(\BookStack\Permissions\Permission::SettingsManage))
                                    <form method="POST" action="{{ route('maintenance.approve', $task->id) }}">
                                        {{ csrf_field() }}
                                        <button class="button outline" type="submit">通過審核</button>
                                    </form>
                                @elseif($task->status === \BookStack\Maintenance\MaintenanceTask::STATUS_PENDING && $task->user_id === user()->id)
                                    <form method="POST" action="{{ route('maintenance.submit', $task->id) }}">
                                        {{ csrf_field() }}
                                        <button class="button outline" type="submit">送出審核</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid third gap-lg mt-m">
                <div class="col-span-2">{{ $tasks->links() }}</div>
            </div>
        </div>
    </div>
@endsection
