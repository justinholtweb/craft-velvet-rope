<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A completed checkout by someone who wasn't logged in, and what Velvet Rope did
 * with it: created a pending account, attached it to an existing one, or held it
 * for review.
 *
 * @property int $id
 * @property string $stripeSessionId
 * @property string $email
 * @property string $plan
 * @property string|null $stripeCustomerId
 * @property string|null $stripeSubscriptionId
 * @property int|null $userId
 * @property string $outcome created|attached|review
 * @property string|null $reason
 */
class GuestCheckout extends Model
{
    public const string CREATED = 'created';

    public const string ATTACHED = 'attached';

    public const string REVIEW = 'review';

    protected $table = 'velvetrope_guest_checkouts';

    protected $guarded = ['id'];
}
