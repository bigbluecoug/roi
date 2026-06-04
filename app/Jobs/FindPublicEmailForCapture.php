<?php

namespace App\Jobs;

use App\Models\Capture;
use App\Services\PublicLeadEnricher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FindPublicEmailForCapture implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $captureId)
    {
    }

    public function handle(PublicLeadEnricher $enricher): void
    {
        $capture = Capture::query()->with(['event', 'district'])->find($this->captureId);

        if (! $capture || ! $capture->hasPublicEmailSearchClues()) {
            return;
        }

        $this->recordPublicEnrichment($capture, [
            'status' => 'searching',
            'email' => null,
            'confidence' => 0,
            'person_match' => null,
            'organization_match' => null,
            'summary' => 'Public email search is running.',
            'sources' => [],
            'checked_at' => now()->toIso8601String(),
        ]);

        try {
            $this->applyPublicEnrichment($capture, $enricher->enrich($capture));
        } catch (Throwable $exception) {
            report($exception);

            $this->recordPublicEnrichment($capture, [
                'status' => 'error',
                'email' => null,
                'confidence' => 0,
                'person_match' => null,
                'organization_match' => null,
                'summary' => 'Public email search could not run: '.$exception->getMessage(),
                'sources' => [],
                'checked_at' => now()->toIso8601String(),
            ]);
        }
    }

    private function applyPublicEnrichment(Capture $capture, array $enrichment): void
    {
        $enrichment = $this->sanitizePublicEnrichment($enrichment);

        $updates = [
            'sync_error' => null,
        ];

        if ($this->shouldApplyEnrichmentEmail($capture, $enrichment)) {
            $updates['email'] = $enrichment['email'];
            $updates['status'] = $capture->status === Capture::STATUS_SYNCED
                ? Capture::STATUS_SYNCED
                : Capture::STATUS_COMPLETE;
        }

        $payload = $capture->extracted_payload ?? [];
        $payload['public_enrichment'] = $enrichment;
        $updates['extracted_payload'] = $payload;

        $capture->forceFill($updates)->save();
    }

    private function recordPublicEnrichment(Capture $capture, array $enrichment): void
    {
        $payload = $capture->extracted_payload ?? [];
        $payload['public_enrichment'] = $enrichment;

        $capture->forceFill([
            'extracted_payload' => $payload,
        ])->save();
    }

    private function canApplyEnrichmentEmail(array $enrichment): bool
    {
        return ($enrichment['status'] ?? null) === 'found'
            && Capture::isUsableEmail($enrichment['email'] ?? null)
            && (float) ($enrichment['confidence'] ?? 0) >= 0.78
            && collect($enrichment['sources'] ?? [])->contains(fn ($source) => filled($source['url'] ?? null));
    }

    private function shouldApplyEnrichmentEmail(Capture $capture, array $enrichment): bool
    {
        if (! $this->canApplyEnrichmentEmail($enrichment)) {
            return false;
        }

        $candidateEmail = strtolower(trim((string) $enrichment['email']));
        $currentEmail = $capture->usableEmail();

        if (! $currentEmail) {
            return true;
        }

        return $capture->status === Capture::STATUS_COMPLETE
            && $currentEmail !== $candidateEmail;
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
}
