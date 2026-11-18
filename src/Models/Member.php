<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use CraftCms\Cms\User\Elements\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Cashier\Billable;

/**
 * Cashier's billable, one per Craft user. Holding the Stripe columns here keeps
 * Craft's own users table untouched.
 *
 * @property int $id
 * @property int $userId
 * @property string|null $stripe_id
 */
class Member extends Model
{
    use Billable;

    protected $table = 'velvetrope_members';

    protected $guarded = ['id'];

    protected $casts = ['trial_ends_at' => 'datetime'];

    /** Cashier names the foreign key on its tables after this (`member_id`). */
    public function getForeignKey(): string
    {
        return 'member_id';
    }

    /** Stripe customer email/name come from the Craft user. */
    public function stripeEmail(): ?string
    {
        return User::find()->id($this->userId)->status(null)->one()?->email;
    }

    public function stripeName(): ?string
    {
        $user = User::find()->id($this->userId)->status(null)->one();

        return $user?->getName();
    }

    public static function forUser(int $userId): self
    {
        return self::firstOrCreate(['userId' => $userId]);
    }
}
