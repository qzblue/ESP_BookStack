@extends('layouts.simple')

@section('title', trans('esp::maintenance.overview.title'))

@section('body')
<div class="grid-xl">
    <div class="col-12">
        <div class="card">
            <h1 class="card-title">{{ trans('esp::maintenance.overview.heading') }}</h1>
            <div class="body">
                <div class="grid half pad-s justify-between items-center stretch">
                    <div class="card light">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-muted text-small">{{ trans('esp::maintenance.overview.summary.total') }}</div>
                                <div class="text-large">{{ $summary['total'] ?? 0 }}</div>
                            </div>
                            <div class="text-small text-muted">{{ trans('esp::maintenance.overview.summary.scope') }}</div>
                        </div>
                        <div class="flex gap-m wrap mt-s">
                            @foreach($service->getStatusOptions() as $status => $label)
                                @php $count = $summary['statuses'][$status] ?? 0; @endphp
                                <span class="tag small outline {{ $count ? '' : 'muted' }}">{{ $label }} — {{ $count }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="card light">
                        <form method="get" class="flex wrap gap-m items-end">
                            <div>
                                <label class="text-small text-muted" for="status-filter">{{ trans('esp::maintenance.overview.filters.status') }}</label>
                                <select id="status-filter" name="status">
                                    <option value="">{{ trans('common.all') }}</option>
                                    @foreach($service->getStatusOptions() as $status => $label)
                                        <option value="{{ $status }}" @if($selectedStatus === $status) selected @endif>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-small text-muted" for="maintainer-filter">{{ trans('esp::maintenance.overview.filters.maintainer') }}</label>
                                <select id="maintainer-filter" name="maintainer">
                                    <option value="">{{ trans('common.all') }}</option>
                                    @foreach($maintainers as $maintainer)
                                        <option value="{{ $maintainer->id }}" @if($selectedMaintainer === $maintainer->id) selected @endif>
                                            {{ $maintainer->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <button class="button">{{ trans('esp::maintenance.overview.filters.apply') }}</button>
                            </div>
                        </form>
                    </div>
                </div>

                @if($records->isEmpty())
                    <p class="text-muted mt-m">{{ trans('esp::maintenance.overview.empty') }}</p>
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
