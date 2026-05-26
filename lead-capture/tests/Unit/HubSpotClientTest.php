<?php

namespace Tests\Unit;

use App\Services\HubSpotClient;
use PHPUnit\Framework\TestCase;

class HubSpotClientTest extends TestCase
{
    public function test_missing_only_properties_preserves_existing_crm_values(): void
    {
        $missing = (new HubSpotClient)->missingOnlyProperties(
            [
                'email' => 'alex@example.org',
                'firstname' => 'Alex',
                'lastname' => '',
                'jobtitle' => null,
            ],
            [
                'email' => 'alex@example.org',
                'firstname' => 'Alexander',
                'lastname' => 'Rivera',
                'jobtitle' => 'Math Director',
                'phone' => '',
            ],
        );

        $this->assertSame([
            'lastname' => 'Rivera',
            'jobtitle' => 'Math Director',
        ], $missing);
    }
}
