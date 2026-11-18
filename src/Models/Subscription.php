<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * @property string $type
 * @property string $stripe_status
 * @property string|null $stripe_price
 * @property Carbon|null $past_due_since
 * @property Carbon|null $ends_at
 * @property Carbon|null $trial_ends_at
 */
class Subscription extends CashierSubscription
{
    protected $table = 'velvetrope_subscriptions';

    /** @var array<string, string> */
    protected $casts = [
        'ends_at' => 'datetime',
        'quantity' => 'integer',
        'trial_ends_at' => 'datetime',
        'past_due_since' => 'datetime',
    ];

    public function getForeignKey(): string
    {
        return 'subscription_id';
    }
}
