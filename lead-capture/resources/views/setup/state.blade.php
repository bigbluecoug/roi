<x-layouts.app title="Choose State · Lead Capture">
    <div class="hero-row">
        <div>
            <h1>Choose State</h1>
            <p class="subhead">Start each conference by choosing the territory state. Next you will pick or create the event.</p>
        </div>
    </div>

    <section class="panel">
        <form method="post" action="{{ route('setup.state.store') }}" class="stack">
            @csrf
            <div class="state-grid">
                @foreach ($states as $code => $name)
                    <label class="choice-card">
                        <input type="radio" name="state_code" value="{{ $code }}" @checked(old('state_code', $currentStateCode) === $code) required>
                        <span>
                            <strong>{{ $code }}</strong>
                            <small>{{ $name }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
            <button class="button accent" type="submit">Continue to Events</button>
        </form>
    </section>
</x-layouts.app>
