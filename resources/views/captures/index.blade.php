@php
    $event = $event ?? null;
    $isEventLog = $event !== null;
@endphp

<x-layouts.app title="{{ $isEventLog ? $event->name.' Log · Edchange Event Capture' : 'Capture Log · Edchange Event Capture' }}">
    <div class="hero-row">
        <div>
            <h1>{{ $isEventLog ? $event->name.' Log' : 'Capture Log' }}</h1>
            <p class="subhead">
                @if ($isEventLog)
                    {{ $event->state_code }} · {{ $stateName }} · Review, correct, and sync contacts for this event.
                @else
                    Review, correct, and sync conference contacts across every event.
                @endif
            </p>
        </div>
        <div class="row hero-actions">
            @if ($isEventLog)
                <a class="button secondary" href="{{ route('events.show', $event) }}">Back to Event</a>
                <a class="button accent" href="{{ route('captures.create', ['event' => $event->id]) }}">Capture Lead</a>
            @else
                <a class="button accent" href="{{ route('captures.create') }}">New Capture</a>
            @endif
        </div>
    </div>

    @if ($captures->isEmpty())
        <div class="empty">{{ $isEventLog ? 'No captures for this event yet.' : 'No captures yet.' }}</div>
    @else
        <section class="panel table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Organization</th>
                        @unless ($isEventLog)
                            <th>Event</th>
                        @endunless
                        <th>District</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($captures as $capture)
                        <tr>
                            <td>
                                <strong>{{ $capture->displayName() }}</strong><br>
                                <span class="meta">
                                    @if ($capture->stillProcessing())
                                        AI is reading this photo
                                    @elseif (in_array($capture->publicEnrichmentStatus(), ['queued', 'searching'], true))
                                        Public email search {{ $capture->publicEnrichmentStatus() }}
                                    @else
                                        {{ $capture->usableEmail() ?? 'No email' }}
                                    @endif
                                </span>
                            </td>
                            <td>{{ $capture->organization ?? ($capture->stillProcessing() ? 'Processing capture' : 'Organization unconfirmed') }}</td>
                            @unless ($isEventLog)
                                <td>
                                    <a href="{{ route('events.log', $capture->event) }}">{{ $capture->event->state_code }} · {{ $capture->event->name }}</a>
                                </td>
                            @endunless
                            <td>{{ $capture->district?->name ?? 'Unconfirmed' }}</td>
                            <td>
                                <span class="badge {{ $capture->statusBadgeClass() }}">
                                    {{ $capture->statusLabel() }}
                                </span>
                            </td>
                            <td>
                                <div class="row" style="justify-content: flex-start;">
                                    <a class="button secondary" href="{{ route('captures.review', $capture) }}">Open</a>
                                    <form method="post" action="{{ route('captures.destroy', $capture) }}" onsubmit="return confirm('Delete this lead from the local capture log? This will not remove any HubSpot records.');">
                                        @csrf
                                        @method('delete')
                                        @if ($isEventLog)
                                            <input type="hidden" name="return_to" value="event_log">
                                        @endif
                                        <button class="button danger" type="submit" data-busy-label="Deleting...">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <div style="margin-top: 16px;">
            {{ $captures->links() }}
        </div>
    @endif
</x-layouts.app>
