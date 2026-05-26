<x-layouts.app title="Capture · Lead Capture">
    <div class="hero-row">
        <div>
            <h1>Capture</h1>
            <p class="subhead">{{ $selectedEvent->name }} · {{ $selectedEvent->state_code }} {{ $stateName }}</p>
        </div>
        <a class="button secondary" href="{{ route('setup.events') }}">Switch Event</a>
    </div>

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
                <label for="photo">Badge or Card Photo</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*" capture="environment" required>
                <span>On iPhone, this opens the camera so you can photograph the badge or business card.</span>
                <div class="photo-status" id="photo-status" aria-live="polite"></div>
            </div>
            <div>
                <label for="rep_notes">Rep Notes</label>
                <textarea id="rep_notes" name="rep_notes">{{ old('rep_notes') }}</textarea>
            </div>
            <button class="button accent capture-submit" type="submit">Take Photo and Extract Lead</button>
        </form>
    </section>

    <script>
        (() => {
            const input = document.getElementById('photo');
            const status = document.getElementById('photo-status');
            const form = document.getElementById('capture-form');
            const button = form.querySelector('button[type="submit"]');
            const maxBytes = 1600 * 1024;
            const serverMaxBytes = 20 * 1024 * 1024;
            const maxDimension = 1600;
            const imageNamePattern = /\.(avif|bmp|gif|heic|heif|jpe?g|png|tiff?|webp)$/i;

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

            const replaceInputFile = (blob, originalFile) => {
                const compressedFile = new File(
                    [blob],
                    originalFile.name.replace(/\.[^.]+$/, '') + '-lead-capture.jpg',
                    { type: 'image/jpeg', lastModified: Date.now() }
                );
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(compressedFile);
                input.files = dataTransfer.files;
                input.dataset.prepared = 'true';

                return compressedFile;
            };

            input.addEventListener('change', async () => {
                const file = input.files && input.files[0];
                if (!file) {
                    setStatus('');
                    return;
                }

                input.dataset.prepared = 'false';

                if (!looksLikeImage(file)) {
                    setStatus('Choose an image from the camera or photo library.', true);
                    return;
                }

                if (file.size > serverMaxBytes) {
                    setStatus(`This photo is ${formatBytes(file.size)}. Retake it or choose an image under ${formatBytes(serverMaxBytes)}.`, true);
                    return;
                }

                button.disabled = true;
                setStatus(`Preparing ${formatBytes(file.size)} photo for upload...`);

                try {
                    const blob = await compressImage(file);
                    if (!blob) {
                        throw new Error('Browser could not decode this image.');
                    }

                    if (blob.size > serverMaxBytes) {
                        setStatus('This photo is still too large. Retake it closer to the badge or choose a smaller image.', true);
                        return;
                    }

                    const compressedFile = replaceInputFile(blob, file);
                    const statusVerb = compressedFile.size < file.size ? 'reduced' : 'formatted';
                    setStatus(`Photo ${statusVerb} as JPEG (${formatBytes(compressedFile.size)}) before upload.`);
                } catch (error) {
                    setStatus('This photo will be converted after upload.');
                } finally {
                    button.disabled = false;
                }
            });

            form.addEventListener('submit', (event) => {
                const file = input.files && input.files[0];
                if (file && file.size > serverMaxBytes) {
                    event.preventDefault();
                    setStatus(`This photo is too large to upload. Choose an image under ${formatBytes(serverMaxBytes)}.`, true);
                    return;
                }

                if (file && input.dataset.prepared !== 'true') {
                    setStatus('Uploading photo for server conversion...');
                }
            }, { capture: true });
        })();
    </script>
</x-layouts.app>
