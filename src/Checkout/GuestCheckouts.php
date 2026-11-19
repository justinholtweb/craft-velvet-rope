<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Checkout;

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Membership\SubscriptionMirror;
use JustinHoltWeb\VelvetRope\Models\GuestCheckout;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use Laravel\Cashier\Cashier;
use RuntimeException;
use Throwable;

/**
 * Turns a guest's completed Checkout into a member. The design rule: paying never
 * logs anybody in and never takes over an account.
 *
 * - No Craft user has the email Stripe collected → a new **pending** user is made
 *   and sent Craft's activation email. Nobody can sign in as them until the
 *   owner of that inbox sets a password, so typing someone else's email at
 *   Checkout gets the payer nothing.
 * - A user has that email → the subscription is attached to them (it only adds
 *   access; it signs nobody in). If they're already a Stripe customer under a
 *   different customer ID, nothing is attached: the checkout is held for review.
 *
 * Idempotent per Checkout session (Stripe retries webhooks).
 */
class GuestCheckouts
{
    public function __construct(
        private readonly Plans $plans,
        private readonly SubscriptionMirror $mirror,
        private readonly GroupSync $groupSync,
    ) {}

    /**
     * @param  array<string, mixed>  $session  A Checkout Session object
     */
    public function complete(array $session): ?GuestCheckout
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        if (($metadata['velvetrope'] ?? null) !== 'guest' || ($session['mode'] ?? null) !== 'subscription') {
            return null;
        }

        // Delayed payment methods complete later (`checkout.session.async_payment_succeeded`).
        if (! in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            return null;
        }

        $sessionId = (string) ($session['id'] ?? '');
        $customerId = $session['customer'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;
        $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? null;
        $plan = $this->plans->get((string) ($metadata['plan'] ?? ''));

        if ($sessionId === '' || ! is_string($customerId) || ! is_string($subscriptionId) || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $plan === null) {
            Log::warning("Velvet Rope: guest checkout {$sessionId} is missing its customer, subscription, email or a known plan; ignored.");

            return null;
        }

        $record = GuestCheckout::where('stripeSessionId', $sessionId)->first()
            ?? DB::transaction(function () use ($sessionId, $customerId, $subscriptionId, $email, $plan, $metadata): GuestCheckout {
                $base = [
                    'stripeSessionId' => $sessionId,
                    'email' => $email,
                    'plan' => $plan->handle,
                    'stripeCustomerId' => $customerId,
                    'stripeSubscriptionId' => $subscriptionId,
                ];
                $user = User::find()->email($email)->status(null)->one();
                $owner = Member::where('stripe_id', $customerId)->first();

                if ($user instanceof User) {
                    $member = Member::forUser((int) $user->id);

                    if (($member->stripe_id !== null && $member->stripe_id !== $customerId) || ($owner !== null && $owner->userId !== (int) $user->id)) {
                        return GuestCheckout::create([...$base, 'userId' => $user->id, 'outcome' => GuestCheckout::REVIEW,
                            'reason' => 'The account with this email already has a different Stripe customer.']);
                    }

                    $member->stripe_id = $customerId;
                    $member->save();

                    return GuestCheckout::create([...$base, 'userId' => $user->id, 'outcome' => GuestCheckout::ATTACHED]);
                }

                if ($owner !== null) {
                    return GuestCheckout::create([...$base, 'outcome' => GuestCheckout::REVIEW,
                        'reason' => 'This Stripe customer already belongs to another account.']);
                }

                $user = new User;
                $user->email = $email;
                $user->username = $email;
                $user->pending = true;
                $user->affiliatedSiteId = Sites::getSiteByHandle((string) ($metadata['site'] ?? ''))?->id;

                if (! Elements::saveElement($user)) {
                    throw new RuntimeException('Velvet Rope could not create a user for a guest checkout: '.json_encode($user->errors()->all()));
                }

                $member = Member::forUser((int) $user->id);
                $member->stripe_id = $customerId;
                $member->save();

                return GuestCheckout::create([...$base, 'userId' => $user->id, 'outcome' => GuestCheckout::CREATED]);
            });

        if ($record->outcome === GuestCheckout::REVIEW) {
            if ($record->wasRecentlyCreated) {
                Log::warning("Velvet Rope: guest checkout {$sessionId} held for review: {$record->reason}");
            }

            return $record;
        }

        if ($record->wasRecentlyCreated && $record->outcome === GuestCheckout::CREATED && ($user = User::find()->id($record->userId)->status(null)->one()) instanceof User) {
            try {
                Users::sendActivationEmail($user);
            } catch (Throwable $e) {
                // The account and membership still stand; an admin can resend activation from the CP.
                Log::error("Velvet Rope: activation email for user {$user->id} failed: {$e->getMessage()}");
            }
        }

        // The subscription itself (Cashier ignored its webhook if it came before the customer was linked).
        if (! Subscription::where('stripe_id', $subscriptionId)->exists()) {
            $member = Member::where('userId', $record->userId)->firstOrFail();
            $this->mirror->upsert($member, Cashier::stripe()->subscriptions->retrieve($subscriptionId)->toArray());
        }

        $this->groupSync->syncUser((int) $record->userId);

        return $record;
    }
}
