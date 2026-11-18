<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Plans;

use JustinHoltWeb\VelvetRope\Settings;

/**
 * The configured plans, keyed by handle.
 */
class Plans
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, Plan> */
    public function all(): array
    {
        $plans = [];

        foreach ($this->settings->plans ?? [] as $row) {
            if (is_array($row) && ($plan = Plan::fromRow($row)) !== null) {
                $plans[$plan->handle] ??= $plan;
            }
        }

        return $plans;
    }

    public function get(string $handle): ?Plan
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The plan a subscription belongs to: by its type (the plan handle Velvet Rope
     * gives it at checkout), else by price.
     */
    public function forSubscription(string $type, ?string $price): ?Plan
    {
        $plans = $this->all();

        if (isset($plans[$type])) {
            return $plans[$type];
        }

        foreach ($plans as $plan) {
            if ($price !== null && $plan->priceId === $price) {
                return $plan;
            }
        }

        return null;
    }
}
