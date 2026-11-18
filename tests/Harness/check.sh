#!/bin/bash
# Live webhook-lifecycle checks against the Craft 6 harness (Craft Pro). No Stripe account involved:
# webhooks are signed locally with the harness's fake secret, exactly as Stripe signs them.
# Prereq: tests/Harness/fixtures.php run through `ddev artisan tinker --execute` (user member1 →
# Staff group; plan gold → Gold Members; member stripe_id cus_harness_1). Templates: copy tests/Harness/templates/*
# to the harness's resources/views/vr/, and add the `vr/route-gated` route (see README).
set -uo pipefail
exec < /dev/null
HERE=$(cd "$(dirname "$0")" && pwd)
H=/Users/jholt/Sites/plugin-testing-craft6
U=https://plugin-testing-craft6.ddev.site
SECRET=whsec_harness_local_only
cd "$H" || exit 2
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v WARNING | tail -1; }
groups() { tk '$u=CraftCms\Cms\User\Elements\User::find()->username("member1")->status(null)->one(); echo implode(",", CraftCms\Cms\Support\Facades\UserGroups::getGroupsByUserId($u->id)->pluck("handle")->sort()->values()->all());'; }
# payload <type> <status> [extra-json-fields] [price]
payload() { python3 -I -c '
import json,sys,time
typ,status,extra,price=sys.argv[1],sys.argv[2],json.loads(sys.argv[3] or "{}"),sys.argv[4]
obj={"id":"sub_harness_1","object":"subscription","customer":"cus_harness_1","status":status,"metadata":{"type":"gold"},
     "items":{"data":[{"id":"si_harness_1","price":{"id":price,"product":"prod_harness"},"quantity":1}]}}
obj.update(extra)
print(json.dumps({"id":"evt_"+str(time.time_ns()),"type":typ,"data":{"object":obj}}))' "$1" "$2" "${3:-}" "${4:-price_harness_gold}"; }
sign() { python3 -I -c '
import hmac,hashlib,sys,time
body,secret,ts=sys.stdin.read(),sys.argv[1],int(sys.argv[2]) if len(sys.argv)>2 else int(time.time())
print("t=%d,v1=%s" % (ts, hmac.new(secret.encode(), ("%d.%s" % (ts, body)).encode(), hashlib.sha256).hexdigest()))' "$@"; }
post() { local body=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' "$@" --data "$body" "$U/stripe/webhook"; }
send() { local body; body=$(payload "$@"); post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign $SECRET)"; }

tk 'JustinHoltWeb\VelvetRope\Models\Subscription::query()->delete(); $u=CraftCms\Cms\User\Elements\User::find()->username("member1")->status(null)->one(); $m=JustinHoltWeb\VelvetRope\Models\Member::forUser($u->id); $m->stripe_id="cus_harness_1"; $m->save(); CraftCms\Cms\Support\Facades\Users::assignUserToGroups($u->id, [CraftCms\Cms\Support\Facades\UserGroups::getGroupByHandle("staff")->id]); echo "reset";' >/dev/null
ok "start: only Staff" "$(groups)" "staff"

body=$(payload customer.subscription.created active)
ok "unsigned webhook refused" "$(post "$body")" "403"
ok "badly signed webhook refused" "$(post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign whsec_wrong)")" "403"
ok "replayed (stale timestamp) webhook refused" "$(post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign $SECRET $(( $(date +%s) - 3600 )))")" "403"
ok "no member change from refused webhooks" "$(groups)" "staff"

ok "subscription.created (active) accepted" "$(send customer.subscription.created active)" "200"
ok "active → Gold added, Staff kept" "$(groups)" "gold,staff"

ok "subscription.updated (past_due) accepted" "$(send customer.subscription.updated past_due)" "200"
ok "past_due within grace → still Gold" "$(groups)" "gold,staff"
ok "past_due_since recorded" "$(tk 'echo JustinHoltWeb\VelvetRope\Models\Subscription::where("stripe_id","sub_harness_1")->first()->past_due_since ? "yes" : "no";')" "yes"
tk 'JustinHoltWeb\VelvetRope\Models\Subscription::where("stripe_id","sub_harness_1")->update(["past_due_since" => now()->subDays(4)]); echo 1;' >/dev/null
ddev artisan craft:velvetrope:sync >/dev/null 2>&1
ok "grace expired + hourly sync → Gold removed, Staff kept" "$(groups)" "staff"

ok "subscription.updated (active again)" "$(send customer.subscription.updated active)" "200"
ok "paid up → Gold back" "$(groups)" "gold,staff"
ok "past_due_since cleared" "$(tk 'echo JustinHoltWeb\VelvetRope\Models\Subscription::where("stripe_id","sub_harness_1")->first()->past_due_since ? "set" : "cleared";')" "cleared"

future=$(( $(date +%s) + 86400 * 10 ))
ok "cancellation scheduled (cancel_at in 10 days)" "$(send customer.subscription.updated active "{\"cancel_at\": $future}")" "200"
ok "cancelled but paid to period end → still Gold" "$(groups)" "gold,staff"

ok "subscription.deleted accepted" "$(send customer.subscription.deleted canceled)" "200"
ok "deleted → Gold removed, Staff kept" "$(groups)" "staff"

tk 'JustinHoltWeb\VelvetRope\Models\Subscription::query()->delete(); echo 1;' >/dev/null
ok "subscription for an unknown price accepted" "$(send customer.subscription.created active '{"id":"sub_harness_2","metadata":{}}' price_not_a_plan)" "200"
ok "unknown price → no group change" "$(groups)" "staff"

ok "checkout while logged out → login redirect" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/velvet-rope/checkout/gold")" "302"
J=$("$H/bin/cp-session")
ok "checkout for an unknown plan → 404" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/velvet-rope/checkout/platinum")" "404"
ok "checkout with a dummy Stripe key → 502, not 500" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/velvet-rope/checkout/gold")" "502"
ok "settings page loads" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/velvetrope")" "200"

tk '$p=CraftCms\Cms\Support\Facades\Plugins::getPlugin("velvetrope"); CraftCms\Cms\Support\Facades\Plugins::savePluginSettings($p, ["webhookSecret" => null]); echo 1;' >/dev/null
ok "no webhook secret configured → webhooks refused (503)" "$(send customer.subscription.created active)" "503"
tk '$p=CraftCms\Cms\Support\Facades\Plugins::getPlugin("velvetrope"); CraftCms\Cms\Support\Facades\Plugins::savePluginSettings($p, ["webhookSecret" => "whsec_harness_local_only"]); echo 1;' >/dev/null
ok "…and nothing was granted" "$(groups)" "staff"

# ---- Phase 2: one template API, three front doors (Twig, Blade, route middleware) ----
setadmin() { tk "\$a=CraftCms\\Cms\\User\\Elements\\User::find()->admin()->one(); \$m=JustinHoltWeb\\VelvetRope\\Models\\Member::forUser(\$a->id); \$m->stripe_id='cus_harness_admin'; \$m->save(); \$m->subscriptions()->updateOrCreate(['stripe_id'=>'sub_harness_admin'],['type'=>'gold','stripe_status'=>'$1','stripe_price'=>'price_harness_gold','past_due_since'=>$2]); echo 1;" >/dev/null; }
marks() { curl -sk -b "$J" "$U/$1" | grep -o '<p id="[a-z]*">[^<]*' | sed -E 's/<p id="([a-z]*)">/\1=/; s/ +//g' | tr '\n' ' '; }
setadmin canceled null
for engine in twig blade; do
  ok "$engine: non-member sees GOLD-NO" "$(marks vr/$engine | grep -o 'gold=GOLD-[A-Z]*')" "gold=GOLD-NO"
done
ok "Twig and Blade agree (non-member)" "$(marks vr/twig)" "$(marks vr/blade)"
for g in twig-gated blade-gated route-gated; do
  ok "$g: guest → login" "$(curl -sk -o /dev/null -w '%{redirect_url}' "$U/vr/$g")" "$U/login"
  ok "$g: non-member → the plan's checkout" "$(curl -sk -b "$J" -o /dev/null -w '%{redirect_url}' "$U/vr/$g")" "$U/velvet-rope/checkout/gold"
done
setadmin active null
ok "Twig and Blade agree (member)" "$(marks vr/twig)" "$(marks vr/blade)"
ok "member sees GOLD-YES + status" "$(marks vr/blade | grep -oE 'gold=GOLD-YES status=active')" "gold=GOLD-YES status=active"
ok "twig-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/twig-gated" | grep -c TWIG-SECRET)" "1"
ok "blade-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/blade-gated" | grep -c BLADE-SECRET)" "1"
ok "route-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/route-gated")" "ROUTE-SECRET"
setadmin past_due "now()->subDays(10)"
ok "lapsed grace: Twig and Blade both say GOLD-NO" "$(marks vr/twig | grep -o 'gold=GOLD-[A-Z]*')$(marks vr/blade | grep -o 'gold=GOLD-[A-Z]*')" "gold=GOLD-NOgold=GOLD-NO"
setadmin canceled null

