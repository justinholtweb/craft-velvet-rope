<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Whether a subscription grants access right now. Pure, so it's unit-tested
 * without Stripe.
 *
 * - active, trialing → yes
 * - cancelled but paid to period end (ends_at in the future) → yes
 * - past_due → yes for `graceDays` after Stripe first reported it, then no
 * - incomplete, incomplete_expired, unpaid, canceled (ended) → no
 */
final class Access
{
    public static function grants(
        string $status,
        ?DateTimeInterface $endsAt,
        ?DateTimeInterface $trialEndsAt,
        ?DateTimeInterface $pastDueSince,
        int $graceDays,
        ?DateTimeInterface $now = null,
    ): bool {
        $now = Carbon::instance($now ?? now());

        if ($endsAt !== null && Carbon::instance($endsAt)->lessThanOrEqualTo($now)) {
            return false;
        }

        if ($trialEndsAt !== null && Carbon::instance($trialEndsAt)->greaterThan($now) && ! in_array($status, ['incomplete_expired', 'unpaid'], true)) {
            return true;
        }

        return match ($status) {
            'active', 'trialing' => true,
            'past_due' => $pastDueSince !== null
                && $graceDays > 0
                && Carbon::instance($pastDueSince)->addDays($graceDays)->greaterThan($now),
            default => false,
        };
    }

    /**
     * The new set of group ids: groups Velvet Rope doesn't manage are kept as they
     * are; managed ones are exactly the ones granted.
     *
     * @param  list<int>  $current
     * @param  list<int>  $managed
     * @param  list<int>  $granted
     * @return list<int>
     */
    public static function groupIds(array $current, array $managed, array $granted): array
    {
        $ids = array_merge(array_diff($current, $managed), array_intersect($granted, $managed));
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }
}
