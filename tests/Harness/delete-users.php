<?php

// Velvet Rope harness: soft-deletes velvetrope-member1 and hard-deletes velvetrope-member2, each with one live
// and one already-cancelled subscription, then runs the queued cancel jobs against the local Stripe mock.
// check.sh re-runs fixtures.php afterwards (which restores member1 and recreates member2).

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;

$mk = function (string $username, string $sub) {
    $u = User::find()->username($username)->status(null)->trashed(null)->one();
    if ($u && $u->trashed) {
        Elements::restoreElement($u);
    }
    $m = Member::forUser($u->id);
    $m->stripe_id = "cus_{$username}";
    $m->save();
    $m->subscriptions()->updateOrCreate(['stripe_id' => $sub], ['type' => 'gold', 'stripe_status' => 'active', 'stripe_price' => 'price_harness_gold', 'ends_at' => null]);
    // an already-cancelled one must NOT be sent to Stripe again
    $m->subscriptions()->updateOrCreate(['stripe_id' => $sub.'_old'], ['type' => 'gold', 'stripe_status' => 'canceled', 'stripe_price' => 'price_harness_gold', 'ends_at' => now()->subMonth()]);

    return $u;
};
DB::table('jobs')->where('payload', 'like', '%CancelStripeSubscriptions%')->delete();
Subscription::query()->delete();
$soft = $mk('velvetrope-member1', 'sub_soft_1');
$hard = $mk('velvetrope-member2', 'sub_hard_1');
Elements::deleteElement($soft);
Elements::deleteElement($hard, true);
$jobs = DB::table('jobs')->where('payload', 'like', '%CancelStripeSubscriptions%')->get();
echo 'jobs queued: ', $jobs->count(), "\n";
foreach ($jobs as $row) {
    $cmd = unserialize(json_decode($row->payload, true)['data']['command']);
    echo 'job user ', $cmd->userId, ' → ', implode(',', $cmd->subscriptionIds), "\n";
    $cmd->handle();
    DB::table('jobs')->delete($row->id);
}
echo 'soft-deleted user row status: ', Subscription::where('stripe_id', 'sub_soft_1')->value('stripe_status'), "\n";
echo 'hard-deleted user rows left: ', Subscription::where('stripe_id', 'like', 'sub_hard_1%')->count(), "\n";
