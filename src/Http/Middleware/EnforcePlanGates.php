<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Middleware;

use Closure;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Route\MatchedElement;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use JustinHoltWeb\VelvetRope\Plans\Gate;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\VelvetRope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pro: plan gates on front-end pages. URI gates are checked before the page is
 * built; section gates once Craft has matched the entry, before anything is
 * sent. A gated page that is let through is marked `private, no-store` so a CDN
 * or proxy never hands it to the next visitor. The first matching gate wins.
 *
 * Gates protect pages. The same content reached another way (an element query in
 * an ungated template, GraphQL) is not covered — see the docs.
 */
class EnforcePlanGates
{
    public function __construct(
        private readonly Plans $plans,
        private readonly VelvetRope $velvetRope,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! Plugin::isPro() || ! $request->isSiteRequest() || $request->isActionRequest() || $request->isPreview()) {
            return $next($request);
        }

        $gates = $this->plans->gates();

        if ($gates === []) {
            return $next($request);
        }

        $path = Sites::getRequestPath($request);
        $gated = false;

        foreach ($gates as $gate) {
            if ($gate->matchesUri($path)) {
                if (($denied = $this->deny($gate)) !== null) {
                    return $denied;
                }
                $gated = true;
                break;
            }
        }

        $response = $next($request);

        if (! $gated && ($entry = MatchedElement::get()) instanceof Entry && ($section = $entry->getSection()?->handle) !== null) {
            foreach ($gates as $gate) {
                if ($gate->type === Gate::SECTION && $gate->match === $section) {
                    if (($denied = $this->deny($gate)) !== null) {
                        return $denied;
                    }
                    $gated = true;
                    break;
                }
            }
        }

        if ($gated && $response instanceof Response) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /** The redirect (login, join page, checkout) or 403 for someone the gate keeps out; null to let them in. */
    private function deny(Gate $gate): ?Response
    {
        try {
            $this->velvetRope->requireMembership($gate->plans);

            return null;
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }
}
