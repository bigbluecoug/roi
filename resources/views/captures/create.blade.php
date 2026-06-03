<x-layouts.app title="Capture · Lead Capture">
    <div class="hero-row">
        <div>
            <h1>Capture</h1>
            <p class="subhead">{{ $selectedEvent->name }} · {{ $selectedEvent->state_code }} {{ $stateName }}</p>
        </div>
        <a class="button secondary" href="{{ route('setup.events') }}">Switch Event</a>
    </div>

    @if ($lastBatchCaptures->isNotEmpty())
        <section
            class="item-card batch-panel"
            data-batch-panel
            data-status-url="{{ route('captures.status') }}"
            data-capture-ids="{{ $lastBatchCaptures->pluck('id')->implode(',') }}"
            style="margin-bottom: 18px;"
        >
            <div class="row">
                <div>
                    <h2 class="item-title">Last batch</h2>
                    <div class="meta" data-batch-summary>{{ $lastBatchCaptures->count() }} queued {{ $lastBatchCaptures->count() === 1 ? 'capture' : 'captures' }}</div>
                </div>
                <a class="button secondary compact" href="{{ route('events.show', $selectedEvent) }}">Open Event</a>
            </div>
            <div class="batch-list">
                @foreach ($lastBatchCaptures as $capture)
                    <article class="batch-row" data-capture-row="{{ $capture->id }}">
                        <div>
                            <strong data-capture-name>{{ $capture->displayName() }}</strong>
                            <div class="meta" data-capture-meta>
                                {{ $capture->usableEmail() ?? $capture->organization ?? $capture->district?->name ?? 'Waiting for AI' }}
                            </div>
                        </div>
                        <span class="badge {{ $capture->statusBadgeClass() }}" data-capture-status>{{ $capture->statusLabel() }}</span>
                        <a class="button secondary compact" data-capture-review href="{{ route('captures.review', $capture) }}">Review</a>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="panel">
        <form id="capture-form" method="post" action="{{ route('captures.store') }}" enctype="multipart/form-data" class="stack">
            @csrf
            <input type="hidden" name="event_id" value="{{ $selectedEventId }}">
            <div class="event-context">
                <span class="badge">{{ $selectedEvent->state_code }}</span>
                <strong>{{ $selectedEvent->name }}</strong>
                <small>{{ $selectedEvent->starts_on?->format('M j, Y') ?? 'Date TBD' }}{{ $selectedEvent->venue ? ' · '.$selectedEvent->venue : '' }}</small>
            </div>
            <div class="photo-picker">
                <label for="photos">Badge or Card Photos</label>
                <input id="photos" name="photos[]" type="file" accept="image/*,.heic,.heif" multiple>
                <span>Add up to 12 separate badge or business-card photos. Submit once, then keep capturing while AI processes them.</span>
                <div class="photo-status" id="photo-status" aria-live="polite"></div>
                <div class="photo-tray" id="photo-tray" hidden></div>
            </div>
            <div>
                <label for="rep_notes">Rep Notes</label>
                <textarea id="rep_notes" name="rep_notes">{{ old('rep_notes') }}</textarea>
            </div>
            <button class="button accent capture-submit" type="submit" disabled>Queue Photos for AI</button>
        </form>
    </section>

    <script>
        (() => {
            const input = document.getElementById('photos');
            const status = document.getElementById('photo-status');
            const tray = document.getElementById('photo-tray');
            const form = document.getElementById('capture-form');
            const button = form.querySelector('button[type="submit"]');
            const maxFiles = 12;
            const maxBytes = 1600 * 1024;
            const serverMaxBytes = 20 * 1024 * 1024;
            const maxDimension = 1600;
            const imageNamePattern = /\.(avif|bmp|gif|heic|heif|jpe?g|png|tiff?|webp)$/i;
            const items = [];

            const formatBytes = (bytes) => {
                if (!bytes) return '0 KB';
                if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
                return `${Math.round(bytes / 1024)} KB`;
            };

            const setStatus = (message, isError = false) => {
                status.textContent = message;
                status.classList.toggle('is-visible', Boolean(message));
                status.classList.toggle('is-error', isError);
            };

            const looksLikeImage = (file) => (
                file.type.startsWith('image/') || imageNamePattern.test(file.name)
            );

            const loadImage = (file) => new Promise((resolve, reject) => {
                const url = URL.createObjectURL(file);
                const image = new Image();
                image.onload = () => {
                    URL.revokeObjectURL(url);
                    resolve(image);
                };
                image.onerror = () => {
                    URL.revokeObjectURL(url);
                    reject(new Error('Could not read the selected image.'));
                };
                image.src = url;
            });

            const canvasToBlob = (canvas, quality) => new Promise((resolve) => {
                canvas.toBlob(resolve, 'image/jpeg', quality);
            });

            const compressImage = async (file) => {
                const image = await loadImage(file);
                let scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
                let quality = 0.82;
                let bestBlob = null;

                for (let attempt = 0; attempt < 6; attempt++) {
                    const width = Math.max(1, Math.round(image.width * scale));
                    const height = Math.max(1, Math.round(image.height * scale));
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const context = canvas.getContext('2d');
                    context.drawImage(image, 0, 0, width, height);

                    for (let q = quality; q >= 0.44; q -= 0.08) {
                        const blob = await canvasToBlob(canvas, q);
                        if (!blob) continue;
                        bestBlob = blob;
                        if (blob.size <= maxBytes) return blob;
                    }

                    scale *= 0.82;
                    quality = 0.76;
                }

                return bestBlob;
            };

            const asCompressedFile = (blob, originalFile) => new File(
                [blob],
                originalFile.name.replace(/\.[^.]+$/, '') + '-lead-capture.jpg',
                { type: 'image/jpeg', lastModified: Date.now() }
            );

            const activeItems = () => items.filter((item) => !item.removed);

            const syncSubmitState = () => {
                const preparing = activeItems().some((item) => item.status === 'preparing');
                const readyCount = activeItems().filter((item) => item.status === 'ready').length;
                button.disabled = preparing || readyCount === 0;
                button.textContent = readyCount === 1 ? 'Queue 1 Photo for AI' : `Queue ${readyCount} Photos for AI`;
            };

            const renderTray = () => {
                tray.textContent = '';
                const visibleItems = activeItems();
                tray.hidden = visibleItems.length === 0;

                visibleItems.forEach((item) => {
                    const card = document.createElement('article');
                    card.className = 'photo-chip';

                    const preview = document.createElement('img');
                    preview.alt = item.file.name;
                    preview.src = item.previewUrl;
                    card.appendChild(preview);

                    const copy = document.createElement('div');
                    copy.className = 'photo-chip-copy';

                    const name = document.createElement('strong');
                    name.textContent = item.file.name;
                    copy.appendChild(name);

                    const meta = document.createElement('span');
                    meta.textContent = item.status === 'ready'
                        ? `${formatBytes(item.file.size)} ready`
                        : item.message;
                    copy.appendChild(meta);
                    card.appendChild(copy);

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'button secondary compact';
                    remove.textContent = 'Remove';
                    remove.addEventListener('click', () => {
                        item.removed = true;
                        URL.revokeObjectURL(item.previewUrl);
                        renderTray();
                        syncSubmitState();
                    });
                    card.appendChild(remove);

                    tray.appendChild(card);
                });

                syncSubmitState();
            };

            const prepareFile = async (file) => {
                if (activeItems().length >= maxFiles) {
                    setStatus(`You can queue ${maxFiles} photos at a time. Submit this batch, then add more.`, true);
                    return;
                }

                if (!looksLikeImage(file)) {
                    setStatus(`${file.name} is not an image. Choose Camera, Photos, or Files images only.`, true);
                    return;
                }

                if (file.size > serverMaxBytes) {
                    setStatus(`${file.name} is ${formatBytes(file.size)}. Choose an image under ${formatBytes(serverMaxBytes)}.`, true);
                    return;
                }

                const item = {
                    file,
                    message: `Preparing ${formatBytes(file.size)} photo...`,
                    previewUrl: URL.createObjectURL(file),
                    removed: false,
                    status: 'preparing',
                };
                items.push(item);
                renderTray();
                setStatus(`Preparing ${activeItems().length} ${activeItems().length === 1 ? 'photo' : 'photos'}...`);

                try {
                    const blob = await compressImage(file);
                    if (blob && blob.size <= serverMaxBytes) {
                        item.file = asCompressedFile(blob, file);
                    }
                    item.status = 'ready';
                    item.message = `${formatBytes(item.file.size)} ready`;
                } catch (error) {
                    item.status = 'ready';
                    item.message = 'Will convert after upload';
                }

                renderTray();
                setStatus(`${activeItems().filter((candidate) => candidate.status === 'ready').length} ${activeItems().length === 1 ? 'photo' : 'photos'} ready to queue.`);
            };

            input.addEventListener('change', async () => {
                const files = Array.from(input.files || []);
                input.value = '';

                for (const file of files) {
                    await prepareFile(file);
                }
            });

            form.addEventListener('submit', (event) => {
                const readyItems = activeItems().filter((item) => item.status === 'ready');

                if (readyItems.length === 0) {
                    event.preventDefault();
                    setStatus('Choose at least one badge or card photo.', true);
                    return;
                }

                const dataTransfer = new DataTransfer();
                readyItems.forEach((item) => dataTransfer.items.add(item.file));
                input.files = dataTransfer.files;
                setStatus(`Queueing ${readyItems.length} ${readyItems.length === 1 ? 'photo' : 'photos'} for AI...`);
            }, { capture: true });
        })();

        (() => {
            const panel = document.querySelector('[data-batch-panel]');
            if (!(panel instanceof HTMLElement)) {
                return;
            }

            const ids = panel.dataset.captureIds;
            const statusUrl = panel.dataset.statusUrl;
            const summary = panel.querySelector('[data-batch-summary]');
            if (!ids || !statusUrl || !(summary instanceof HTMLElement)) {
                return;
            }

            const rows = new Map(Array.from(panel.querySelectorAll('[data-capture-row]')).map((row) => [row.dataset.captureRow, row]));

            const updateRows = (captures) => {
                let pending = 0;

                captures.forEach((capture) => {
                    const row = rows.get(String(capture.id));
                    if (!(row instanceof HTMLElement)) {
                        return;
                    }

                    const name = row.querySelector('[data-capture-name]');
                    const meta = row.querySelector('[data-capture-meta]');
                    const status = row.querySelector('[data-capture-status]');
                    const review = row.querySelector('[data-capture-review]');

                    if (name instanceof HTMLElement) name.textContent = capture.display_name;
                    if (meta instanceof HTMLElement) {
                        meta.textContent = capture.email || capture.organization || capture.district || (capture.public_enrichment_status ? `Email ${capture.public_enrichment_status}` : 'Waiting for AI');
                    }
                    if (status instanceof HTMLElement) {
                        status.textContent = capture.status_label;
                        status.className = `badge ${capture.status_badge_class}`;
                    }
                    if (review instanceof HTMLAnchorElement) {
                        review.href = capture.review_url;
                    }

                    if (!capture.ready_for_review) {
                        pending += 1;
                    }
                });

                const total = captures.length;
                summary.textContent = pending === 0
                    ? `${total} ${total === 1 ? 'capture' : 'captures'} ready for review`
                    : `${pending} processing, ${total - pending} ready`;

                return pending;
            };

            const poll = () => {
                fetch(`${statusUrl}?ids=${encodeURIComponent(ids)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((response) => response.ok ? response.json() : null)
                    .then((payload) => {
                        if (!payload || !Array.isArray(payload.captures)) {
                            return;
                        }

                        if (updateRows(payload.captures) > 0) {
                            window.setTimeout(poll, 2500);
                        }
                    })
                    .catch(() => window.setTimeout(poll, 5000));
            };

            poll();
        })();
    </script>
</x-layouts.app>
