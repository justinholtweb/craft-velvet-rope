<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Gifts;

use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Jobs\SendMemberEmail;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Models\Gift;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Settings;
use Throwable;

use function CraftCms\Cms\site_url;

/**
 * Pro: gift memberships. A paid gift checkout issues a one-time code (only its
 * hash is stored) that's emailed to the buyer; redeeming it gives the redeemer
 * a grant for the plan, starting when any grant they already have ends.
 */
class Gifts
{
    public function __construct(
        private readonly Plans $plans,
        private readonly GroupSync $groupSync,
        private readonly Settings $settings,
    ) {}

    /**
     * From `checkout.session.completed` / `checkout.session.async_payment_succeeded`.
     *
     * @param  array<string, mixed>  $session
     */
    public function purchased(array $session): ?Gift
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        if (($metadata['velvetrope'] ?? null) !== 'gift' || ($session['mode'] ?? null) !== 'payment' || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $sessionId = (string) ($session['id'] ?? '');
        $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? null;
        $plan = (string) ($metadata['plan'] ?? '');
        $months = (int) ($metadata['months'] ?? 0);

        if ($sessionId === '' || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $months < 1 || $months > 120 || $this->plans->get($plan) === null) {
            Log::warning("Velvet Rope: ignored gift checkout {$sessionId} (missing email, unknown plan “{$plan}” or bad months).");

            return null;
        }

        // Stripe retries webhooks: one gift per checkout session, ever.
        if (Gift::where('stripeSessionId', $sessionId)->exists()) {
            return null;
        }

        $site = Sites::getSiteByHandle((string) ($metadata['site'] ?? '')) ?? Sites::getPrimarySite();
        $code = self::newCode();

        $gift = Gift::create([
            'codeHash' => Gift::hash($code),
            'option' => (string) ($metadata['option'] ?? ''),
            'plan' => $plan,
            'months' => $months,
            'buyerEmail' => $email,
            'stripeSessionId' => $sessionId,
            'siteHandle' => $site->handle,
        ]);

        // Sent now, not queued, so the code never sits in the jobs table. If it can't be
        // sent, forget the gift: Stripe retries the webhook and a new code goes out.
        try {
            new SendMemberEmail('velvetrope_gift_purchased', null, $email, [
                'code' => $code,
                'planName' => $this->plans->get($plan)->name,
                'months' => (string) $months,
                'redeemUrl' => site_url(ltrim((string) $this->settings->redeemPath, '/'), siteId: $site->id),
            ], $site->handle)->handle();
        } catch (Throwable $e) {
            $gift->delete();
            throw $e;
        }

        return $gift;
    }

    /**
     * @return array{planName: string, endsAt: string}|null Null when the code is unknown or used.
     */
    public function redeem(string $code, int $userId): ?array
    {
        $code = Gift::normalise($code);

        if ($code === '' || strlen($code) > 64) {
            return null;
        }

        $gift = Gift::where('codeHash', Gift::hash($code))->first();
        $plan = $gift !== null ? $this->plans->get($gift->plan) : null;

        if ($gift === null || $gift->redeemedAt !== null || $plan === null) {
            return null;
        }

        $grant = DB::transaction(function () use ($gift, $userId): ?Grant {
            // Claim it atomically: two tabs, one code, one grant.
            if (Gift::whereKey($gift->id)->whereNull('redeemedAt')->update(['redeemedAt' => now(), 'redeemedByUserId' => $userId]) !== 1) {
                return null;
            }

            $after = Grant::where('userId', $userId)->where('plan', $gift->plan)->where('endsAt', '>', now())->max('endsAt');
            $start = $after !== null ? Carbon::parse($after) : now();

            return Grant::create([
                'userId' => $userId,
                'plan' => $gift->plan,
                'source' => Grant::SOURCE_GIFT,
                'giftId' => $gift->id,
                'startsAt' => $start,
                'endsAt' => $start->copy()->addMonths($gift->months),
            ]);
        });

        if ($grant === null) {
            return null;
        }

        $this->groupSync->syncUser($userId);

        return ['planName' => $plan->name, 'endsAt' => $grant->endsAt->toDateString()];
    }

    /** e.g. `VR7K-3QXM-9TNA-PW2F`: 80 random bits, no look-alike characters. */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 16; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }

        return implode('-', str_split($code, 4));
    }
}
