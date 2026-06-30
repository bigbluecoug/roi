<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DistrictNeedsSummaryController extends Controller
{
    private const CACHE_VERSION = 'v4-math-score-comparison';

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

        $mode = $request->input('mode') === 'secondary_schools' ? 'secondary_schools' : 'evidence';
        $cacheKey = $this->cacheKey($request, $mode);
        $cached = $this->cachedPayload($cacheKey);

        if ($cached) {
            return response()->json($this->withCacheMeta($cached, $cacheKey, true));
        }

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
                        'search_context_size' => 'high',
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

            $payload = $this->normalize($this->decodeResponse($response->json()), $response->json(), $mode);
            $this->storeCachedPayload($cacheKey, $payload);

            return response()->json($this->withCacheMeta($payload, $cacheKey, false));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'District needs summary could not be generated.',
            ], 502);
        }
    }

    private function cacheKey(Request $request, string $mode): string
    {
        $question = $this->cleanString($request->input('question')) ?: '';
        $districtId = $this->cleanString($request->input('district.leaId'))
            ?: $this->cleanString($request->input('district.name'))
            ?: 'unknown-district';
        $state = $this->cleanString($request->input('profile.stateCode'))
            ?: $this->cleanString($request->input('profile.stateName'))
            ?: 'unknown-state';

        return hash('sha256', json_encode([
            'version' => self::CACHE_VERSION,
            'mode' => $mode,
            'state' => mb_strtolower($state),
            'district' => mb_strtolower($districtId),
            'question' => mb_strtolower($question),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function cachePath(string $cacheKey): string
    {
        return 'district-needs-cache/'.$cacheKey.'.json';
    }

    private function cachedPayload(string $cacheKey): ?array
    {
        $path = $this->cachePath($cacheKey);
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function storeCachedPayload(string $cacheKey, array $payload): void
    {
        Storage::disk('local')->put($this->cachePath($cacheKey), json_encode([
            ...$payload,
            'cache_stored_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function withCacheMeta(array $payload, string $cacheKey, bool $cached): array
    {
        return [
            ...$payload,
            'cached' => $cached,
            'cache_scope' => 'shared',
            'cache_key' => $cacheKey,
        ];
    }

    private function prompt(array $payload, string $mode = 'evidence'): string
    {
        $question = $this->cleanString(Arr::get($payload, 'question'));

        if ($mode === 'secondary_schools') {
            return implode("\n", [
                'Create a source-backed secondary school roster for the selected district.',
                'Use web search and public sources only. Prefer NCES ELSI / CCD 2024-25 School Directory data first, then NCES public school search, state education directories, or the district official school directory.',
                'Use the district LEA ID from the payload when searching ELSI/CCD records.',
                'If knownSecondarySchoolRoster is provided in the payload, treat it as the current ELSI/CCD-derived roster, return those school names first, and use web search only to validate/source gaps.',
                'List schools associated with the district that serve secondary grades: middle schools, junior highs, high schools, and 6-12 schools. Use grade span and NCES school level when available.',
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
                'Answer the AE follow-up question for Derivita using quick public-web research.',
                'Use targeted search queries the way a sales rep would: district name + state + the requested topic, official district pages, state report-card pages, board PDFs, parent/student access pages, course catalogs, and school pages.',
                'Keep the scope around how district math test scores compare to the state average, district LMS, adopted math curriculum, and practical outreach implications.',
                'For math, answer the comparison question directly: above state average, near state average, below state average, mixed by grade, or exact comparison needs manual state report-card check.',
                'Do not give generic "could not confirm" filler. If the exact score comparison is not source-backed, state that clearly, then provide the closest useful public clues and the search query that should be used next.',
                'Cite source URLs in the sources array.',
                'Answer in 3 short bullets or fewer.',
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
            'Run quick public-web research for a Derivita AE. Search like Google, not like a compliance validator.',
            'Goal: produce a useful district research brief with source-backed clues for outreach. Do not stop after one missing exact source.',
            'Use several targeted searches before answering. Start with the exact district name, state, city, and LEA ID from the payload.',
            'Prioritize official and near-official sources: state report cards/assessment pages, district and school pages, board agenda PDFs, parent/student login pages, course catalogs, curriculum pages, technology help pages, and credible public documents.',
            'Answer these three sections:',
            '1. Math scores vs state average: answer "How do this district\'s math test scores compare to the state average?" Look for grades 6-12 math results, state assessment/report-card pages, CMAS or state test terms, SAT/PSAT/math readiness where relevant. Report whether scores appear above state average, near state average, below state average, mixed by grade, or unavailable. Include district score, state average, grade/span, assessment/year, and source URL when available. If exact district-vs-state values are not directly visible, say "Exact math-score comparison needs manual state report-card check" and still summarize the best sourced math clues you found.',
            '2. LMS / platform clue: identify the district LMS or student/parent learning platform from public pages. Canvas, Schoology, Google Classroom, Blackboard, Infinite Campus, ParentVUE/StudentVUE, Clever, ClassLink, and district portals are useful clues. Distinguish LMS from SIS when possible.',
            '3. Math curriculum / instructional clue: look for adopted math curriculum, course sequence, math resources, curriculum guides, board adoptions, or school math pages. If no districtwide adoption is visible, summarize school-level or course-guide evidence and mark it as a clue.',
            'For every section, provide a concrete summary, a short evidence note, confidence, and the best source URL. Avoid empty "could not confirm" answers unless no relevant source was found after multiple targeted searches.',
            'Return 3-5 investigation_queries that are ready to click in Google, including at least one math-score comparison query, one state report-card query, one LMS query, and one curriculum query.',
            'Cite source URLs in the section and sources array when possible.',
            'Return JSON only in the requested schema.',
            '',
            'Suggested search patterns to adapt to this district:',
            '- "{district name}" "{state name}" math scores compared to state average',
            '- "{district name}" CMAS math report card state average OR state assessment math',
            '- "site:{district domain if visible}" "{district name}" Canvas OR Schoology OR LMS OR Clever',
            '- "{district name}" math curriculum OR curriculum guide OR course catalog OR board adoption',
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
