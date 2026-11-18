use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\Support\Facades\Elements;
use JustinHoltWeb\VelvetRope\Models\{Member, Subscription};
use Illuminate\Support\Facades\DB;
$mk = function (string $username, string $sub) {
    $u = User::find()->username($username)->status(null)->trashed(null)->one();
    if ($u && $u->trashed) Elements::restoreElement($u);
    if (! $u) { $u = new User(); $u->username = $username; $u->email = "$username@example.test"; $u->active = true; Elements::saveElement($u); }
    $m = Member::forUser($u->id); $m->stripe_id = "cus_$username"; $m->save();
    $m->subscriptions()->updateOrCreate(['stripe_id' => $sub], ['type' => 'gold', 'stripe_status' => 'active', 'stripe_price' => 'price_harness_gold', 'ends_at' => null]);
    // an already-cancelled one must NOT be sent to Stripe again
    $m->subscriptions()->updateOrCreate(['stripe_id' => $sub.'_old'], ['type' => 'gold', 'stripe_status' => 'canceled', 'stripe_price' => 'price_harness_gold', 'ends_at' => now()->subMonth()]);
    return $u;
};
DB::table('jobs')->where('payload', 'like', '%CancelStripeSubscriptions%')->delete();
$soft = $mk('member1', 'sub_soft_1');
$hard = $mk('member2', 'sub_hard_1');
Elements::deleteElement($soft);
Elements::deleteElement($hard, true);
$jobs = DB::table('jobs')->where('payload', 'like', '%CancelStripeSubscriptions%')->get();
echo 'jobs queued: ', $jobs->count(), "\n";
\Laravel\Cashier\Cashier::$apiBaseUrl = 'http://127.0.0.1:12111';
foreach ($jobs as $row) {
    $cmd = unserialize(json_decode($row->payload, true)['data']['command']);
    echo 'job user ', $cmd->userId, ' → ', implode(',', $cmd->subscriptionIds), "\n";
    $cmd->handle();
    DB::table('jobs')->delete($row->id);
}
echo 'soft-deleted user row status: ', Subscription::where('stripe_id', 'sub_soft_1')->value('stripe_status'), "\n";
echo 'hard-deleted user rows left: ', Subscription::where('stripe_id', 'like', 'sub_hard_1%')->count(), "\n";
Elements::restoreElement(User::find()->username('member1')->status(null)->trashed(true)->one());
$m1 = Member::forUser(User::find()->username('member1')->status(null)->one()->id); $m1->stripe_id = 'cus_harness_1'; $m1->save();
echo 'member1 restored', "\n";
