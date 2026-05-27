<x-layouts.app title="Capture Log · Event Lead Capture">
    <div class="hero-row">
        <div>
            <h1>Capture Log</h1>
            <p class="subhead">Review, correct, and sync conference contacts.</p>
        </div>
        <a class="button accent" href="{{ route('captures.create') }}">New Capture</a>
    </div>

    @if ($captures->isEmpty())
        <div class="empty">No captures yet.</div>
    @else
        <section class="panel table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Organization</th>
                        <th>Event</th>
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
                                <span class="meta">{{ $capture->usableEmail() ?? 'No email' }}</span>
                            </td>
                            <td>{{ $capture->organization ?? 'Needs review' }}</td>
                            <td>{{ $capture->event->state_code }} · {{ $capture->event->name }}</td>
                            <td>{{ $capture->district?->name ?? 'Unconfirmed' }}</td>
                            <td>
                                <span class="badge {{ $capture->status === 'synced' ? 'synced' : ($capture->status === 'sync_failed' ? 'failed' : 'review') }}">
                                    {{ str_replace('_', ' ', $capture->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="row" style="justify-content: flex-start;">
                                    <a class="button secondary" href="{{ route('captures.review', $capture) }}">Open</a>
                                    <form method="post" action="{{ route('captures.destroy', $capture) }}" onsubmit="return confirm('Delete this lead from the local capture log? This will not remove any HubSpot records.');">
                                        @csrf
                                        @method('delete')
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
