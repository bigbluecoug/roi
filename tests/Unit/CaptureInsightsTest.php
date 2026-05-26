<?php

namespace Tests\Unit;

use App\Models\Capture;
use PHPUnit\Framework\TestCase;

class CaptureInsightsTest extends TestCase
{
    public function test_it_formats_ai_insights_for_notes(): void
    {
        $capture = new Capture([
            'extracted_payload' => [
                'insights' => [
                    'role_category' => 'Curriculum leader',
                    'lead_priority' => 'High',
                    'district_clues' => ['District name on badge', 'K-12 email domain'],
                ],
            ],
        ]);

        $this->assertSame([
            'Role category: Curriculum leader',
            'Lead priority: High',
            'District clues: District name on badge; K-12 email domain',
        ], $capture->aiInsightSummary());
    }
}
