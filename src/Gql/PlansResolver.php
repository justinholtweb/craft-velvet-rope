<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Gql;

use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Site\Data\Site;
use GraphQL\Error\UserError;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\VelvetRope;

use function CraftCms\Cms\t;

class PlansResolver
{
    public const string COMPONENT = 'velvetrope.plans';

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function resolve(mixed $source, array $args): array
    {
        if (! GqlHelper::canSchema(self::COMPONENT)) {
            throw new UserError('This schema can’t view membership plans.');
        }

        $site = self::site(isset($args['site']) ? (string) $args['site'] : null);
        $plans = app(Plans::class);
        $gifts = Plugin::isPro() ? $plans->giftOptions() : [];
        $language = $site->language;
        $rows = [];

        foreach ($plans->all() as $plan) {
            if (isset($args['handle']) && $args['handle'] !== $plan->handle) {
                continue;
            }

            $rows[] = [
                'handle' => $plan->handle,
                'name' => t($plan->name, category: 'site', locale: $language),
                'trialDays' => $plan->trialDays,
                'checkoutUrl' => VelvetRope::linkTo("checkout/{$plan->handle}", siteId: $site->id),
                'giftOptions' => array_values(array_map(fn ($o) => [
                    'handle' => $o->handle,
                    'months' => $o->months,
                    'url' => VelvetRope::linkTo("gift/{$o->handle}", siteId: $site->id),
                ], array_filter($gifts, fn ($o) => $o->plan === $plan->handle))),
            ];
        }

        return $rows;
    }

    /** The requested site, or the primary one the schema can read; never a site it can't. */
    private static function site(?string $handle): Site
    {
        $allowed = GqlHelper::getAllowedSites();

        foreach ($allowed as $site) {
            if ($handle === null ? $site->primary : $site->handle === $handle) {
                return $site;
            }
        }

        if ($handle === null && $allowed !== []) {
            return $allowed[0];
        }

        throw new UserError($handle === null ? 'This schema can’t read any site.' : "This schema can’t read the “{$handle}” site.");
    }
}