# ---- Phase 2: deleting a user cancels their Stripe billing (against a local Stripe mock) ----
cp "$HERE/stripe-mock-router.php" "$H/storage/stripe-mock/router.php" 2>/dev/null || { mkdir -p "$H/storage/stripe-mock"; cp "$HERE/stripe-mock-router.php" "$H/storage/stripe-mock/router.php"; }
rm -f "$H/storage/stripe-mock/calls.log"
ddev exec "pgrep -f 'php -S 127.0.0.1:12111' >/dev/null || (nohup php -S 127.0.0.1:12111 storage/stripe-mock/router.php >/tmp/stripe-mock.out 2>&1 &)" >/dev/null 2>&1
out=$(ddev artisan tinker --execute "$(cat "$HERE/delete-users.php")" 2>/dev/null | grep -v WARNING)
calls=$(cat "$H/storage/stripe-mock/calls.log" 2>/dev/null)
ok "a cancel job is queued per deleted user (soft + hard)" "$(echo "$out" | grep -c '^job user')" "2"
ok "soft-deleted user's subscription cancelled at Stripe" "$(echo "$calls" | grep -c 'DELETE /v1/subscriptions/sub_soft_1$')" "1"
ok "hard-deleted user's subscription cancelled at Stripe (captured before cascade)" "$(echo "$calls" | grep -c 'DELETE /v1/subscriptions/sub_hard_1$')" "1"
ok "already-cancelled subscriptions aren't re-sent" "$(echo "$calls" | grep -c '_old')" "0"
ok "soft-deleted user's local row marked canceled" "$(echo "$out" | grep -o 'row status: [a-z]*')" "row status: canceled"

echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
