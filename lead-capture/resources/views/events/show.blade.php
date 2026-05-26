<x-layouts.app title="{{ $event->name }} · Lead Capture">
    <div class="hero-row">
        <div>
            <h1>{{ $event->name }}</h1>
            <p class="subhead">
                {{ $event->state_code }} · {{ $stateName }}
                @if ($event->starts_on)
                    · {{ $event->starts_on->format('M j, Y') }}
                @else
                    · Date TBD
                @endif
                @if ($event->venue)
                    · {{ $event->venue }}
                @endif
            </p>
        </div>
        <div class="row" style="justify-content: flex-end;">
            <a class="button secondary" href="{{ route('events.index') }}">Back to Events</a>
            <a class="button accent" href="{{ route('captures.create', ['event' => $event->id]) }}">Capture Lead</a>
        </div>
    </div>

    <section class="item-card" style="margin-bottom: 18px;">
        <div class="row">
            <span class="badge">{{ $event->captures_count }} {{ $event->captures_count === 1 ? 'capture' : 'captures' }}</span>
            <a class="button secondary" href="{{ route('captures.index') }}">Full Log</a>
        </div>
        @if ($event->notes)
            <div class="meta">{{ $event->notes }}</div>
        @endif
    </section>

    @if ($captures->isEmpty())
        <div class="empty">No people captured for this event yet.</div>
    @else
        <div class="grid">
            @foreach ($captures as $capture)
                <article class="item-card capture-card">
                    <div class="row">
                        <span class="badge {{ $capture->status === 'synced' ? 'synced' : ($capture->status === 'sync_failed' ? 'failed' : 'review') }}">
                            {{ str_replace('_', ' ', $capture->status) }}
                        </span>
                        <span class="meta">{{ $capture->created_at?->format('M j, g:i A') }}</span>
                    </div>
                    <div>
                        <h2 class="item-title">{{ $capture->displayName() }}</h2>
                        <div class="meta">{{ $capture->email ?? 'No email yet' }}</div>
                    </div>
                    <div>
                        <strong>{{ $capture->organization ?? 'Needs organization review' }}</strong>
                        <div class="meta">{{ $capture->district?->name ?? 'District unconfirmed' }}</div>
                    </div>
                    <div class="row">
                        <a class="button secondary" href="{{ route('captures.review', $capture) }}">Open</a>
                        <form method="post" action="{{ route('captures.destroy', $capture) }}" onsubmit="return confirm('Delete this lead from the local capture log? This will not remove any HubSpot records.');">
                            @csrf
                            @method('delete')
                            <input type="hidden" name="return_to" value="event">
                            <button class="button danger" type="submit" data-busy-label="Deleting...">Delete</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top: 16px;">
            {{ $captures->links() }}
        </div>
    @endif
</x-layouts.app>
