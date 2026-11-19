<?php

// Removes everything Velvet Rope's harness fixtures created (all `velvetrope`-prefixed), plus the
// unprefixed `member1`/`member2` users from Velvet Rope's first harness runs. Leaves the shared
// `gold`/`staff` groups alone (other plugins' checks use them). Plugin settings stay as fixtures left them.

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\VelvetRope\Models\Gift;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Models\GuestCheckout;
use JustinHoltWeb\VelvetRope\Models\Member;

foreach (Entry::find()->section('velvetropePremium')->status(null)->site('*')->unique()->all() as $e) {
    Elements::deleteElement($e, true);
}
if ($s = Sections::getSectionByHandle('velvetropePremium')) {
    Sections::deleteSection($s);
}
if ($t = EntryTypes::getEntryTypeByHandle('velvetropePremium')) {
    EntryTypes::deleteEntryType($t);
}
// Velvet Rope's own tables: anything left from a run that didn't finish.
GuestCheckout::query()->delete();
Gift::query()->delete();
Grant::query()->delete();
foreach (Member::where('stripe_id', 'like', 'cus_guest%')->get() as $m) {
    if (($u = User::find()->id($m->userId)->status(null)->trashed(null)->one()) && str_ends_with((string) $u->email, '@velvetrope.test')) {
        Elements::deleteElement($u, true);
    }
    $m->delete();
}
$users = User::find()->status(null)->trashed(null)->all();
foreach ($users as $u) {
    if (str_starts_with((string) $u->username, 'velvetrope-') || in_array($u->username, ['member1', 'member2'], true) || str_ends_with((string) $u->email, '@velvetrope.test')) {
        Elements::deleteElement($u, true);
    }
}
foreach (['velvetropeGold', 'velvetropeSilver', 'velvetropeStaff'] as $h) {
    if ($g = UserGroups::getGroupByHandle($h)) {
        UserGroups::deleteGroup($g);
    }
}
foreach (Gql::getTokens() as $t) {
    if (str_starts_with((string) $t->name, 'velvetrope_')) {
        Gql::deleteTokenById($t->id);
    }
}
foreach (Gql::getSchemas() as $s) {
    if (str_starts_with((string) $s->name, 'velvetrope_')) {
        Gql::deleteSchema($s);
    }
}
echo 'cleaned';
