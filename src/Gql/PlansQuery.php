<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `velvetRopePlans` — the membership plans a headless front end needs to draw a
 * pricing page: handle, name, trial days and checkout link (plus Pro gift
 * options). Read-only, behind the schema's “View membership plans” permission.
 *
 * Deliberately absent: Stripe price IDs and secrets, members, subscriptions, and
 * "is the current user subscribed". A GraphQL token identifies a schema (or an
 * app), not the visitor in front of the page, so membership status over token
 * auth would describe the wrong person; templates and the session-authenticated
 * site answer that instead.
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 */
class PlansQuery extends Query
{
    /** @return array<string, UnnamedFieldDefinitionConfig> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlHelper::canSchema(PlansResolver::COMPONENT)) {
            return [];
        }

        return [
            'velvetRopePlans' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::planType()))),
                'args' => [
                    'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'A site handle, for translated names and that site’s checkout links (default: the primary site the schema can read).'],
                    'handle' => ['name' => 'handle', 'type' => Type::string(), 'description' => 'Only this plan.'],
                ],
                'resolve' => [PlansResolver::class, 'resolve'],
                'description' => 'Membership plans (Velvet Rope).',
            ],
        ];
    }

    private static function planType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('VelvetRopePlan', fn () => new ObjectType([
            'name' => 'VelvetRopePlan',
            'description' => 'A membership plan.',
            'fields' => fn () => [
                'handle' => ['type' => Type::nonNull(Type::string())],
                'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'Translated for the site (category `site`).'],
                'trialDays' => ['type' => Type::nonNull(Type::int())],
                'checkoutUrl' => ['type' => Type::nonNull(Type::string()), 'description' => 'Send the visitor here (in a browser) to subscribe.'],
                'giftOptions' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(self::giftType()))), 'description' => 'Pro: ways to buy this plan as a gift.'],
            ],
        ]));
    }

    private static function giftType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('VelvetRopeGiftOption', fn () => new ObjectType([
            'name' => 'VelvetRopeGiftOption',
            'fields' => fn () => [
                'handle' => ['type' => Type::nonNull(Type::string())],
                'months' => ['type' => Type::nonNull(Type::int())],
                'url' => ['type' => Type::nonNull(Type::string())],
            ],
        ]));
    }
}
