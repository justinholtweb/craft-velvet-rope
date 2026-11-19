<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use JustinHoltWeb\VelvetRope\Gql\PlansResolver;

use function CraftCms\Cms\t;

/** GraphQL schema permission: “View membership plans” (public plan details only). */
class RegisterGqlComponents
{
    public function handle(GqlSchemaComponentsResolving $event): void
    {
        $event->queries['Velvet Rope'] = [
            PlansResolver::COMPONENT.':read' => [
                'label' => t('View membership plans', category: 'velvetrope'),
                'info' => t('Adds the `velvetRopePlans` query: each plan’s handle, name, trial days and checkout link (and Pro gift options). No price IDs, members or anything personal.', category: 'velvetrope'),
                'authenticated' => true,
            ],
        ];
    }
}
