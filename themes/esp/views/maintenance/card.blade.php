@php
    $record = $record ?? null;
    $service = $service ?? null;
    $statusOptions = $service?->getStatusOptions() ?? [];
    $statusLabel = $record ? ($statusOptions[$record->status] ?? $record->status) : null;
    $userOptions = ($userOptions ?? collect());
@endphp

<div class="entity-details maintenance-card mb-l">
    <h5>{{ trans('esp::maintenance.card.title') }}</h5>
    <div class="blended-links">
        @if($record)
            <div class="entity-meta-item">
                @icon('user')
                <div>
                    <div class="text-muted text-small">{{ trans('esp::maintenance.card.maintainer') }}</div>
                    <div>{{ $record->maintainer?->name ?? trans('esp::maintenance.card.not_assigned') }}</div>
                </div>
            </div>
            <div class="entity-meta-item">
                @icon('calendar')
                <div>
                    <div class="text-muted text-small">{{ trans('esp::maintenance.card.period') }}</div>
                    <div>{{ $record->period_days }} {{ trans('esp::maintenance.card.days_suffix') }}</div>
                </div>
            </div>
            <div class="entity-meta-item">
                @icon('history')
                <div>
                    <div class="text-muted text-small">{{ trans('esp::maintenance.card.last_review') }}</div>
                    <div>{{ optional($record->last_reviewed_at)->format('Y-m-d H:i') ?? trans('esp::maintenance.card.never') }}</div>
                </div>
            </div>
            <div class="entity-meta-item">
                @icon('clock')
                <div>
                    <div class="text-muted text-small">{{ trans('esp::maintenance.card.next_due') }}</div>
                    <div>{{ optional($record->next_due_at)->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>
            <div class="entity-meta-item">
                @icon('flag')
                <div>
                    <div class="text-muted text-small">{{ trans('esp::maintenance.card.status') }}</div>
                    <div>
                        <span class="tag outline small">
                            {{ $statusLabel }}
                        </span>
                    </div>
                </div>
            </div>
            @if($record->last_rejected_reason)
                <div class="entity-meta-item">
                    @icon('close')
                    <div>
                        <div class="text-muted text-small">{{ trans('esp::maintenance.card.last_rejection_reason') }}</div>
                        <div>{{ $record->last_rejected_reason }}</div>
                    </div>
                </div>
            @endif
        @else
            <p class="text-muted">{{ trans('esp::maintenance.card.not_configured') }}</p>
        @endif
    </div>

    <div class="mt-m">
        @if(user()->hasSystemRole('admin'))
            <form action="{{ route('maintenance.assign', ['page' => $page->id]) }}" method="POST" class="stack gap-s mb-m">
                @csrf
                <div>
                    <label class="text-small text-muted">{{ trans('esp::maintenance.card.select_maintainer') }}</label>
                    <select name="maintainer_user_id" class="outline">
                        @foreach($userOptions as $userOption)
                            <option value="{{ $userOption->id }}" @if($record && $record->maintainer_user_id === $userOption->id) selected @endif>
                                {{ $userOption->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-small text-muted">{{ trans('esp::maintenance.card.period_days') }}</label>
                    <input type="number" name="period_days" class="outline" value="{{ $record->period_days ?? 30 }}" min="1" max="365">
                </div>
                <button type="submit" class="button">{{ trans('esp::maintenance.card.save') }}</button>
            </form>
        @endif

        @if($record && $record->maintainer_user_id === user()->id && in_array($record->status, [\EspTheme\Logic\PageMaintenance::STATUS_DUE_SOON, \EspTheme\Logic\PageMaintenance::STATUS_OVERDUE]))
            <form action="{{ route('maintenance.start', ['page' => $page->id]) }}" method="POST" class="mb-s">
                @csrf
                <button class="button outline">{{ trans('esp::maintenance.buttons.start_update') }}</button>
            </form>
        @endif

        @if($record && $record->maintainer_user_id === user()->id && $record->status === \EspTheme\Logic\PageMaintenance::STATUS_IN_UPDATE)
            <form action="{{ route('maintenance.submit', ['page' => $page->id]) }}" method="POST" class="mb-s">
                @csrf
                <button class="button">{{ trans('esp::maintenance.buttons.submit_review') }}</button>
            </form>
        @endif

        @if(user()->hasSystemRole('admin') && $record && $record->status === \EspTheme\Logic\PageMaintenance::STATUS_IN_REVIEW)
            <div class="stack gap-s">
                <form action="{{ route('maintenance.approve', ['page' => $page->id]) }}" method="POST">
                    @csrf
                    <button class="button success">{{ trans('esp::maintenance.buttons.approve') }}</button>
                </form>
                <form action="{{ route('maintenance.reject', ['page' => $page->id]) }}" method="POST" class="stack gap-xs">
                    @csrf
                    <label class="text-small text-muted" for="reject-reason">{{ trans('esp::maintenance.card.rejection_reason') }}</label>
                    <textarea id="reject-reason" name="reason" class="outline" rows="2" required></textarea>
                    <button class="button neg">{{ trans('esp::maintenance.buttons.reject') }}</button>
                </form>
            </div>
        @endif
    </div>
</div>
