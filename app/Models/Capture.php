<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Capture extends Model
{
    use HasFactory;

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_SYNC_FAILED = 'sync_failed';

    protected $fillable = [
        'user_id',
        'event_id',
        'district_id',
        'status',
        'image_path',
        'original_filename',
        'image_purged_at',
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
        'confidence',
        'evidence',
        'extracted_payload',
        'ai_confidence',
        'match_confidence',
        'match_reason',
        'rep_notes',
        'follow_up_status',
        'hubspot_contact_id',
        'hubspot_company_id',
        'hubspot_note_id',
        'synced_at',
        'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'array',
            'evidence' => 'array',
            'extracted_payload' => 'array',
            'ai_confidence' => 'float',
            'match_confidence' => 'float',
            'image_purged_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function displayName(): string
    {
        return $this->full_name ?: trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: 'Unlabeled capture';
    }

    public function readyForHubSpot(): bool
    {
        return filled($this->usableEmail()) && filled($this->organization) && $this->district_id !== null;
    }

    public function usableEmail(): ?string
    {
        if (! self::isUsableEmail($this->email)) {
            return null;
        }

        return strtolower(trim((string) $this->email));
    }

    public function publicEnrichmentEmail(): ?string
    {
        $email = $this->publicEnrichment()['email'] ?? null;

        if (! self::isUsableEmail($email)) {
            return null;
        }

        return strtolower(trim((string) $email));
    }

    public static function isUsableEmail(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $email = strtolower(trim((string) $value));

        return $email !== ''
            && ! self::looksMaskedEmail($email)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function looksMaskedEmail(mixed $value): bool
    {
        return is_scalar($value)
            && preg_match('/[*•●◦○□■◼◻…]/u', (string) $value) === 1;
    }

    public static function redactMaskedEmailText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace(
            '/[A-Z0-9._%+\-]*[*•●◦○□■◼◻…]+[A-Z0-9._%+\-]*@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
            '[masked email]',
            $text,
        ) ?? $text;

        return $text === '' ? null : $text;
    }

    public function aiInsights(): array
    {
        $insights = $this->extracted_payload['insights'] ?? [];

        return is_array($insights) ? $insights : [];
    }

    public function aiInsightSummary(): array
    {
        $insights = $this->aiInsights();
        $summary = [];

        foreach ([
            'role_category' => 'Role category',
            'seniority' => 'Seniority',
            'organization_type' => 'Organization type',
            'lead_priority' => 'Lead priority',
            'buyer_relevance' => 'Buyer relevance',
            'suggested_follow_up' => 'Suggested follow-up',
            'caveat' => 'Caveat',
        ] as $key => $label) {
            if (filled($insights[$key] ?? null)) {
                $summary[] = $label.': '.$insights[$key];
            }
        }

        foreach ([
            'district_clues' => 'District clues',
            'missing_fields' => 'Missing fields',
        ] as $key => $label) {
            $items = array_filter((array) ($insights[$key] ?? []));
            if ($items !== []) {
                $summary[] = $label.': '.implode('; ', $items);
            }
        }

        return $summary;
    }

    public function publicEnrichment(): array
    {
        $enrichment = $this->extracted_payload['public_enrichment'] ?? [];

        return is_array($enrichment) ? $enrichment : [];
    }

    public function publicEnrichmentSources(): array
    {
        return array_values(array_filter(
            (array) ($this->publicEnrichment()['sources'] ?? []),
            fn ($source) => is_array($source) && filled($source['url'] ?? null),
        ));
    }

    public function publicEnrichmentSummary(): ?string
    {
        $enrichment = $this->publicEnrichment();

        if ($enrichment === []) {
            return null;
        }

        $parts = array_filter([
            filled($enrichment['status'] ?? null) ? 'Status: '.$enrichment['status'] : null,
            filled($this->publicEnrichmentEmail()) ? 'Email: '.$this->publicEnrichmentEmail() : null,
            isset($enrichment['confidence']) ? 'Confidence: '.$enrichment['confidence'] : null,
            filled(self::redactMaskedEmailText($enrichment['summary'] ?? null)) ? 'Summary: '.self::redactMaskedEmailText($enrichment['summary'] ?? null) : null,
        ]);

        $sources = collect($this->publicEnrichmentSources())
            ->pluck('url')
            ->filter()
            ->values()
            ->all();

        if ($sources !== []) {
            $parts[] = 'Sources: '.implode(' | ', $sources);
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    public function hasPublicEmailSearchClues(): bool
    {
        $hasName = filled($this->full_name) || filled($this->first_name) || filled($this->last_name);
        $hasOrganizationClue = filled($this->organization)
            || filled($this->district?->name)
            || filled($this->raw_text);

        return $hasName && $hasOrganizationClue;
    }

    public function shouldAutoFindPublicEmail(): bool
    {
        return blank($this->usableEmail())
            && $this->publicEnrichment() === []
            && $this->hasPublicEmailSearchClues();
    }
}
