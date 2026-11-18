<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JustinHoltWeb\VelvetRope\VelvetRope;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('member')` or `->middleware('member:gold,silver')` on app routes —
 * same rules as `{% requireMembership %}` / `@requireMembership`.
 */
class RequireMembership
{
    public function __construct(private readonly VelvetRope $velvetRope) {}

    public function handle(Request $request, Closure $next, string ...$plans): Response
    {
        $this->velvetRope->requireMembership($plans);

        return $next($request);
    }
}
