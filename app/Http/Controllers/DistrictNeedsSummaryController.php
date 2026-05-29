<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DistrictNeedsSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'profile.stateCode' => ['nullable', 'string', 'max:30'],
            'profile.stateName' => ['required', 'string', 'max:120'],
            'district.name' => ['required', 'string', 'max:255'],
            'district.city' => ['nullable', 'string', 'max:120'],
            'district.leaId' => ['nullable', 'string', 'max:40'],
            'district.leaType' => ['nullable', 'string', 'max:120'],
            'district.totalStudents' => ['nullable', 'numeric'],
            'district.secondaryStudents' => ['nullable', 'numeric'],
            'district.middleHighSchools' => ['nullable', 'numeric'],
            'district.fullOpportunity' => ['nullable', 'numeric'],
            'district.modeledOpportunity' => ['nullable', 'numeric'],
            'district.targetPenetrationPct' => ['nullable', 'numeric'],
            'currentSignals' => ['nullable', 'array'],
            'planning' => ['nullable', 'array'],
            'tierDrivers' => ['nullable', 'array'],
        ]);

        if (! config('services.openai.key')) {
            return response()->json([
                'message' => 'OpenAI API key is not configured.',
            ], 503);
        }

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->connectTimeout(5)
                ->timeout(60)
                ->acceptJson()
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.search_model', config('services.openai.model', 'gpt-5.4-mini')),
                    'tools' => [[
                        'type' => 'web_search',
                        'search_context_size' => 'medium',
                    ]],
                    'tool_choice' => 'required',
                    'input' => $this->prompt($request->all()),
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'district_needs_summary',
                            'strict' => false,
                            'schema' => $this->schema(),
                        ],
                    ],
                ]);

            if ($response->failed()) {
                throw new RuntimeException('OpenAI district needs summary failed: '.$response->body());
            }

            return response()->json($this->normalize($this->decodeResponse($response->json()), $response->json()));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'District needs summary could not be generated.',
            ], 502);
        }
    }

    private function prompt(array $payload): string
    {
        return implode("\n", [
            'Create a concise AE district-needs summary for Derivita.',
            'Use web search and public sources. Prefer official state report cards, district sites, board packets, curriculum pages, technology/help docs, and procurement pages.',
            'Focus on the context that matters for Derivita territory planning: math state-test performance, Algebra readiness, Canvas/Schoology/Google Classroom or other LMS evidence, Illustrative Math/Open Up Resources/OUR/Big Ideas or other math curriculum evidence, standards/fiscal timing, competitive risk, and what the AE should validate in HubSpot.',
            'Do not guess. If a math-score, LMS, curriculum, or customer-status signal is not source-backed, mark it as Needs validation.',
            'The AE chooses the final tier; provide tier rationale, not an automatic assignment.',
            'Return JSON only in the requested schema with short source evidence and URLs.',
            '',
            'Planner payload:',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function schema(): array
    {
        $section = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'summary', 'evidence', 'confidence'],
            'properties' => [
                'status' => ['type' => 'string'],
                'summary' => ['type' => ['string', 'null']],
                'evidence' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['string', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary',
                'math_test_score',
                'lms',
                'math_curriculum',
                'hubspot',
                'other_context',
                'outreach_angle',
                'tier_rationale',
                'recommended_tier',
                'confidence',
                'validation_checklist',
                'sources',
            ],
            'properties' => [
                'summary' => ['type' => ['string', 'null']],
                'math_test_score' => $section,
                'lms' => $section,
                'math_curriculum' => $section,
                'hubspot' => $section,
                'other_context' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'outreach_angle' => ['type' => ['string', 'null']],
                'tier_rationale' => ['type' => ['string', 'null']],
                'recommended_tier' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['string', 'null']],
                'validation_checklist' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
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

    private function normalize(array $payload, array $rawResponse = []): array
    {
        $sources = collect(Arr::wrap(Arr::get($payload, 'sources', [])))
            ->map(fn ($source) => [
                'title' => $this->cleanString(Arr::get($source, 'title')) ?: 'Public source',
                'url' => $this->cleanUrl(Arr::get($source, 'url')),
                'evidence' => $this->cleanString(Arr::get($source, 'evidence')),
            ])
            ->filter(fn ($source) => filled($source['url']))
            ->values()
            ->all();

        foreach ($this->citations($rawResponse) as $citation) {
            if (! collect($sources)->contains('url', $citation['url'])) {
                $sources[] = $citation;
            }
        }

        return [
            'summary' => $this->cleanString(Arr::get($payload, 'summary')) ?: 'Needs validation before outreach.',
            'math_test_score' => $this->normalizeSection(Arr::get($payload, 'math_test_score')),
            'lms' => $this->normalizeSection(Arr::get($payload, 'lms')),
            'math_curriculum' => $this->normalizeSection(Arr::get($payload, 'math_curriculum')),
            'hubspot' => $this->normalizeSection(Arr::get($payload, 'hubspot')),
            'other_context' => collect(Arr::wrap(Arr::get($payload, 'other_context', [])))
                ->map(fn ($item) => $this->cleanString($item))
                ->filter()
                ->values()
                ->all(),
            'outreach_angle' => $this->cleanString(Arr::get($payload, 'outreach_angle')),
            'tier_rationale' => $this->cleanString(Arr::get($payload, 'tier_rationale')),
            'recommended_tier' => $this->cleanString(Arr::get($payload, 'recommended_tier')),
            'confidence' => $this->cleanString(Arr::get($payload, 'confidence')) ?: 'Needs AE validation',
            'validation_checklist' => collect(Arr::wrap(Arr::get($payload, 'validation_checklist', [])))
                ->map(fn ($item) => $this->cleanString($item))
                ->filter()
                ->values()
                ->all(),
            'sources' => $sources,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeSection(mixed $value): array
    {
        if (! is_array($value)) {
            $summary = $this->cleanString($value);

            return [
                'status' => $summary ? 'Needs validation' : 'Not found',
                'summary' => $summary ?: 'Needs validation.',
                'evidence' => null,
                'confidence' => 'Low',
            ];
        }

        return [
            'status' => $this->cleanString(Arr::get($value, 'status')) ?: 'Needs validation',
            'summary' => $this->cleanString(Arr::get($value, 'summary')) ?: 'Needs validation.',
            'evidence' => $this->cleanString(Arr::get($value, 'evidence')),
            'confidence' => $this->cleanString(Arr::get($value, 'confidence')) ?: 'Low',
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
            'summary' => $text,
            'confidence' => 'Needs AE validation',
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

    private function cleanUrl(mixed $value): ?string
    {
        $url = $this->cleanString($value);

        return $url && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
