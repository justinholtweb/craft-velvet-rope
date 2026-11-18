use CraftCms\Cms\User\Data\UserGroup;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\Support\Facades\{UserGroups, Elements, Plugins, Users};
$groups = [];
foreach (['gold' => 'Gold Members', 'staff' => 'Staff'] as $h => $n) {
    $g = UserGroups::getGroupByHandle($h) ?? new UserGroup(['name' => $n, 'handle' => $h]);
    if (! $g->id) UserGroups::saveGroup($g);
    $groups[$h] = UserGroups::getGroupByHandle($h);
}
$u = User::find()->username('member1')->status(null)->one();
if (! $u) { $u = new User(); $u->username = 'member1'; $u->email = 'member1@example.test'; $u->active = true; Elements::saveElement($u); }
Users::assignUserToGroups($u->id, [$groups['staff']->id]);
$p = Plugins::getPlugin('velvetrope');
echo 'settings: ', Plugins::savePluginSettings($p, [
    'stripeSecret' => 'sk_test_harness_dummy', 'webhookSecret' => 'whsec_harness_local_only', 'graceDays' => 3,
    'plans' => [['handle' => 'gold', 'name' => 'Gold', 'priceId' => 'price_harness_gold', 'userGroup' => $groups['gold']->uid, 'trialDays' => 0]],
]) ? 'saved' : 'fail', "\n";
$m = JustinHoltWeb\VelvetRope\Models\Member::forUser($u->id); $m->stripe_id = 'cus_harness_1'; $m->save();
echo "user {$u->id} member {$m->id} gold={$groups['gold']->id} staff={$groups['staff']->id}\n";
