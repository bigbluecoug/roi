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
            'mode' => ['nullable', 'string', 'in:evidence,secondary_schools'],
            'question' => ['nullable', 'string', 'max:1000'],
            'previousSummary' => ['nullable', 'array'],
        ]);

        if (! config('services.openai.key')) {
            return response()->json([
                'message' => 'OpenAI API key is not configured.',
            ], 503);
        }

        try {
            $mode = $request->input('mode') === 'secondary_schools' ? 'secondary_schools' : 'evidence';

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
                    'input' => $this->prompt($request->all(), $mode),
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'district_needs_summary',
                            'strict' => false,
                            'schema' => $this->schema($mode),
                        ],
                    ],
                ]);

            if ($response->failed()) {
                throw new RuntimeException('OpenAI district needs summary failed: '.$response->body());
            }

            return response()->json($this->normalize($this->decodeResponse($response->json()), $response->json(), $mode));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'District needs summary could not be generated.',
            ], 502);
        }
    }

    private function prompt(array $payload, string $mode = 'evidence'): string
    {
        $question = $this->cleanString(Arr::get($payload, 'question'));

        if ($mode === 'secondary_schools') {
            return implode("\n", [
                'Create a source-backed secondary school roster for the selected district.',
                'Use web search and public sources only. Prefer NCES CCD/EDGE, state education directories, or the district official school directory.',
                'List schools associated with the district that serve secondary grades: middle schools, junior highs, high schools, and 6-12 schools.',
                'Exclude elementary-only, pre-K-only, adult education, virtual-only, closed, and unrelated schools unless the source clearly identifies them as serving grades 6-12 for this LEA.',
                'For each school, include the school name, grade span if source-backed, city if available, and a source URL.',
                'If the source-backed list cannot be completed, include the verified schools you found and say what still needs validation.',
                'Do not invent school names. Cite source URLs in both each school row and the sources array when possible.',
                'Return JSON only in the requested schema.',
                '',
                'Planner payload:',
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);
        }

        if ($question) {
            return implode("\n", [
                'Answer the AE follow-up question for Derivita using web search and public sources.',
                'Stay focused on these evidence areas only: secondary math state-test performance, the district LMS, and the district math curriculum/adoption evidence.',
                'Use official state report cards or state assessment pages for math scores when possible. Use district technology/help/procurement pages for LMS evidence. Use curriculum pages, board packets, course guides, or adoption documents for math curriculum evidence.',
                'Do not guess. If a claim is not source-backed, say it needs validation.',
                'Cite source URLs in the sources array. Keep the answer concise and useful for an Account Executive.',
                'Brevity rules: answer max 3 short bullets or 60 words total. Each summary/evidence field max 18 words. Each validation item max 10 words.',
                'Return JSON only in the requested schema.',
                '',
                'AE question:',
                $question,
                '',
                'Planner payload:',
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);
        }

        return implode("\n", [
            'Create a focused source-backed district evidence review for Derivita.',
            'Use web search and public sources only.',
            'Only cover these three areas:',
            '1. Secondary math state-test performance from official state report cards, state assessment pages, or district accountability pages. Prefer grades 6-12, middle school math, Algebra, high school math, or district-level secondary math evidence. If only broader math evidence is available, label that limitation clearly.',
            '2. Listed LMS evidence, especially Canvas, Schoology, Google Classroom, or another platform. Prefer district technology/help pages, login portals, board packets, procurement pages, or public documentation.',
            '3. Math curriculum evidence, especially Illustrative Math, Open Up Resources/OUR, Big Ideas, or another adopted math curriculum. Prefer curriculum pages, board adoption documents, course guides, or public curriculum maps.',
            'Do not include account sizing, HubSpot, outreach angle, tier recommendation, standards timing, or broad sales advice unless it directly explains one of the three evidence areas.',
            'Do not guess. If math scores, LMS, or curriculum are not source-backed, mark that section as Needs validation and explain where the AE should investigate next.',
            'Cite source URLs in both the relevant section and the sources array whenever possible.',
            'Brevity rules: overall summary max 35 words. Each section summary max 16 words. Each evidence field max 18 words. Validation checklist max 3 items, max 10 words each. Source evidence max 12 words.',
            'Return JSON only in the requested schema.',
            '',
            'Planner payload:',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function schema(string $mode = 'evidence'): array
    {
        if ($mode === 'secondary_schools') {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'summary',
                    'secondary_schools',
                    'confidence',
                    'validation_checklist',
                    'investigation_queries',
                    'sources',
                ],
                'properties' => [
                    'summary' => ['type' => ['string', 'null']],
                    'secondary_schools' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['name', 'grades', 'city', 'source_title', 'source_url', 'confidence'],
                            'properties' => [
                                'name' => ['type' => ['string', 'null']],
                                'grades' => ['type' => ['string', 'null']],
                                'city' => ['type' => ['string', 'null']],
                                'source_title' => ['type' => ['string', 'null']],
                                'source_url' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                    'confidence' => ['type' => ['string', 'null']],
                    'validation_checklist' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'investigation_queries' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'sources' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['title', 'url', 'evidence', 'category'],
                            'properties' => [
                                'title' => ['type' => ['string', 'null']],
                                'url' => ['type' => ['string', 'null']],
                                'evidence' => ['type' => ['string', 'null']],
                                'category' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $section = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'summary', 'evidence', 'confidence'],
            'properties' => [
                'status' => ['type' => 'string'],
                'summary' => ['type' => ['string', 'null']],
                'evidence' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['string', 'null']],
                'source_title' => ['type' => ['string', 'null']],
                'source_url' => ['type' => ['string', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary',
                'answer',
                'math_test_score',
                'lms',
                'math_curriculum',
                'confidence',
                'validation_checklist',
                'investigation_queries',
                'sources',
            ],
            'properties' => [
                'summary' => ['type' => ['string', 'null']],
                'answer' => ['type' => ['string', 'null']],
                'math_test_score' => $section,
                'lms' => $section,
                'math_curriculum' => $section,
                'confidence' => ['type' => ['string', 'null']],
                'validation_checklist' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'investigation_queries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'url', 'evidence', 'category'],
                        'properties' => [
                            'title' => ['type' => ['string', 'null']],
                            'url' => ['type' => ['string', 'null']],
                            'evidence' => ['type' => ['string', 'null']],
                            'category' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function normalize(array $payload, array $rawResponse = [], string $mode = 'evidence'): array
    {
        $sources = collect(Arr::wrap(Arr::get($payload, 'sources', [])))
            ->map(fn ($source) => [
                'title' => $this->cleanString(Arr::get($source, 'title')) ?: 'Public source',
                'url' => $this->cleanUrl(Arr::get($source, 'url')),
                'evidence' => $this->cleanString(Arr::get($source, 'evidence')),
                'category' => $this->cleanString(Arr::get($source, 'category')),
            ])
            ->filter(fn ($source) => filled($source['url']))
            ->values()
            ->all();

        foreach ($this->citations($rawResponse) as $citation) {
            if (! collect($sources)->contains('url', $citation['url'])) {
                $sources[] = $citation;
            }
        }

        if ($mode === 'secondary_schools') {
            return [
                'summary' => $this->cleanString(Arr::get($payload, 'summary')) ?: 'Secondary school roster needs validation.',
                'secondary_schools' => collect(Arr::wrap(Arr::get($payload, 'secondary_schools', [])))
                    ->map(fn ($school) => [
                        'name' => $this->cleanString(Arr::get($school, 'name')),
                        'grades' => $this->cleanString(Arr::get($school, 'grades')),
                        'city' => $this->cleanString(Arr::get($school, 'city')),
                        'source_title' => $this->cleanString(Arr::get($school, 'source_title')),
                        'source_url' => $this->cleanUrl(Arr::get($school, 'source_url')),
                        'confidence' => $this->cleanString(Arr::get($school, 'confidence')) ?: 'Needs validation',
                    ])
                    ->filter(fn ($school) => filled($school['name']))
                    ->values()
                    ->all(),
                'confidence' => $this->cleanString(Arr::get($payload, 'confidence')) ?: 'Needs AE validation',
                'validation_checklist' => collect(Arr::wrap(Arr::get($payload, 'validation_checklist', [])))
                    ->map(fn ($item) => $this->cleanString($item))
                    ->filter()
                    ->values()
                    ->all(),
                'investigation_queries' => collect(Arr::wrap(Arr::get($payload, 'investigation_queries', [])))
                    ->map(fn ($item) => $this->cleanString($item))
                    ->filter()
                    ->values()
                    ->all(),
                'sources' => $sources,
                'checked_at' => now()->toIso8601String(),
            ];
        }

        return [
            'summary' => $this->cleanString(Arr::get($payload, 'summary')) ?: 'Needs validation before outreach.',
            'answer' => $this->cleanString(Arr::get($payload, 'answer')),
            'math_test_score' => $this->normalizeSection(Arr::get($payload, 'math_test_score')),
            'lms' => $this->normalizeSection(Arr::get($payload, 'lms')),
            'math_curriculum' => $this->normalizeSection(Arr::get($payload, 'math_curriculum')),
            'confidence' => $this->cleanString(Arr::get($payload, 'confidence')) ?: 'Needs AE validation',
            'validation_checklist' => collect(Arr::wrap(Arr::get($payload, 'validation_checklist', [])))
                ->map(fn ($item) => $this->cleanString($item))
                ->filter()
                ->values()
                ->all(),
            'investigation_queries' => collect(Arr::wrap(Arr::get($payload, 'investigation_queries', [])))
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
                'source_title' => null,
                'source_url' => null,
            ];
        }

        return [
            'status' => $this->cleanString(Arr::get($value, 'status')) ?: 'Needs validation',
            'summary' => $this->cleanString(Arr::get($value, 'summary')) ?: 'Needs validation.',
            'evidence' => $this->cleanString(Arr::get($value, 'evidence')),
            'confidence' => $this->cleanString(Arr::get($value, 'confidence')) ?: 'Low',
            'source_title' => $this->cleanString(Arr::get($value, 'source_title')),
            'source_url' => $this->cleanUrl(Arr::get($value, 'source_url')),
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
                        'category' => null,
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
