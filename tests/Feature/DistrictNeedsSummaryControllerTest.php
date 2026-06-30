<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DistrictNeedsSummaryControllerTest extends TestCase
{
    public function test_district_needs_summary_uses_public_web_research_prompt(): void
    {
        Storage::fake('local');
        config([
            'services.openai.key' => 'test-key',
            'services.openai.model' => 'gpt-test',
        ]);

        $openAiPayload = null;

        Http::fake(function (HttpRequest $request) use (&$openAiPayload) {
            $openAiPayload = $request->data();

            return Http::response([
                'output_text' => json_encode([
                    'summary' => 'Public sources surfaced math score comparison, LMS, and curriculum clues for the AE.',
                    'answer' => null,
                    'math_test_score' => [
                        'status' => 'Needs manual score check',
                        'summary' => 'Exact grade-level comparison needs a manual report-card check, but public math-score sources were found.',
                        'evidence' => 'Colorado assessment pages reference CMAS math reporting and state-average comparison data for the district.',
                        'confidence' => 'Medium',
                        'source_title' => 'Colorado district performance source',
                        'source_url' => 'https://example.test/colorado-report-card',
                    ],
                    'lms' => [
                        'status' => 'Clue found',
                        'summary' => 'A public school page references Canvas access.',
                        'evidence' => 'Parent access page mentions Canvas.',
                        'confidence' => 'Medium',
                        'source_title' => 'Canvas access page',
                        'source_url' => 'https://example.test/canvas',
                    ],
                    'math_curriculum' => [
                        'status' => 'Clue found',
                        'summary' => 'A course guide lists math pathways.',
                        'evidence' => 'Course catalog includes secondary math courses.',
                        'confidence' => 'Medium',
                        'source_title' => 'Course catalog',
                        'source_url' => 'https://example.test/course-catalog',
                    ],
                    'confidence' => 'Medium',
                    'validation_checklist' => [
                        'Open the state report-card math page for exact grade-level district-vs-state comparison.',
                    ],
                    'investigation_queries' => [
                        'Douglas County School District Colorado CMAS math scores compared to state average',
                        'Douglas County School District Canvas LMS',
                        'Douglas County School District math curriculum course catalog',
                    ],
                    'sources' => [
                        [
                            'title' => 'Colorado district performance source',
                            'url' => 'https://example.test/colorado-report-card',
                            'evidence' => 'CMAS math reporting and state-average comparison source.',
                            'category' => 'math',
                        ],
                    ],
                ]),
            ]);
        });

        $this->postJson('/api/district-needs-summary', [
            'profile' => [
                'stateCode' => 'CO',
                'stateName' => 'Colorado',
            ],
            'district' => [
                'name' => 'Douglas County School District No. Re 1',
                'city' => 'Castle Rock',
                'leaId' => '0803450',
                'leaType' => 'Regular public district',
                'totalStudents' => 63262,
                'secondaryStudents' => 33233,
            ],
            'currentSignals' => [
                'mathNeed' => 'Unknown',
                'lms' => 'Unknown',
                'curriculum' => 'Unknown',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('summary', 'Public sources surfaced math score comparison, LMS, and curriculum clues for the AE.')
            ->assertJsonPath('math_test_score.status', 'Needs manual score check');

        $this->assertIsArray($openAiPayload);
        $this->assertSame('web_search', $openAiPayload['tools'][0]['type']);
        $this->assertSame('high', $openAiPayload['tools'][0]['search_context_size']);
        $this->assertStringContainsString('Search like Google', $openAiPayload['input']);
        $this->assertStringContainsString('Do not stop after one missing exact source', $openAiPayload['input']);
        $this->assertStringContainsString("How do this district's math test scores compare to the state average?", $openAiPayload['input']);
        $this->assertStringContainsString('above state average, near state average, below state average, mixed by grade, or unavailable', $openAiPayload['input']);
        $this->assertStringContainsString('CMAS or state test terms', $openAiPayload['input']);
        $this->assertStringContainsString('Canvas, Schoology, Google Classroom', $openAiPayload['input']);
        $this->assertStringContainsString('including at least one math-score comparison query', $openAiPayload['input']);
        $this->assertStringNotContainsString('Do not answer anything else.', $openAiPayload['input']);
    }
}
