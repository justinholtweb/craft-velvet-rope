<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Access to a plan that isn't a Stripe subscription: a redeemed gift or a
 * complimentary grant made in the control panel (Pro).
 *
 * @property int $id
 * @property int $userId
 * @property string $plan
 * @property string $source gift|comp
 * @property int|null $giftId
 * @property Carbon $startsAt
 * @property Carbon $endsAt
 * @property string|null $note
 * @property int|null $createdById
 */
class Grant extends Model
{
    public const string SOURCE_GIFT = 'gift';

    public const string SOURCE_COMP = 'comp';

    protected $table = 'velvetrope_grants';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = ['startsAt' => 'datetime', 'endsAt' => 'datetime', 'userId' => 'integer'];

    /**
     * @param  Builder<Grant>  $query
     * @return Builder<Grant>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('startsAt', '<=', now())->where('endsAt', '>', now());
    }

    public function isCurrent(): bool
    {
        return $this->startsAt->lessThanOrEqualTo(now()) && $this->endsAt->greaterThan(now());
    }
}
