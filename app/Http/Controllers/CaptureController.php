<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCaptureImage;
use App\Models\Capture;
use App\Models\District;
use App\Models\Event;
use App\Services\CaptureImageNormalizer;
use App\Services\DistrictMatcher;
use App\Services\HubSpotClient;
use App\Services\OpenAiLeadExtractor;
use App\Services\PublicLeadEnricher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class CaptureController extends Controller
{
    public function index(): View
    {
        return view('captures.index', [
            'captures' => Capture::query()
                ->with(['event', 'district'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->filled('event')) {
            $event = Event::query()->where('active', true)->findOrFail((int) $request->query('event'));
            $request->session()->put('current_state_code', $event->state_code);
            $request->session()->put('current_event_id', $event->id);
        }

        $selectedEvent = Event::query()
            ->where('active', true)
            ->find($request->session()->get('current_event_id'));

        if (! $selectedEvent) {
            return $request->session()->has('current_state_code')
                ? redirect()->route('setup.events')
                : redirect()->route('setup.state');
        }

        $events = Event::query()
            ->where('active', true)
            ->where('state_code', $selectedEvent->state_code)
            ->orderBy('name')
            ->get();

        return view('captures.create', [
            'events' => $events,
            'lastBatchCaptures' => $this->lastBatchCaptures($request),
            'selectedEvent' => $selectedEvent,
            'selectedEventId' => $selectedEvent->id,
            'stateName' => EventController::STATES[$selectedEvent->state_code] ?? $selectedEvent->state_code,
        ]);
    }

    public function store(Request $request, CaptureImageNormalizer $normalizer): RedirectResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'photo' => ['nullable', 'file', 'required_without:photos'],
            'photos' => ['nullable', 'array', 'max:12', 'required_without:photo'],
            'photos.*' => ['file'],
            'rep_notes' => ['nullable', 'string', 'max:2000'],
            'append_to_last_batch' => ['nullable', 'boolean'],
        ]);

        $event = Event::findOrFail($data['event_id']);
        $request->session()->put('current_state_code', $event->state_code);
        $request->session()->put('current_event_id', $event->id);

        $files = collect($request->file('photos', []));
        if ($request->hasFile('photo')) {
            $files->push($request->file('photo'));
        }

        if ($files->isEmpty()) {
            return back()->withErrors(['photo' => 'Choose at least one badge or card photo.'])->withInput();
        }

        if ($files->count() > 12) {
            return back()->withErrors(['photos' => 'Choose 12 or fewer photos at a time.'])->withInput();
        }

        $captureIds = [];
        foreach ($files as $file) {
            $capture = Capture::create([
                'user_id' => $request->user()->id,
                'event_id' => $event->id,
                'status' => Capture::STATUS_QUEUED,
                'rep_notes' => $data['rep_notes'] ?? null,
                'sync_error' => null,
            ]);

            try {
                $stored = $normalizer->storeOriginal($file);
                $capture->forceFill([
                    'image_path' => $stored['path'],
                    'original_filename' => $stored['filename'],
                ])->save();

                ProcessCaptureImage::dispatch($capture->id);
            } catch (Throwable $exception) {
                report($exception);

                $capture->forceFill([
                    'status' => Capture::STATUS_EXTRACTION_FAILED,
                    'original_filename' => $file->getClientOriginalName() ?: null,
                    'sync_error' => 'This photo could not be queued: '.$exception->getMessage(),
                ])->save();
            }

            $captureIds[] = $capture->id;
        }

        $sessionCaptureIds = $request->boolean('append_to_last_batch')
            ? collect((array) $request->session()->get('last_capture_batch_ids', []))
                ->merge($captureIds)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all()
            : $captureIds;

        $request->session()->put('last_capture_batch_ids', $sessionCaptureIds);

        return redirect()
            ->route('captures.create')
            ->with('status', $files->count().' '.($files->count() === 1 ? 'photo' : 'photos').' queued for AI processing and public email search. Keep capturing while we work.');
    }

    public function status(Request $request): JsonResponse
    {
        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->map(fn (string $id) => (int) trim($id))
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['captures' => []]);
        }

        $captures = Capture::query()
            ->with(['district', 'event'])
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Capture $capture) => $ids->search($capture->id))
            ->values();

        $this->forgetLastBatchIfComplete($request, $captures);

        $captures = $captures->map(fn (Capture $capture) => [
            'id' => $capture->id,
            'status' => $capture->status,
            'status_label' => $capture->statusLabel(),
            'status_badge_class' => $capture->statusBadgeClass(),
            'display_name' => $capture->displayName(),
            'email' => $capture->usableEmail(),
            'organization' => $capture->organization,
            'district' => $capture->district?->name,
            'public_enrichment_status' => $capture->publicEnrichmentStatus(),
            'automation_pending' => $capture->automationPending(),
            'ready_for_review' => $capture->reviewReady(),
            'review_url' => route('captures.review', $capture),
        ]);

        return response()->json(['captures' => $captures]);
    }

    private function lastBatchCaptures(Request $request)
    {
        $ids = collect((array) $request->session()->get('last_capture_batch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $captures = Capture::query()
            ->with(['event', 'district'])
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Capture $capture) => $ids->search($capture->id))
            ->values();

        if ($this->forgetLastBatchIfComplete($request, $captures)) {
            return collect();
        }

        return $captures;
    }

    private function forgetLastBatchIfComplete(Request $request, $captures): bool
    {
        $lastBatchIds = collect((array) $request->session()->get('last_capture_batch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($lastBatchIds->isEmpty()) {
            return false;
        }

        $captureIds = $captures->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        if ($captureIds->all() !== $lastBatchIds->sort()->values()->all()) {
            return false;
        }

        if ($captures->every(fn (Capture $capture) => ! $capture->automationPending())) {
            $request->session()->forget('last_capture_batch_ids');

            return true;
        }

        return false;
    }

    public function show(Capture $capture): View
    {
        return $this->review($capture);
    }

    public function review(Capture $capture): View
    {
        $capture->load(['event', 'district']);

        return view('captures.show', [
            'capture' => $capture,
            'districts' => District::query()
                ->where('state_code', $capture->event->state_code)
                ->orderByDesc('total_students')
                ->get(),
        ]);
    }

    public function update(Request $request, Capture $capture): RedirectResponse
    {
        $data = $this->validateReviewData($request);
        $this->applyReviewData($capture, $data, markReviewed: true);

        return redirect()->route('captures.review', $capture)->with('status', 'Capture updated.');
    }

    private function validateReviewData(Request $request, bool $requireDistrict = true): array
    {
        return $request->validate([
            'district_id' => [$requireDistrict ? 'required' : 'nullable', 'exists:districts,id'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Capture::looksMaskedEmail($value)) {
                        $fail('Enter the complete email address, not a masked directory result.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['nullable', 'string'],
            'rep_notes' => ['nullable', 'string', 'max:4000'],
            'follow_up_status' => [$requireDistrict ? 'required' : 'nullable', 'string', 'in:new,follow_up,meeting,not_fit'],
        ]);
    }

    private function applyReviewData(Capture $capture, array $data, bool $markReviewed): void
    {
        if (array_key_exists('full_name', $data) && blank($data['full_name'] ?? null)) {
            $data['full_name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: null;
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = isset($data['email']) ? strtolower($data['email']) : null;
        }

        if ($markReviewed) {
            $data['status'] = $capture->status === Capture::STATUS_SYNCED ? Capture::STATUS_SYNCED : Capture::STATUS_REVIEWED;
        }

        $data['sync_error'] = null;

        $capture->update($data);
    }

    public function sync(Capture $capture, HubSpotClient $hubSpot): RedirectResponse
    {
        try {
            $result = $hubSpot->syncCapture($capture->load(['event', 'district']));
            $capture->forceFill([
                'status' => Capture::STATUS_SYNCED,
                'hubspot_contact_id' => $result['contact_id'],
                'hubspot_company_id' => $result['company_id'],
                'hubspot_note_id' => $result['note_id'],
                'synced_at' => now(),
                'sync_error' => null,
            ])->save();

            return redirect()->route('captures.review', $capture)->with('status', 'Added to HubSpot.');
        } catch (Throwable $exception) {
            report($exception);
            $capture->forceFill([
                'status' => Capture::STATUS_SYNC_FAILED,
                'sync_error' => $exception->getMessage(),
            ])->save();

            return redirect()->route('captures.review', $capture)->withErrors(['hubspot' => $exception->getMessage()]);
        }
    }

    public function destroy(Request $request, Capture $capture): RedirectResponse
    {
        $event = $capture->event;

        if ($capture->image_path) {
            Storage::disk('local')->delete($capture->image_path);
        }

        $capture->delete();

        $redirect = $request->string('return_to')->toString() === 'event' && $event
            ? redirect()->route('events.show', $event)
            : redirect()->route('captures.index');

        return $redirect->with('status', 'Lead deleted from the local capture log.');
    }

    public function reprocess(Capture $capture, OpenAiLeadExtractor $extractor, DistrictMatcher $matcher, HubSpotClient $hubSpot, PublicLeadEnricher $publicEnricher): RedirectResponse
    {
        if (! $capture->image_path || ! Storage::disk('local')->exists($capture->image_path)) {
            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['photo' => 'There is no stored image to reprocess. Retake or upload the badge/card photo.']);
        }

        if (! config('services.openai.key')) {
            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['openai' => 'Add OPENAI_API_KEY to .env before using AI extraction.']);
        }

        try {
            $extracted = $extractor->extract($capture->image_path);
            $hubSpotContext = $hubSpot->lookupLeadContext($extracted['email'], $extracted['organization']);
            $matchInput = $extracted;
            $hubSpotCompanyName = $hubSpotContext['company']['properties']['name'] ?? null;
            $hubSpotContactCompany = $hubSpotContext['contact']['properties']['company'] ?? null;
            if (blank($matchInput['organization']) && filled($hubSpotCompanyName ?: $hubSpotContactCompany)) {
                $matchInput['organization'] = $hubSpotCompanyName ?: $hubSpotContactCompany;
            }

            $match = $matcher->match($capture->event, $matchInput);
            $matchReason = $match['reason'];
            if ($hubSpotContext['contact'] || $hubSpotContext['company']) {
                $matchReason .= ' Existing HubSpot '.($hubSpotContext['contact'] ? 'contact' : 'company').' found.';
            }

            $capture->forceFill([
                'district_id' => $match['district']?->id,
                'status' => Capture::STATUS_COMPLETE,
                'full_name' => $extracted['full_name'],
                'first_name' => $extracted['first_name'],
                'last_name' => $extracted['last_name'],
                'email' => $extracted['email'],
                'phone' => $extracted['phone'],
                'title' => $extracted['title'],
                'organization' => $extracted['organization'],
                'city' => $extracted['city'],
                'state' => $extracted['state'],
                'raw_text' => $extracted['raw_text'],
                'confidence' => $extracted['confidence'],
                'evidence' => $extracted['evidence'],
                'extracted_payload' => $extracted['extracted_payload'],
                'ai_confidence' => $extracted['ai_confidence'],
                'match_confidence' => $match['confidence'],
                'match_reason' => $matchReason,
                'sync_error' => null,
            ])->save();

            $autoMessage = $this->runAutomaticPublicEmailSearch($capture, $publicEnricher);
            $message = trim('AI extraction refreshed from the stored image. '.($autoMessage ?? ''));

            return redirect()->route('captures.review', $capture)->with('status', $message);
        } catch (ConnectionException $exception) {
            report($exception);

            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['openai' => 'AI extraction could not connect. Check the network connection and try again.']);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['openai' => 'AI extraction could not run: '.$exception->getMessage()]);
        }
    }

    public function webEnrich(Request $request, Capture $capture, PublicLeadEnricher $enricher): RedirectResponse
    {
        if ($this->requestHasReviewContext($request)) {
            $this->applyReviewData($capture, $this->validateReviewData($request, requireDistrict: false), markReviewed: false);
        }

        if (! config('services.openai.key')) {
            $this->recordPublicEnrichmentError($capture, 'OpenAI API key is not configured.');

            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['web_enrichment' => 'Add OPENAI_API_KEY to .env before using public email search.']);
        }

        try {
            $capture->load(['event', 'district']);
            $enrichment = $this->existingPublicEnrichment($capture) ?: $enricher->enrich($capture);
            $emailApplied = $this->applyPublicEnrichment($capture, $enrichment);

            $message = $emailApplied
                ? 'Public email found and added to the capture.'
                : 'Public email search finished. Review the source details before making changes.';

            return redirect()->route('captures.review', $capture)->with('status', $message);
        } catch (ConnectionException $exception) {
            report($exception);
            $this->recordPublicEnrichmentError($capture, 'OpenAI web search could not connect.');

            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['web_enrichment' => 'OpenAI web search could not connect. Check the network connection and restart the local dev server with internet access.']);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordPublicEnrichmentError($capture, $exception->getMessage());

            return redirect()
                ->route('captures.review', $capture)
                ->withErrors(['web_enrichment' => 'Public email search could not run: '.$exception->getMessage()]);
        }
    }

    private function requestHasReviewContext(Request $request): bool
    {
        foreach ([
            'district_id',
            'full_name',
            'first_name',
            'last_name',
            'email',
            'phone',
            'title',
            'organization',
            'city',
            'state',
            'raw_text',
            'rep_notes',
            'follow_up_status',
        ] as $field) {
            if ($request->exists($field)) {
                return true;
            }
        }

        return false;
    }

    public function image(Capture $capture)
    {
        abort_unless($capture->image_path && Storage::disk('local')->exists($capture->image_path), 404);

        return response()->file(Storage::disk('local')->path($capture->image_path), [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function destroyImage(Capture $capture): RedirectResponse
    {
        if ($capture->image_path) {
            Storage::disk('local')->delete($capture->image_path);
            $capture->forceFill([
                'image_path' => null,
                'image_purged_at' => now(),
            ])->save();
        }

        return redirect()->route('captures.review', $capture)->with('status', 'Capture image removed.');
    }

    private function canApplyEnrichmentEmail(array $enrichment): bool
    {
        if (($enrichment['status'] ?? null) !== 'found') {
            return false;
        }

        if (! Capture::isUsableEmail($enrichment['email'] ?? null)) {
            return false;
        }

        if ((float) ($enrichment['confidence'] ?? 0) < 0.65) {
            return false;
        }

        return collect($enrichment['sources'] ?? [])->contains(fn ($source) => filled($source['url'] ?? null));
    }

    private function runAutomaticPublicEmailSearch(Capture $capture, PublicLeadEnricher $enricher): ?string
    {
        if (! config('services.openai.key') || ! $capture->shouldAutoFindPublicEmail()) {
            return null;
        }

        try {
            $capture->load(['event', 'district']);
            $enrichment = $this->existingPublicEnrichment($capture) ?: $enricher->enrich($capture);
            $emailApplied = $this->applyPublicEnrichment($capture, $enrichment);

            return $emailApplied
                ? 'Public email found and added to the capture.'
                : 'Public email search finished. Review the source details before making changes.';
        } catch (ConnectionException $exception) {
            report($exception);
            $this->recordPublicEnrichmentError($capture, 'OpenAI web search could not connect.');

            return 'Public email search could not connect.';
        } catch (Throwable $exception) {
            report($exception);
            $this->recordPublicEnrichmentError($capture, $exception->getMessage());

            return 'Public email search could not run.';
        }
    }

    private function applyPublicEnrichment(Capture $capture, array $enrichment): bool
    {
        $enrichment = $this->sanitizePublicEnrichment($enrichment);
        $payload = $capture->extracted_payload ?? [];
        $payload['public_enrichment'] = $enrichment;

        $updates = [
            'extracted_payload' => $payload,
            'sync_error' => null,
        ];

        $emailApplied = false;
        if (blank($capture->email) && $this->canApplyEnrichmentEmail($enrichment)) {
            $updates['email'] = $enrichment['email'];
            $updates['status'] = $capture->status === Capture::STATUS_SYNCED
                ? Capture::STATUS_SYNCED
                : Capture::STATUS_COMPLETE;
            $emailApplied = true;
        }

        $capture->forceFill($updates)->save();

        return $emailApplied;
    }

    private function sanitizePublicEnrichment(array $enrichment): array
    {
        foreach (['summary', 'person_match', 'organization_match'] as $key) {
            if (array_key_exists($key, $enrichment)) {
                $enrichment[$key] = Capture::redactMaskedEmailText($enrichment[$key]);
            }
        }

        $enrichment['sources'] = collect($enrichment['sources'] ?? [])
            ->map(function ($source) {
                if (is_array($source) && array_key_exists('evidence', $source)) {
                    $source['evidence'] = Capture::redactMaskedEmailText($source['evidence']);
                }

                return $source;
            })
            ->all();

        if (! Capture::looksMaskedEmail($enrichment['email'] ?? null)) {
            return $enrichment;
        }

        $summary = trim((string) ($enrichment['summary'] ?? ''));
        $maskedMessage = 'The source only exposed a masked email, so it was not applied.';

        $enrichment['email'] = null;
        $enrichment['status'] = ($enrichment['status'] ?? null) === 'found' ? 'ambiguous' : ($enrichment['status'] ?? 'ambiguous');
        $enrichment['summary'] = $summary === '' ? $maskedMessage : $summary.' '.$maskedMessage;

        return $enrichment;
    }

    private function recordPublicEnrichmentError(Capture $capture, string $message): void
    {
        $payload = $capture->extracted_payload ?? [];
        $payload['public_enrichment'] = [
            'status' => 'error',
            'email' => null,
            'confidence' => 0,
            'person_match' => null,
            'organization_match' => null,
            'summary' => $message,
            'sources' => [],
            'checked_at' => now()->toIso8601String(),
        ];

        $capture->forceFill([
            'extracted_payload' => $payload,
        ])->save();
    }

    private function existingPublicEnrichment(Capture $capture): ?array
    {
        $name = trim((string) ($capture->full_name ?: trim(($capture->first_name ?? '').' '.($capture->last_name ?? ''))));
        $organization = trim((string) $capture->organization);

        if (blank($name) || blank($organization)) {
            return null;
        }

        $candidate = Capture::query()
            ->whereKeyNot($capture->id)
            ->whereNotNull('email')
            ->whereRaw('lower(full_name) = ?', [strtolower($name)])
            ->whereRaw('lower(organization) = ?', [strtolower($organization)])
            ->latest()
            ->get()
            ->first(fn (Capture $candidate) => $candidate->publicEnrichmentSources() !== [] && filled($candidate->usableEmail()));

        if (! $candidate) {
            return null;
        }

        $sourceEnrichment = $candidate->publicEnrichment();

        return [
            'status' => 'found',
            'email' => $candidate->usableEmail(),
            'confidence' => max(0.9, (float) ($sourceEnrichment['confidence'] ?? 0)),
            'person_match' => 'Matched prior sourced capture #'.$candidate->id.' for '.$name.'.',
            'organization_match' => 'Organization matches '.$organization.'.',
            'summary' => 'Reused a public email already found for this same person and organization.',
            'sources' => $candidate->publicEnrichmentSources(),
            'checked_at' => now()->toIso8601String(),
            'reused_from_capture_id' => $candidate->id,
        ];
    }
}
