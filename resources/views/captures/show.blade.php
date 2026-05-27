<x-layouts.app title="Review Capture · Event Lead Capture">
    <div class="hero-row">
        <div>
            <h1>{{ $capture->displayName() }}</h1>
            <p class="subhead">{{ $capture->event->state_code }} · {{ $capture->event->name }}</p>
        </div>
        <div class="row hero-actions">
            <a class="button secondary" href="{{ route('events.show', $capture->event) }}">Back to Event</a>
            <a class="button secondary" href="{{ route('captures.index') }}">Back to Log</a>
            <form method="post" action="{{ route('captures.destroy', $capture) }}" onsubmit="return confirm('Delete this lead from the local capture log? This will not remove any HubSpot records.');">
                @csrf
                @method('delete')
                <input type="hidden" name="return_to" value="event">
                <button class="button danger" type="submit" data-busy-label="Deleting...">Delete Lead</button>
            </form>
        </div>
    </div>

    <div class="review-layout">
        <section class="stack review-media">
            @if ($capture->image_path)
                <img class="capture-image" src="{{ route('captures.image', $capture) }}" alt="Captured badge or business card">
                <form method="post" action="{{ route('captures.reprocess', $capture) }}">
                    @csrf
                    <button class="button accent" type="submit" data-busy-label="Reading Image...">Re-run AI from Image</button>
                </form>
                <form method="post" action="{{ route('captures.image.destroy', $capture) }}">
                    @csrf
                    @method('delete')
                    <button class="button danger" type="submit">Remove Image</button>
                </form>
            @else
                <div class="empty">Image removed from capture log.</div>
            @endif

            <form
                id="web-enrich-form"
                method="post"
                action="{{ route('captures.web-enrich', $capture) }}"
                @if ($capture->shouldAutoFindPublicEmail()) data-auto-web-enrich="true" data-testid="auto-public-email-enabled" @endif
            >
                @csrf
                <button class="button secondary" type="submit" data-busy-label="Searching...">Find Public Email</button>
            </form>

            <article class="item-card">
                <div class="row">
                    <span class="badge {{ $capture->status === 'synced' ? 'synced' : ($capture->status === 'sync_failed' ? 'failed' : 'review') }}">
                        {{ str_replace('_', ' ', $capture->status) }}
                    </span>
                    <span class="meta">AI {{ number_format((float) $capture->ai_confidence, 2) }}</span>
                </div>
                <div class="meta">{{ $capture->match_reason ?? 'District match pending.' }}</div>
                @if ($capture->sync_error)
                    <div class="alert error" style="margin:0;">{{ $capture->sync_error }}</div>
                @endif
            </article>

            @php
                $insights = $capture->aiInsights();
                $scalarInsights = [
                    'role_category' => 'Role category',
                    'seniority' => 'Seniority',
                    'organization_type' => 'Organization type',
                    'lead_priority' => 'Lead priority',
                    'buyer_relevance' => 'Buyer relevance',
                    'suggested_follow_up' => 'Suggested follow-up',
                    'caveat' => 'Caveat',
                ];
                $publicEnrichment = $capture->publicEnrichment();
                $publicSources = $capture->publicEnrichmentSources();
            @endphp

            @if ($insights)
                <article class="item-card">
                    <div class="row">
                        <h2 class="item-title">Badge Clues</h2>
                        <span class="badge">AI</span>
                    </div>
                    <ul class="insight-list">
                        @foreach ($scalarInsights as $key => $label)
                            @if (filled($insights[$key] ?? null))
                                <li>
                                    <strong>{{ $label }}</strong>
                                    {{ $insights[$key] }}
                                </li>
                            @endif
                        @endforeach
                        @foreach (['district_clues' => 'District clues', 'missing_fields' => 'Missing fields'] as $key => $label)
                            @if (! empty($insights[$key]))
                                <li>
                                    <strong>{{ $label }}</strong>
                                    {{ implode('; ', (array) $insights[$key]) }}
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </article>
            @endif

            @if ($publicEnrichment)
                <article class="item-card">
                    <div class="row">
                        <h2 class="item-title">Public Email Search</h2>
                        <span class="badge {{ ($publicEnrichment['status'] ?? null) === 'found' ? 'synced' : 'review' }}">
                            {{ str_replace('_', ' ', $publicEnrichment['status'] ?? 'checked') }}
                        </span>
                    </div>
                    <ul class="insight-list">
                        @if (filled($publicEnrichment['email'] ?? null))
                            <li>
                                <strong>Email</strong>
                                {{ $publicEnrichment['email'] }}
                            </li>
                        @endif
                        @if (isset($publicEnrichment['confidence']))
                            <li>
                                <strong>Confidence</strong>
                                {{ number_format((float) $publicEnrichment['confidence'], 2) }}
                            </li>
                        @endif
                        @foreach ([
                            'summary' => 'Summary',
                            'person_match' => 'Person match',
                            'organization_match' => 'Organization match',
                        ] as $key => $label)
                            @if (filled($publicEnrichment[$key] ?? null))
                                <li>
                                    <strong>{{ $label }}</strong>
                                    {{ $publicEnrichment[$key] }}
                                </li>
                            @endif
                        @endforeach
                        @foreach ($publicSources as $source)
                            <li>
                                <strong>{{ $source['title'] ?? 'Public source' }}</strong>
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['url'] }}</a>
                                @if (filled($source['evidence'] ?? null))
                                    <div>{{ $source['evidence'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endif
        </section>

        <section class="panel review-panel">
            <form method="post" action="{{ route('captures.update', $capture) }}" class="stack">
                @csrf
                @method('patch')

                <div class="field-grid">
                    <div>
                        <label for="first_name">First Name</label>
                        <input id="first_name" name="first_name" value="{{ old('first_name', $capture->first_name) }}">
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" value="{{ old('last_name', $capture->last_name) }}">
                    </div>
                </div>

                <div>
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" value="{{ old('full_name', $capture->full_name) }}">
                </div>

                <div class="field-grid">
                    <div>
                        <div class="row inline-field-action">
                            <label for="email" style="margin-bottom: 6px;">Email</label>
                            <button class="button secondary compact" type="submit" form="web-enrich-form" data-busy-label="Searching...">Find Public Email</button>
                        </div>
                        <input id="email" name="email" type="email" value="{{ old('email', $capture->email) }}">
                    </div>
                    <div>
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" value="{{ old('phone', $capture->phone) }}">
                    </div>
                </div>

                <div class="field-grid">
                    <div>
                        <label for="title">Title</label>
                        <input id="title" name="title" value="{{ old('title', $capture->title) }}">
                    </div>
                    <div>
                        <label for="organization">Organization</label>
                        <input id="organization" name="organization" value="{{ old('organization', $capture->organization) }}">
                    </div>
                </div>

                <div class="field-grid">
                    <div>
                        <label for="city">City</label>
                        <input id="city" name="city" value="{{ old('city', $capture->city) }}">
                    </div>
                    <div>
                        <label for="state">State</label>
                        <input id="state" name="state" value="{{ old('state', $capture->state) }}">
                    </div>
                </div>

                <div>
                    <label for="district_id">District</label>
                    <select id="district_id" name="district_id" required>
                        <option value="">Select district</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}" @selected((int) old('district_id', $capture->district_id) === $district->id)>
                                {{ $district->name }} · {{ number_format($district->total_students) }} students
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="follow_up_status">Follow-Up Status</label>
                    <select id="follow_up_status" name="follow_up_status" required>
                        @foreach (['new' => 'New', 'follow_up' => 'Follow up', 'meeting' => 'Meeting', 'not_fit' => 'Not fit'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('follow_up_status', $capture->follow_up_status) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="rep_notes">Rep Notes</label>
                    <textarea id="rep_notes" name="rep_notes">{{ old('rep_notes', $capture->rep_notes) }}</textarea>
                </div>

                <div>
                    <label for="raw_text">Visible Text</label>
                    <textarea id="raw_text" name="raw_text">{{ old('raw_text', $capture->raw_text) }}</textarea>
                </div>

                <div class="row form-actions">
                    <button class="button" type="submit">Save Review</button>
                </div>
            </form>

            <form class="sync-form" method="post" action="{{ route('captures.sync', $capture) }}">
                @csrf
                <button class="button accent" type="submit" @disabled(! $capture->readyForHubSpot())>Add to HubSpot</button>
            </form>
        </section>
    </div>
</x-layouts.app>
