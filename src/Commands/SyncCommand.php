<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\VelvetRope\Membership\Dunning;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Membership\SubscriptionMirror;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ExceptionInterface as StripeException;
use Stripe\Exception\InvalidRequestException;

/**
 * Re-applies every member's groups from their local subscriptions and grants, and
 * moves dunning along (Pro). Runs hourly so grace periods and grants end even
 * when Stripe sends nothing. `--refresh` first re-reads every live subscription
 * from Stripe (status, price for MRR) — for missed webhooks, or after go-live.
 */
class SyncCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:velvetrope:sync {--refresh : Re-read live subscriptions from the Stripe API first}';

    protected $description = 'Re-syncs members’ user groups from their subscriptions and grants';

    public function handle(GroupSync $groupSync, Dunning $dunning, SubscriptionMirror $mirror): int
    {
        if ($this->option('refresh')) {
            if (! config('cashier.secret')) {
                $this->components->error('Stripe is not configured (no secret key).');

                return self::FAILURE;
            }

            $refreshed = $failed = 0;
            Subscription::query()->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
                ->chunkById(100, function ($subscriptions) use ($mirror, &$refreshed, &$failed): void {
                    foreach ($subscriptions as $subscription) {
                        $member = Member::find($subscription->member_id);
                        if ($member === null) {
                            continue;
                        }
                        try {
                            $mirror->upsert($member, Cashier::stripe()->subscriptions->retrieve($subscription->stripe_id)->toArray());
                            $refreshed++;
                        } catch (InvalidRequestException $e) {
                            // Gone at Stripe: it can't be granting access any more.
                            if ($e->getStripeCode() === 'resource_missing') {
                                $subscription->update(['stripe_status' => 'canceled', 'ends_at' => $subscription->ends_at ?? now()]);
                            }
                            $failed++;
                            $this->components->warn("{$subscription->stripe_id}: {$e->getMessage()}");
                        } catch (StripeException $e) {
                            $failed++;
                            $this->components->warn("{$subscription->stripe_id}: {$e->getMessage()}");
                        }
                    }
                });
            $this->components->info("Refreshed {$refreshed} subscriptions from Stripe; {$failed} failed.");
        }

        $changed = 0;

        Member::query()->chunkById(200, function ($members) use ($groupSync, &$changed): void {
            foreach ($members as $member) {
                $r = $groupSync->sync($member);
                $changed += (int) ($r['before'] !== $r['after']);
            }
        });

        $moved = $dunning->sweep();
        $this->components->info("Synced members; {$changed} changed, {$moved} dunning updates.");

        return self::SUCCESS;
    }
}
