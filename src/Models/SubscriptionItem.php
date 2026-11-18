<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

class SubscriptionItem extends CashierSubscriptionItem
{
    protected $table = 'velvetrope_subscription_items';
}
