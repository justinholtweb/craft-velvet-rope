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
 * Pro: plan gates on front-end pages. Every gate that matches applies, and the
 * visitor must pass all of them. URI gates (case-insensitive, like Craft's element
 * routing) are checked against the requested path before the page is built, and
 * against the matched element's canonical URI afterwards; section gates once Craft
 * has matched the entry — always before anything is sent. A gated page that is let
 * through is marked `private, no-store` so a CDN or proxy never hands it to the
 * next visitor.
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

        // Every matching gate applies, and the visitor must pass all of them.
        $applied = [];

        // URI gates on the requested path, before the page is built.
        $path = Sites::getRequestPath($request);
        foreach ($gates as $i => $gate) {
            if ($gate->matchesUri($path)) {
                if (($denied = $this->deny($gate)) !== null) {
                    return $denied;
                }
                $applied[$i] = true;
            }
        }

        $response = $next($request);

        // Then, once Craft has matched an element: URI gates against its canonical URI (the
        // request may have reached it by another spelling) and section gates for its section.
        $element = MatchedElement::get();
        if ($element !== false) {
            $uri = is_string($element->uri ?? null) ? $element->uri : null;
            $section = $element instanceof Entry ? $element->getSection()?->handle : null;

            foreach ($gates as $i => $gate) {
                if (isset($applied[$i])) {
                    continue;
                }

                if (($uri !== null && $gate->matchesUri($uri)) || $gate->matchesSection($section)) {
                    if (($denied = $this->deny($gate)) !== null) {
                        return $denied;
                    }
                    $applied[$i] = true;
                }
            }
        }

        if ($applied !== [] && $response instanceof Response) {
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
