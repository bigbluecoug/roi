<?php

namespace Tests\Unit;

use App\Services\OpenAiLeadExtractor;
use PHPUnit\Framework\TestCase;

class OpenAiLeadExtractorTest extends TestCase
{
    public function test_it_normalizes_extracted_fields(): void
    {
        $result = (new OpenAiLeadExtractor)->normalize([
            'full_name' => '  Alex   Rivera  ',
            'email' => 'ALEX@EXAMPLE.ORG',
            'organization' => '  Cherry Creek School District ',
            'confidence' => ['overall' => 0.87],
            'evidence' => ['Alex Rivera'],
            'insights' => [
                'role_category' => 'Curriculum leader',
                'district_clues' => ['Cherry Creek text', 'district email domain'],
                'missing_fields' => ['phone'],
            ],
        ]);

        $this->assertSame('Alex Rivera', $result['full_name']);
        $this->assertSame('Alex', $result['first_name']);
        $this->assertSame('Rivera', $result['last_name']);
        $this->assertSame('alex@example.org', $result['email']);
        $this->assertSame('Cherry Creek School District', $result['organization']);
        $this->assertSame(0.87, $result['ai_confidence']);
        $this->assertSame('Curriculum leader', $result['insights']['role_category']);
        $this->assertSame(['Cherry Creek text', 'district email domain'], $result['insights']['district_clues']);
        $this->assertSame('Curriculum leader', $result['extracted_payload']['insights']['role_category']);
    }

    public function test_it_rejects_non_email_values(): void
    {
        $result = (new OpenAiLeadExtractor)->normalize([
            'email' => 'not an email',
        ]);

        $this->assertNull($result['email']);
    }
}
