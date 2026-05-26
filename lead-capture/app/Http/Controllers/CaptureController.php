<?php

namespace App\Http\Controllers;

use App\Models\Capture;
use App\Models\District;
use App\Models\Event;
use App\Services\CaptureImageNormalizer;
use App\Services\DistrictMatcher;
use App\Services\HubSpotClient;
use App\Services\OpenAiLeadExtractor;
use App\Services\PublicLeadEnricher;
use Illuminate\Http\Client\ConnectionException;
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
            'selectedEvent' => $selectedEvent,
            'selectedEventId' => $selectedEvent->id,
            'stateName' => EventController::STATES[$selectedEvent->state_code] ?? $selectedEvent->state_code,
        ]);
    }

    public function store(Request $request, CaptureImageNormalizer $normalizer, OpenAiLeadExtractor $extractor, DistrictMatcher $matcher, HubSpotClient $hubSpot): RedirectResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
            'photo' => ['required', 'file', 'max:20480'],
            'rep_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = Event::findOrFail($data['event_id']);
        $request->session()->put('current_state_code', $event->state_code);
        $request->session()->put('current_event_id', $event->id);

        $file = $request->file('photo');

        try {
            $normalized = $normalizer->normalize($file);
            $path = $normalized['path'];
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['photo' => $exception->getMessage()])
                ->withInput();
        }

        try {
            $extracted = $extractor->extract($path);
            $syncError = null;
        } catch (Throwable $exception) {
            report($exception);
            $extracted = [
                'full_name' => null,
                'first_name' => null,
                'last_name' => null,
                'email' => null,
                'phone' => null,
                'title' => null,
                'organization' => null,
                'city' => null,
                'state' => null,
                'raw_text' => null,
                'confidence' => ['overall' => 0],
                'evidence' => [],
                'warnings' => [$exception->getMessage()],
                'insights' => [],
                'ai_confidence' => 0,
                'extracted_payload' => [],
            ];
            $syncError = 'AI extraction failed. Review and enter fields manually.';
        }

        $hubSpotContext = ['contact' => null, 'company' => null];
        try {
            $hubSpotContext = $hubSpot->lookupLeadContext($extracted['email'], $extracted['organization']);
        } catch (Throwable $exception) {
            report($exception);
        }

        $matchInput = $extracted;
        $hubSpotCompanyName = $hubSpotContext['company']['properties']['name'] ?? null;
        $hubSpotContactCompany = $hubSpotContext['contact']['properties']['company'] ?? null;
        if (blank($matchInput['organization']) && filled($hubSpotCompanyName ?: $hubSpotContactCompany)) {
            $matchInput['organization'] = $hubSpotCompanyName ?: $hubSpotContactCompany;
        }

        $match = $matcher->match($event, $matchInput);
        $matchReason = $match['reason'];
        if ($hubSpotContext['contact'] || $hubSpotContext['company']) {
            $matchReason .= ' Existing HubSpot '.($hubSpotContext['contact'] ? 'contact' : 'company').' found.';
        }

        $capture = Capture::create([
            'user_id' => $request->user()->id,
            'event_id' => $event->id,
            'district_id' => $match['district']?->id,
            'status' => Capture::STATUS_NEEDS_REVIEW,
            'image_path' => $path,
            'original_filename' => $normalized['filename'],
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
            'rep_notes' => $data['rep_notes'] ?? null,
            'sync_error' => $syncError,
        ]);

        return redirect()->route('captures.review', $capture)->with('status', 'Capture ready for review.');
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
        $data = $request->validate([
            'district_id' => ['required', 'exists:districts,id'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['nullable', 'string'],
            'rep_notes' => ['nullable', 'string', 'max:4000'],
            'follow_up_status' => ['required', 'string', 'in:new,follow_up,meeting,not_fit'],
        ]);

        if (blank($data['full_name'] ?? null)) {
            $data['full_name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: null;
        }

        $data['email'] = isset($data['email']) ? strtolower($data['email']) : null;
        $data['status'] = $capture->status === Capture::STATUS_SYNCED ? Capture::STATUS_SYNCED : Capture::STATUS_REVIEWED;
        $data['sync_error'] = null;

        $capture->update($data);

        return redirect()->route('captures.review', $capture)->with('status', 'Capture updated.');
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

    public function reprocess(Capture $capture, OpenAiLeadExtractor $extractor, DistrictMatcher $matcher, HubSpotClient $hubSpot): RedirectResponse
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
                'status' => Capture::STATUS_NEEDS_REVIEW,
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

            return redirect()->route('captures.review', $capture)->with('status', 'AI extraction refreshed from the stored image.');
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

    public function webEnrich(Capture $capture, PublicLeadEnricher $enricher): RedirectResponse
    {
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
                ? 'Public email found and added for review.'
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

        if (! filter_var($enrichment['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ((float) ($enrichment['confidence'] ?? 0) < 0.65) {
            return false;
        }

        return collect($enrichment['sources'] ?? [])->contains(fn ($source) => filled($source['url'] ?? null));
    }

    private function applyPublicEnrichment(Capture $capture, array $enrichment): bool
    {
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
                : Capture::STATUS_NEEDS_REVIEW;
            $emailApplied = true;
        }

        $capture->forceFill($updates)->save();

        return $emailApplied;
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
            ->first(fn (Capture $candidate) => $candidate->publicEnrichmentSources() !== []);

        if (! $candidate) {
            return null;
        }

        $sourceEnrichment = $candidate->publicEnrichment();

        return [
            'status' => 'found',
            'email' => $candidate->email,
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
