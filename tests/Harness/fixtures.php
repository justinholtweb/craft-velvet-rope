<?php

// Velvet Rope harness fixtures (idempotent; everything prefixed `velvetrope`, removed by cleanup.php).
//   groups velvetropeGold, velvetropeSilver, velvetropeStaff
//   users  velvetrope-member1 (in velvetropeStaff, Stripe customer cus_harness_1), velvetrope-member2,
//          velvetrope-viewer (CP access + “Access Velvet Rope” + “View members” only), velvetrope-nobody (CP access only)
//   plans  gold, silver, broken (a price the Stripe mock rejects); gift option gold-year; gates for
//          vr/gate-* (gold) and the velvetropePremium section (any plan)
//   section velvetropePremium (vr-premium/{slug}, template vr/_premium) + entry velvetrope-premium-1
// Run with: ddev artisan tinker --execute "require '/var/www/sites/craft-velvetrope/tests/Harness/fixtures.php';"

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\TitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Data\UserGroup;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\VelvetRope\Models\Member;

$fail = fn (string $what, $model) => throw new RuntimeException($what.': '.json_encode(method_exists($model, 'errors') ? $model->errors()->all() : $model));

$groups = [];
foreach (['velvetropeGold' => 'VR Gold Members', 'velvetropeSilver' => 'VR Silver Members', 'velvetropeStaff' => 'VR Staff'] as $h => $n) {
    if (! UserGroups::getGroupByHandle($h)) {
        $g = new UserGroup(['name' => $n, 'handle' => $h]);
        UserGroups::saveGroup($g) || $fail("group {$h}", $g);
    }
    $groups[$h] = UserGroups::getGroupByHandle($h);
}

$user = function (string $username) use ($fail): User {
    $u = User::find()->username($username)->status(null)->trashed(null)->one();
    if ($u?->trashed) {
        Elements::restoreElement($u);
    }
    $u ??= new User;
    $u->username = $username;
    $u->email = "{$username}@velvetrope.test";
    $u->active = true;
    $u->pending = false;
    Elements::saveElement($u) || $fail("user {$username}", $u);

    return $u;
};

$m1 = $user('velvetrope-member1');
$m2 = $user('velvetrope-member2');
Users::assignUserToGroups($m1->id, [$groups['velvetropeStaff']->id]);
Users::assignUserToGroups($m2->id, []);
foreach (['velvetrope-viewer' => ['accessCp', 'accessPlugin-velvetrope', 'velvetrope:viewMembers'], 'velvetrope-nobody' => ['accessCp']] as $name => $perms) {
    $u = $user($name);
    Users::activateUser($u);
    $u->newPassword = 'velvetrope-local-only';
    Elements::saveElement($u) || $fail("password {$name}", $u);
    UserPermissions::saveUserPermissions($u->id, $perms);
}

// ---- A section the Pro section gate covers ----
$type = EntryTypes::getEntryTypeByHandle('velvetropePremium');
if (! $type) {
    $type = new EntryType(['name' => 'VR Premium', 'handle' => 'velvetropePremium']);
    $type->setFieldLayout(FieldLayout::createFromConfig(['type' => Entry::class, 'tabs' => [['name' => 'Content', 'elements' => [['type' => TitleField::class]]]]]));
    EntryTypes::saveEntryType($type) || $fail('entry type', $type);
    $type = EntryTypes::getEntryTypeByHandle('velvetropePremium');
}
$section = Sections::getSectionByHandle('velvetropePremium');
if (! $section) {
    $section = new Section(['name' => 'VR Premium', 'handle' => 'velvetropePremium', 'type' => SectionType::Channel]);
    $siteSettings = [];
    foreach (Sites::getAllSites() as $site) {
        $s = new SectionSiteSettings;
        $s->siteId = $site->id;
        $s->hasUrls = true;
        $s->uriFormat = 'vr-premium/{slug}';
        $s->template = 'vr/_premium';
        $s->enabledByDefault = true;
        $siteSettings[$site->id] = $s;
    }
    $section->setSiteSettings($siteSettings);
    $section->setEntryTypes([$type]);
    Sections::saveSection($section) || $fail('section', $section);
    $section = Sections::getSectionByHandle('velvetropePremium');
}
$entry = Entry::find()->section('velvetropePremium')->slug('velvetrope-premium-1')->status(null)->one()
    ?? new Entry(['sectionId' => $section->id, 'typeId' => $type->id]);
$entry->slug = 'velvetrope-premium-1';
$entry->title = 'Premium Story';
$entry->enabled = true;
Elements::saveElement($entry) || $fail('entry', $entry);

$p = Plugins::getPlugin('velvetrope');
Plugins::savePluginSettings($p, [
    'stripeSecret' => 'sk_test_harness_dummy', 'webhookSecret' => 'whsec_harness_local_only', 'graceDays' => 3,
    'successPath' => 'vr/welcome', 'cancelPath' => 'vr/twig', 'joinPath' => '', 'redeemPath' => 'vr/twig',
    'guestCheckout' => false, 'checkoutRateLimit' => 0, 'allowPromotionCodes' => false, 'dunningEmails' => true, 'showInCpNav' => true,
    'plans' => [
        ['handle' => 'gold', 'name' => 'Gold', 'priceId' => 'price_harness_gold', 'userGroup' => $groups['velvetropeGold']->uid, 'trialDays' => 0],
        ['handle' => 'silver', 'name' => 'Silver', 'priceId' => 'price_harness_silver', 'userGroup' => $groups['velvetropeSilver']->uid, 'trialDays' => 14],
        ['handle' => 'broken', 'name' => 'Broken', 'priceId' => 'price_harness_broken', 'userGroup' => '', 'trialDays' => 0],
    ],
    'giftOptions' => [['handle' => 'gold-year', 'plan' => 'gold', 'months' => 12, 'priceId' => 'price_harness_gift_gold']],
    'gates' => [
        ['type' => 'uri', 'match' => 'vr/gate-*', 'plans' => 'gold'],
        ['type' => 'section', 'match' => 'velvetropePremium', 'plans' => ''],
    ],
]) || throw new RuntimeException('settings');

$m = Member::forUser($m1->id);
$m->stripe_id = 'cus_harness_1';
$m->save();

echo json_encode(['member1' => $m1->id, 'member2' => $m2->id, 'gold' => $groups['velvetropeGold']->id, 'entry' => $entry->id]);
