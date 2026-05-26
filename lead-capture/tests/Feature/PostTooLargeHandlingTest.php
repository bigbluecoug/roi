<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Tests\TestCase;

class PostTooLargeHandlingTest extends TestCase
{
    public function test_post_too_large_exception_renders_friendly_redirect(): void
    {
        $request = Request::create('/captures', 'POST');
        $request->headers->set('referer', '/capture');
        $this->app->instance('request', $request);

        $response = $this->app->make(ExceptionHandler::class)
            ->render($request, new PostTooLargeException);

        $this->assertSame(302, $response->getStatusCode());
    }
}
