<?php

declare(strict_types=1);

namespace SciFY\LaravelHealthEndpoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /health with a shared secret in the X-Health-Token header.
 */
final class VerifyHealthToken {
    public function handle(Request $request, Closure $next): Response {
        $expected = config()->string('health-endpoint.token');
        $provided = $request->headers->get('X-Health-Token') ?? '';

        // Fail closed: an unset token disables the endpoint. 404 rather than 401 keeps the route invisible to scanners.
        // text/plain: tools that inject into HTML responses (Laravel Debugbar locally) leave the body empty.
        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }
}
