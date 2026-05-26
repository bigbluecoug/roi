<x-layouts.app title="Events · Lead Capture">
    <div class="hero-row">
        <div>
            <h1>{{ $stateName }} Events</h1>
            <p class="subhead">Choose the conference you are attending, or create it here before capturing leads.</p>
        </div>
        <a class="button secondary" href="{{ route('setup.state') }}">Switch State</a>
    </div>

    <div class="grid" style="margin-bottom: 18px;">
        @foreach ($events as $event)
            <a class="item-card event-card" href="{{ route('events.show', $event) }}">
                <div class="row">
                    <span class="badge">{{ $event->state_code }}</span>
                    <span class="meta">{{ $event->captures_count }} {{ $event->captures_count === 1 ? 'capture' : 'captures' }}</span>
                </div>
                <h2 class="item-title">{{ $event->name }}</h2>
                <div class="meta">
                    {{ $event->starts_on?->format('M j, Y') ?? 'Date TBD' }}
                    @if ($event->venue)
                        · {{ $event->venue }}
                    @endif
                </div>
                <span class="button {{ (int) $currentEventId === $event->id ? 'accent' : 'secondary' }}">
                    {{ (int) $currentEventId === $event->id ? 'View Captures' : 'Open Event' }}
                </span>
            </a>
        @endforeach
    </div>

    @if ($events->isEmpty())
        <div class="empty" style="margin-bottom: 18px;">No active events exist for {{ $stateName }} yet. Create one below.</div>
    @endif

    <section class="panel">
        <form method="post" action="{{ route('events.store') }}" class="stack">
            @csrf
            <input type="hidden" name="state_code" value="{{ $stateCode }}">
            <div class="field-grid">
                <div>
                    <label for="name">Event Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required>
                </div>
                <div>
                    <label>State</label>
                    <div class="readonly-field">{{ $stateCode }} · {{ $stateName }}</div>
                </div>
                <div>
                    <label for="starts_on">Start Date</label>
                    <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on') }}">
                </div>
                <div>
                    <label for="venue">Venue</label>
                    <input id="venue" name="venue" value="{{ old('venue') }}">
                </div>
            </div>
            <div>
                <label for="notes">Event Notes</label>
                <textarea id="notes" name="notes">{{ old('notes') }}</textarea>
            </div>
            <div>
                <button class="button accent" type="submit">Create and Start Capturing</button>
            </div>
        </form>
    </section>
</x-layouts.app>
