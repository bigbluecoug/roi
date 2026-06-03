<?php

namespace App\Jobs;

use App\Models\Capture;
use App\Services\CaptureImageNormalizer;
use App\Services\DistrictMatcher;
use App\Services\HubSpotClient;
use App\Services\OpenAiLeadExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCaptureImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $captureId)
    {
    }

    public function handle(
        CaptureImageNormalizer $normalizer,
        OpenAiLeadExtractor $extractor,
        DistrictMatcher $matcher,
        HubSpotClient $hubSpot,
    ): void {
        $capture = Capture::query()->with(['event', 'district'])->find($this->captureId);

        if (! $capture || ! $capture->image_path) {
            return;
        }

        $capture->forceFill([
            'status' => Capture::STATUS_PROCESSING,
            'sync_error' => null,
        ])->save();

        $originalPath = $capture->image_path;

        try {
            $normalized = $normalizer->normalizeStored($originalPath, $capture->original_filename);
            $normalizedPath = $normalized['path'];
        } catch (Throwable $exception) {
            report($exception);
            $this->markExtractionFailed($capture, $exception->getMessage());

            return;
        }

        try {
            $extracted = $extractor->extract($normalizedPath);
        } catch (Throwable $exception) {
            report($exception);
            $capture->forceFill([
                'image_path' => $normalizedPath,
                'original_filename' => $normalized['filename'],
            ])->save();
            $this->deleteOriginalIfReplaced($originalPath, $normalizedPath);
            $this->markExtractionFailed($capture, 'AI extraction failed. Review and enter fields manually.');

            return;
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

        $match = $matcher->match($capture->event, $matchInput);
        $matchReason = $match['reason'];
        if ($hubSpotContext['contact'] || $hubSpotContext['company']) {
            $matchReason .= ' Existing HubSpot '.($hubSpotContext['contact'] ? 'contact' : 'company').' found.';
        }

        $capture->forceFill([
            'district_id' => $match['district']?->id,
            'status' => Capture::STATUS_COMPLETE,
            'image_path' => $normalizedPath,
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
            'sync_error' => null,
        ])->save();

        $this->deleteOriginalIfReplaced($originalPath, $normalizedPath);

        $freshCapture = $capture->fresh();
        if ($freshCapture && $freshCapture->shouldAutoFindPublicEmail()) {
            $this->markPublicEmailQueued($freshCapture);
            FindPublicEmailForCapture::dispatch($freshCapture->id)
                ->onConnection(config('services.capture.processing_queue', 'background'));
        }
    }

    private function markExtractionFailed(Capture $capture, string $message): void
    {
        $capture->forceFill([
            'status' => Capture::STATUS_EXTRACTION_FAILED,
            'sync_error' => $message,
        ])->save();
    }

    private function markPublicEmailQueued(Capture $capture): void
    {
        $payload = $capture->extracted_payload ?? [];
        $payload['public_enrichment'] = [
            'status' => 'queued',
            'email' => null,
            'confidence' => 0,
            'person_match' => null,
            'organization_match' => null,
            'summary' => 'Public email search is queued after AI extraction.',
            'sources' => [],
            'checked_at' => now()->toIso8601String(),
        ];

        $capture->forceFill([
            'extracted_payload' => $payload,
        ])->save();
    }

    private function deleteOriginalIfReplaced(string $originalPath, string $normalizedPath): void
    {
        if ($originalPath !== $normalizedPath) {
            Storage::disk('local')->delete($originalPath);
        }
    }
}
