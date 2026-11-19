<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Plans;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * Pro: gift options whose plan exists, keyed by handle.
     *
     * @return array<string, GiftOption>
     */
    public function giftOptions(): array
    {
        $options = [];

        foreach ($this->settings->giftOptions ?? [] as $row) {
            if (is_array($row) && ($option = GiftOption::fromRow($row)) !== null && $this->get($option->plan) !== null) {
                $options[$option->handle] ??= $option;
            }
        }

        return $options;
    }

    public function giftOption(string $handle): ?GiftOption
    {
        return $this->giftOptions()[$handle] ?? null;
    }

    /**
     * Pro: plan gates, in the order they were entered. Every matching gate applies.
     *
     * @return list<Gate>
     */
    public function gates(): array
    {
        $gates = [];

        foreach ($this->settings->gates ?? [] as $row) {
            if (is_array($row) && ($gate = Gate::fromRow($row)) !== null) {
                $gates[] = $gate;
            } elseif (Cache::add('velvetrope:bad-gate', true, 3600)) {
                // Nothing can be gated by a row that can't be read; say so loudly.
                Log::warning('Velvet Rope: a plan gate row is incomplete or invalid and is being ignored: '.json_encode($row));
            }
        }

        return $gates;
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
