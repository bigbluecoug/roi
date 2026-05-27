<?php

namespace App\Services;

use App\Models\Capture;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PublicLeadEnricher
{
    public function enrich(Capture $capture): array
    {
        if (! config('services.openai.key')) {
            throw new RuntimeException('Add OPENAI_API_KEY to .env before using web enrichment.');
        }

        $response = Http::withToken(config('services.openai.key'))
            ->connectTimeout(5)
            ->timeout(45)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('services.openai.search_model', config('services.openai.model', 'gpt-5.4-mini')),
                'tools' => [[
                    'type' => 'web_search',
                    'search_context_size' => 'low',
                ]],
                'tool_choice' => 'required',
                'input' => $this->prompt($capture),
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'public_lead_enrichment',
                        'strict' => false,
                        'schema' => $this->schema(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI web enrichment failed: '.$response->body());
        }

        return $this->normalize($this->decodeResponse($response->json()), $response->json());
    }

    public function normalize(array $payload, array $rawResponse = []): array
    {
        $sources = collect(Arr::wrap(Arr::get($payload, 'sources', [])))
            ->map(fn ($source) => [
                'title' => $this->cleanString(Arr::get($source, 'title')) ?: 'Public source',
                'url' => $this->cleanUrl(Arr::get($source, 'url')),
                'evidence' => Capture::redactMaskedEmailText($this->cleanString(Arr::get($source, 'evidence'))),
            ])
            ->filter(fn ($source) => filled($source['url']))
            ->values()
            ->all();

        foreach ($this->citations($rawResponse) as $citation) {
            if (! collect($sources)->contains('url', $citation['url'])) {
                $sources[] = $citation;
            }
        }

        $status = $this->cleanString(Arr::get($payload, 'status')) ?: 'not_found';
        $status = in_array($status, ['found', 'not_found', 'ambiguous'], true) ? $status : 'not_found';

        return [
            'status' => $status,
            'email' => $this->cleanEmail(Arr::get($payload, 'email')),
            'confidence' => (float) min(1, max(0, round((float) Arr::get($payload, 'confidence', 0), 2))),
            'person_match' => Capture::redactMaskedEmailText($this->cleanString(Arr::get($payload, 'person_match'))),
            'organization_match' => Capture::redactMaskedEmailText($this->cleanString(Arr::get($payload, 'organization_match'))),
            'summary' => Capture::redactMaskedEmailText($this->cleanString(Arr::get($payload, 'summary'))),
            'sources' => $sources,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function prompt(Capture $capture): string
    {
        $name = $capture->full_name ?: trim(($capture->first_name ?? '').' '.($capture->last_name ?? ''));
        $clues = [];
        foreach ([
            'Name' => $name,
            'Title' => $capture->title,
            'Organization' => $capture->organization,
            'City' => $capture->city,
            'State' => $capture->state,
            'District' => $capture->district?->name,
            'Event state' => $capture->event->state_code,
            'Manual notes' => $capture->rep_notes,
            'Visible text' => $capture->raw_text,
        ] as $label => $value) {
            if (filled($value)) {
                $clues[] = $label.': '.$value;
            }
        }

        $insights = implode(' | ', $capture->aiInsightSummary());
        if (filled($insights)) {
            $clues[] = 'Badge clues: '.$insights;
        }

        return implode("\n", [
            'Find a publicly listed professional email address for this conference lead.',
            'Use web search. Prefer official school, district, staff directory, conference, or organization pages.',
            'Use manually entered notes as search clues, but still require public source evidence before returning an email.',
            'Only return an email if the address is directly visible in public search/source evidence and appears to match the person and organization clues.',
            'Do not guess email patterns. Do not create synthetic addresses from a domain. If there is no directly evidenced email, return email null and status "not_found".',
            'Return JSON only. Include source URLs and short evidence notes.',
            '',
            implode("\n", $clues),
        ]);
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'status',
                'email',
                'confidence',
                'person_match',
                'organization_match',
                'summary',
                'sources',
            ],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['found', 'not_found', 'ambiguous']],
                'email' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number'],
                'person_match' => ['type' => ['string', 'null']],
                'organization_match' => ['type' => ['string', 'null']],
                'summary' => ['type' => ['string', 'null']],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'url', 'evidence'],
                        'properties' => [
                            'title' => ['type' => ['string', 'null']],
                            'url' => ['type' => ['string', 'null']],
                            'evidence' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function decodeResponse(array $body): array
    {
        $text = $body['output_text'] ?? null;

        if (! $text) {
            foreach ($body['output'] ?? [] as $item) {
                foreach ($item['content'] ?? [] as $content) {
                    $text = $content['text'] ?? $content['output_text'] ?? $text;
                }
            }
        }

        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'status' => 'ambiguous',
            'email' => null,
            'confidence' => 0,
            'summary' => $text,
            'sources' => [],
        ];
    }

    private function citations(array $body): array
    {
        $citations = [];
        foreach ($body['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    $citation = $annotation['url_citation'] ?? $annotation;
                    if (! is_array($citation)) {
                        continue;
                    }

                    $url = $this->cleanUrl($citation['url'] ?? null);
                    if (! $url) {
                        continue;
                    }

                    $citations[] = [
                        'title' => $this->cleanString($citation['title'] ?? null) ?: 'Public source',
                        'url' => $url,
                        'evidence' => null,
                    ];
                }
            }
        }

        return $citations;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    private function cleanEmail(mixed $value): ?string
    {
        $email = strtolower((string) $this->cleanString($value));

        return Capture::isUsableEmail($email) ? $email : null;
    }

    private function cleanUrl(mixed $value): ?string
    {
        $url = $this->cleanString($value);

        return $url && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
