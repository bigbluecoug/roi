<?php

namespace Tests\Unit;

use App\Services\PublicLeadEnricher;
use Tests\TestCase;

class PublicLeadEnricherTest extends TestCase
{
    public function test_it_normalizes_public_email_results_and_citations(): void
    {
        $result = (new PublicLeadEnricher)->normalize([
            'status' => 'found',
            'email' => ' JENNY.WALKER@DISTRICT.ORG ',
            'confidence' => 1.4,
            'person_match' => 'Name matches staff page.',
            'organization_match' => 'Organization matches badge.',
            'summary' => 'Email appears on the public staff page.',
            'sources' => [[
                'title' => 'Staff Directory',
                'url' => 'https://example.org/staff/jenny-walker',
                'evidence' => 'Staff page lists the email.',
            ]],
        ], [
            'output' => [[
                'content' => [[
                    'annotations' => [[
                        'type' => 'url_citation',
                        'title' => 'District Contact',
                        'url' => 'https://example.org/contact',
                    ]],
                ]],
            ]],
        ]);

        $this->assertSame('found', $result['status']);
        $this->assertSame('jenny.walker@district.org', $result['email']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertCount(2, $result['sources']);
        $this->assertSame('https://example.org/contact', $result['sources'][1]['url']);
    }

    public function test_it_rejects_invalid_or_guessed_email_values(): void
    {
        $result = (new PublicLeadEnricher)->normalize([
            'status' => 'found',
            'email' => 'a******d@district.org',
            'confidence' => 0.91,
            'sources' => [],
        ]);

        $this->assertNull($result['email']);
        $this->assertSame([], $result['sources']);

        $result = (new PublicLeadEnricher)->normalize([
            'status' => 'found',
            'email' => 'first.last at district dot org',
            'confidence' => 0.91,
            'sources' => [],
        ]);

        $this->assertNull($result['email']);
    }
}
