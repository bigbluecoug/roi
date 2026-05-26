<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_pages_require_login(): void
    {
        $this->get('/events')
            ->assertRedirect('/login');
    }
}
