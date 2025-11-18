@extends('layouts.simple')

@section('title', trans('esp::maintenance.overview.title'))

@section('body')
<div class="grid-xl">
    <div class="col-12">
        <div class="card">
            <h1 class="card-title">{{ trans('esp::maintenance.overview.heading') }}</h1>
            <div class="body">
                @if($records->isEmpty())
                    <p class="text-muted">{{ trans('esp::maintenance.overview.empty') }}</p>
                @else
                    <table class="table">
                        <thead>
                        <tr>
                            <th>{{ trans('esp::maintenance.overview.table.page') }}</th>
                            <th>{{ trans('esp::maintenance.overview.table.location') }}</th>
                            <th>{{ trans('esp::maintenance.overview.table.maintainer') }}</th>
                            <th>{{ trans('esp::maintenance.overview.table.period') }}</th>
                            <th>{{ trans('esp::maintenance.overview.table.status') }}</th>
                            <th>{{ trans('esp::maintenance.overview.table.next_due') }}</th>
                            @if($canAdminister)
                                <th>{{ trans('esp::maintenance.overview.table.reviewed_at') }}</th>
                            @endif
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($records as $record)
                            <tr>
                                <td>
                                    <a href="{{ $record->page?->getUrl() }}">{{ $record->page?->name ?? trans('esp::maintenance.tasks.unknown_page') }}</a>
                                </td>
                                <td class="text-small text-muted">
                                    {{ $record->page?->book?->name ?? '—' }}
                                    @if($record->page?->chapter)
                                        — {{ $record->page->chapter->name }}
                                    @endif
                                </td>
                                <td>{{ $record->maintainer?->name ?? trans('esp::maintenance.card.not_assigned') }}</td>
                                <td>{{ $service->formatPeriod($record) }}</td>
                                <td><span class="tag outline small">{{ $service->getStatusOptions()[$record->status] ?? $record->status }}</span></td>
                                <td>{{ $service->formatDateTime($record->next_due_at) }}</td>
                                @if($canAdminister)
                                    <td>{{ $service->formatDateTime($record->last_reviewed_at) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
