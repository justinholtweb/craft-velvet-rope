#!/bin/bash
# Live webhook-lifecycle checks against the Craft 6 harness (Craft Pro). No Stripe account involved:
# webhooks are signed locally with the harness's fake secret, exactly as Stripe signs them.
# Prereq: tests/Harness/fixtures.php run through `ddev artisan tinker --execute` (user member1 →
# Staff group; plan gold → Gold Members; member stripe_id cus_harness_1).
set -uo pipefail
exec < /dev/null
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

tk 'JustinHoltWeb\VelvetRope\Models\Subscription::query()->delete(); $u=CraftCms\Cms\User\Elements\User::find()->username("member1")->status(null)->one(); CraftCms\Cms\Support\Facades\Users::assignUserToGroups($u->id, [CraftCms\Cms\Support\Facades\UserGroups::getGroupByHandle("staff")->id]); echo "reset";' >/dev/null
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

echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
