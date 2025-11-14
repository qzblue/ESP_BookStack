@extends('layouts.simple')

@section('title', trans('esp::maintenance.tasks.title'))

@section('body')
<div class="grid-xl">
    <div class="col-12">
        <div class="card">
            <h1 class="card-title">{{ trans('esp::maintenance.tasks.title') }}</h1>
            <div class="body">
                <h2 class="text-muted text-small mb-s">{{ trans('esp::maintenance.tasks.mine') }}</h2>
                @if($taskSets['maintainer']->isEmpty())
                    <p class="text-muted">{{ trans('esp::maintenance.tasks.mine_empty') }}</p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ trans('esp::maintenance.tasks.table.page') }}</th>
                                <th>{{ trans('esp::maintenance.tasks.table.location') }}</th>
                                <th>{{ trans('esp::maintenance.tasks.table.status') }}</th>
                                <th>{{ trans('esp::maintenance.tasks.table.next_due') }}</th>
                                <th>{{ trans('esp::maintenance.tasks.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($taskSets['maintainer'] as $task)
                            <tr>
                                <td><a href="{{ $task->page?->getUrl() }}">{{ $task->page?->name ?? trans('esp::maintenance.tasks.unknown_page') }}</a></td>
                                <td>
                                    <div class="text-small text-muted">
                                        {{ $task->page?->book?->name }}
                                        @if($task->page?->chapter)
                                            — {{ $task->page->chapter->name }}
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $service->getStatusOptions()[$task->status] ?? $task->status }}</td>
                                <td>{{ optional($task->next_due_at)->format('Y-m-d') }}</td>
                                <td>
                                    @if(in_array($task->status, [\EspTheme\Logic\PageMaintenance::STATUS_DUE_SOON, \EspTheme\Logic\PageMaintenance::STATUS_OVERDUE]))
                                        <form action="{{ route('maintenance.start', ['page' => $task->page_id]) }}" method="POST" class="inline">
                                            @csrf
                                            <button class="text-button">{{ trans('esp::maintenance.tasks.start_update') }}</button>
                                        </form>
                                    @elseif($task->status === \EspTheme\Logic\PageMaintenance::STATUS_IN_UPDATE)
                                        <form action="{{ route('maintenance.submit', ['page' => $task->page_id]) }}" method="POST" class="inline">
                                            @csrf
                                            <button class="text-button">{{ trans('esp::maintenance.tasks.submit_review') }}</button>
                                        </form>
                                    @elseif($task->status === \EspTheme\Logic\PageMaintenance::STATUS_IN_REVIEW)
                                        <span class="text-muted">{{ trans('esp::maintenance.tasks.waiting') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if(user()->hasSystemRole('admin'))
                    <h2 class="text-muted text-small mt-l mb-s">{{ trans('esp::maintenance.tasks.review_title') }}</h2>
                    @if($taskSets['review']->isEmpty())
                        <p class="text-muted">{{ trans('esp::maintenance.tasks.review_empty') }}</p>
                    @else
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ trans('esp::maintenance.tasks.table.page') }}</th>
                                    <th>{{ trans('esp::maintenance.tasks.maintainer') }}</th>
                                    <th>{{ trans('esp::maintenance.tasks.updated') }}</th>
                                    <th>{{ trans('esp::maintenance.tasks.table.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($taskSets['review'] as $task)
                                <tr>
                                    <td><a href="{{ $task->page?->getUrl() }}">{{ $task->page?->name ?? trans('esp::maintenance.tasks.unknown_page') }}</a></td>
                                    <td>{{ $task->maintainer?->name }}</td>
                                    <td>{{ optional($task->updated_at)->diffForHumans() }}</td>
                                    <td class="stack gap-xs">
                                        <a class="text-button" href="{{ $task->page?->getUrl('/revisions') }}">{{ trans('esp::maintenance.tasks.view_revisions') }}</a>
                                        <form action="{{ route('maintenance.approve', ['page' => $task->page_id]) }}" method="POST" class="inline">
                                            @csrf
                                            <button class="text-button success">{{ trans('esp::maintenance.tasks.approve') }}</button>
                                        </form>
                                        <form action="{{ route('maintenance.reject', ['page' => $task->page_id]) }}" method="POST" class="stack gap-xs">
                                            @csrf
                                            <input type="text" name="reason" class="outline" placeholder="{{ trans('esp::maintenance.tasks.reason') }}" required>
                                            <button class="text-button neg">{{ trans('esp::maintenance.tasks.reject') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
