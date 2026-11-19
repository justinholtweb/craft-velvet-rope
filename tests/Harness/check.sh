#!/bin/bash
# Velvet Rope live checks against the Craft 6 harness (Craft Pro). No Stripe account involved: webhooks are
# signed locally with the harness's fake secret, exactly as Stripe signs them, and API calls go to a local
# Stripe mock (tests/Harness/stripe-mock-router.php) through the loopback-only VELVETROPE_STRIPE_API_BASE seam
# in the harness .env. Email is read back from the harness's Mailpit.
# Self-contained: runs fixtures.php + gql-fixtures.php, copies templates to resources/views/vr/, and cleans up
# (cleanup.php) at the end. The multisite section runs when the `velvetropefr` site exists
# (multisite-fixtures.php, added and removed under bin/harness-lock — see the README).
# Also needs the app route `vr/route-gated` (harness routes/web.php, see the README).
set -uo pipefail
exec < /dev/null
HERE=$(cd "$(dirname "$0")" && pwd)
H=/Users/jholt/Sites/plugin-testing-craft6
U=https://plugin-testing-craft6.ddev.site
SECRET=whsec_harness_local_only
MOCK=$H/storage/stripe-mock
FX=/var/www/sites/craft-velvetrope/tests/Harness
cd "$H" || exit 2
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
section() { echo; echo "---- $1 ----"; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v WARNING | tail -1; }
# Project-config writes can race other agents' (Stale/BusyResourceException): retry those, backing off.
tkr() { local out i; for i in 1 2 3 4 5 6 7 8 9 10; do out=$(tk "$1"); case "$out" in *StaleResource*|*BusyResource*|*"has changed since"*|*"being modified"*) sleep $i;; *) echo "$out"; return;; esac; done; echo "$out"; }
req() { tkr "require '$FX/$1';"; }
UE='CraftCms\Cms\User\Elements\User'
VR='JustinHoltWeb\VelvetRope'
F='CraftCms\Cms\Support\Facades'
uid() { tk "echo $UE::find()->username('$1')->status(null)->one()?->id ?? 'none';"; }
groups() { tk "\$u=$UE::find()->$2('$1')->status(null)->one(); echo \$u ? implode(',', $F\\UserGroups::getGroupsByUserId(\$u->id)->pluck('handle')->sort()->values()->all()) : 'nouser';"; }
g1() { groups velvetrope-member1 username; }
settings() { local r; r=$(tkr "\$p=$F\\Plugins::getPlugin('velvetrope'); echo $F\\Plugins::savePluginSettings(\$p, $1) ? 'saved' : 'invalid';"); [ "$r" = saved ] || echo "!! settings $1 not saved: $r"; }
edition() { local r; r=$(tkr "$F\\Plugins::switchEdition('velvetrope', '$1'); echo $VR\\Plugin::isPro() ? 'pro' : 'lite';"); [ "$r" = "$1" ] || echo "!! edition switch to $1 failed: $r"; }
queue() { ddev artisan queue:work --queue=default --stop-when-empty >/dev/null 2>&1; }
# Mailpit (inside the web container)
mailq() { ddev exec "curl -s 'http://127.0.0.1:8025/api/v1/search?query=$(python3 -I -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$1")'" 2>/dev/null; }
mailclear() { ddev exec "curl -s -X DELETE 'http://127.0.0.1:8025/api/v1/search?query=velvetrope.test'" >/dev/null 2>&1; }
subjects() { mailq "to:$1" | python3 -I -c 'import json,sys; print("|".join(sorted(m["Subject"] for m in json.load(sys.stdin).get("messages", []))))'; }
mailtext() { local id; id=$(mailq "to:$1 subject:\"$2\"" | python3 -I -c 'import json,sys; m=json.load(sys.stdin).get("messages", []); print(m[0]["ID"] if m else "")'); [ -n "$id" ] && ddev exec "curl -s http://127.0.0.1:8025/api/v1/message/$id" 2>/dev/null | python3 -I -c 'import json,sys; print(json.load(sys.stdin).get("Text",""))'; }
# Webhooks: sign like Stripe (t=…,v1=HMAC-SHA256 of "t.body") and post to Cashier's endpoint.
sign() { python3 -I -c '
import hmac,hashlib,sys,time
body,secret,ts=sys.stdin.read(),sys.argv[1],int(sys.argv[2]) if len(sys.argv)>2 else int(time.time())
print("t=%d,v1=%s" % (ts, hmac.new(secret.encode(), ("%d.%s" % (ts, body)).encode(), hashlib.sha256).hexdigest()))' "$@"; }
post() { local body=$1; shift; curl -sk -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' "$@" --data "$body" "$U/stripe/webhook"; }
event() { python3 -I -c 'import json,sys,time; print(json.dumps({"id":"evt_"+str(time.time_ns()),"type":sys.argv[1],"data":{"object":json.loads(sys.argv[2])}}))' "$1" "$2"; }
sendevent() { local body; body=$(event "$1" "$2"); post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign $SECRET)"; }
# subobj <status> [extra-json] [price] [customer] [sub-id] [unit_amount] [plan-type]
subobj() { python3 -I -c '
import json,sys
status,extra,price,cus,sid,amount,typ=sys.argv[1:8]
o={"id":sid,"object":"subscription","customer":cus,"status":status,"metadata":{"type":typ} if typ else {},
   "items":{"data":[{"id":"si_"+sid,"quantity":1,"price":{"id":price,"product":"prod_harness","unit_amount":int(amount),"currency":"usd","recurring":{"interval":"month","interval_count":1,"usage_type":"licensed"}}}]}}
o.update(json.loads(extra or "{}")); print(json.dumps(o))' "$1" "${2:-}" "${3:-price_harness_gold}" "${4:-cus_harness_1}" "${5:-sub_harness_1}" "${6:-1500}" "${7-gold}"; }
send() { sendevent "$1" "$(subobj "${@:2}")"; }
calls() { cat "$MOCK/calls.log" 2>/dev/null; }
redirect() { curl -sk -b "${2:-/dev/null}" -o /dev/null -w '%{redirect_url}' "$U/$1"; }
code() { curl -sk -b "${2:-/dev/null}" -o /dev/null -w '%{http_code}' "$U/$1"; }
marks() { curl -sk -b "${2:-$J}" "$U/$1" | grep -o '<p id="[a-z]*">[^<]*' | sed -E 's/<p id="([a-z]*)"> */\1=/; s/ +$//' | tr '\n' '|'; }
mark() { marks "$1" "${3:-$J}" | tr '|' '\n' | grep "^$2=" | cut -d= -f2-; }  # mark <path> <id> [cookie-jar]
# CP pages; -L because a multi-site CP (other agents add sites) redirects to add ?site=.
cpstrings() { curl -sLk -b "$2" "$U/admin/$1" | python3 -I "$HERE/cp-strings.py"; }
xsrf() { awk '$6=="XSRF-TOKEN"{print $7}' "$1" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
cppost() { local jar=$1 path=$2; shift 2; curl -sk -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -H "X-XSRF-TOKEN: $(xsrf "$jar")" -X POST "$U/admin/$path" "$@"; }
gq() { curl -sk -H "Authorization: Bearer $(printf 'velvetrope%s' "$1" | awk '{ s=$0; while (length(s) < 32) s=s "0"; print s }')" -H 'Content-Type: application/json' \
  --data "$(python3 -I -c 'import json,sys; print(json.dumps({"query": sys.argv[1]}))' "$2")" "$U/actions/graphql/api" | python3 -I -c '
import json,sys
d=json.load(sys.stdin); b=d.get("data") or {}
if d.get("errors"): print("ERR:"+d["errors"][0]["message"]); sys.exit()
j=lambda *xs: ",".join(str(x) for x in xs)
# The third argument is a Python expression written in this script (not data from the response).
print(eval(sys.argv[1]))' "$3"; }

# ---- Setup ----
mkdir -p "$H/resources/views/vr" "$MOCK/fixtures"
cp "$HERE"/templates/* "$H/resources/views/vr/"
cp "$HERE/stripe-mock-router.php" "$MOCK/router.php"
rm -f "$MOCK/calls.log" "$MOCK"/fixtures/*.json
grep -q '^VELVETROPE_STRIPE_API_BASE=http://127.0.0.1:12111' .env || { echo "harness .env lacks VELVETROPE_STRIPE_API_BASE (see README)"; exit 2; }
ddev exec "curl -s -o /dev/null 127.0.0.1:12111/v1/ping || (setsid nohup php -S 127.0.0.1:12111 storage/stripe-mock/router.php >/tmp/stripe-mock.out 2>&1 < /dev/null &); sleep 1" >/dev/null 2>&1
ok "setup: leftovers from earlier runs removed" "$(req cleanup.php)" "cleaned"
ok "setup: fixtures" "$(req fixtures.php | grep -c member1)" "1"
ok "setup: graphql fixtures" "$(req gql-fixtures.php)" "ok"
edition lite
queue
mailclear
M1=$(uid velvetrope-member1)
reset1() { tk "$VR\\Models\\Subscription::query()->delete(); $VR\\Models\\Grant::query()->delete(); $VR\\Models\\Gift::query()->delete(); $VR\\Models\\GuestCheckout::query()->delete(); \$m=$VR\\Models\\Member::forUser($M1); \$m->stripe_id='cus_harness_1'; \$m->save(); app($VR\\Membership\\GroupSync::class)->syncUser($M1); echo 1;" >/dev/null; }
reset1

section "Phase 1: Stripe webhooks → user groups (Lite)"
ok "start: only VR Staff" "$(g1)" "velvetropeStaff"
body=$(event customer.subscription.created "$(subobj active)")
ok "unsigned webhook refused" "$(post "$body")" "403"
ok "badly signed webhook refused" "$(post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign whsec_wrong)")" "403"
ok "replayed (stale timestamp) webhook refused" "$(post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign $SECRET $(( $(date +%s) - 3600 )))")" "403"
ok "no member change from refused webhooks" "$(g1)" "velvetropeStaff"
ok "subscription.created (active) accepted" "$(send customer.subscription.created active)" "200"
ok "active → Gold added, Staff kept" "$(g1)" "velvetropeGold,velvetropeStaff"
ok "price recorded for MRR (1500 usd / 1 month)" "$(tk "\$s=$VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->first(); echo \$s->amount.' '.\$s->currency.' '.\$s->interval_count.' '.\$s->interval;")" "1500 usd 1 month"
ok "subscription.updated (past_due) accepted" "$(send customer.subscription.updated past_due)" "200"
ok "past_due within grace → still Gold" "$(g1)" "velvetropeGold,velvetropeStaff"
ok "past_due_since recorded" "$(tk "echo $VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->first()->past_due_since ? 'yes' : 'no';")" "yes"
tk "$VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->update(['past_due_since' => now()->subDays(4)]); echo 1;" >/dev/null
ddev artisan craft:velvetrope:sync >/dev/null 2>&1
ok "grace expired + hourly sync → Gold removed, Staff kept" "$(g1)" "velvetropeStaff"
ok "Lite sends no dunning email" "$(subjects velvetrope-member1@velvetrope.test)" ""
ok "subscription.updated (active again)" "$(send customer.subscription.updated active)" "200"
ok "paid up → Gold back" "$(g1)" "velvetropeGold,velvetropeStaff"
ok "past_due_since cleared" "$(tk "echo $VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->first()->past_due_since ? 'set' : 'cleared';")" "cleared"
future=$(( $(date +%s) + 86400 * 10 ))
ok "cancellation scheduled (cancel_at in 10 days)" "$(send customer.subscription.updated active "{\"cancel_at\": $future}")" "200"
ok "cancelled but paid to period end → still Gold" "$(g1)" "velvetropeGold,velvetropeStaff"
ok "subscription.deleted accepted" "$(send customer.subscription.deleted canceled)" "200"
ok "deleted → Gold removed, Staff kept" "$(g1)" "velvetropeStaff"
ok "an event for an object without a price keeps the last known price" "$(tk "echo $VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->value('amount');")" "1500"
tk "$VR\\Models\\Subscription::query()->delete(); echo 1;" >/dev/null
ok "subscription for an unknown price accepted" "$(send customer.subscription.created active '{"metadata":{}}' price_not_a_plan cus_harness_1 sub_harness_2 1500 '')" "200"
ok "unknown price → no group change" "$(g1)" "velvetropeStaff"
settings "['webhookSecret' => null]"
ok "no webhook secret configured → webhooks refused (503)" "$(send customer.subscription.created active)" "503"
settings "['webhookSecret' => 'whsec_harness_local_only']"
ok "…and nothing was granted" "$(g1)" "velvetropeStaff"

section "Checkout (logged in, against the Stripe mock)"
J=$("$HERE/login.sh" admin claudepassword)
ADMIN=$(uid admin)
ok "checkout while logged out (guest checkout off) → login" "$(redirect actions/velvetrope/checkout/gold)" "$U/login"
ok "checkout for an unknown plan → 404" "$(code actions/velvetrope/checkout/platinum "$J")" "404"
ok "a Stripe API error → 502, not 500" "$(code actions/velvetrope/checkout/broken "$J")" "502"
settings "['checkoutRateLimit' => 2]"
ok "checkout links are rate-limited per visitor (3rd in a minute → 429)" "$(code actions/velvetrope/checkout/broken "$J")/$(code actions/velvetrope/checkout/broken "$J")/$(code actions/velvetrope/checkout/broken "$J")" "502/502/429"
settings "['checkoutRateLimit' => 0]"
rm -f "$MOCK/calls.log"
ok "checkout → Stripe Checkout's hosted page" "$(redirect actions/velvetrope/checkout/silver "$J" | grep -c '^https://checkout.stripe.test/c/pay/cs_mock_')" "1"
ok "session: subscription for the plan's price, typed with the plan handle" "$(calls | grep 'POST /v1/checkout/sessions' | grep -c 'line_items\[0\]\[price\]=price_harness_silver.*subscription_data\[metadata\]\[type\]=silver\|subscription_data\[metadata\]\[type\]=silver.*price_harness_silver')" "1"
ok "session: the plan's 14-day trial" "$(calls | grep 'checkout/sessions' | grep -oE 'subscription_data\[trial_end\]=[0-9]+' | cut -d= -f2 | python3 -I -c 'import sys,time; print(round((int(sys.stdin.read() or 0)-time.time())/86400))')" "14"
ok "session: return URLs on this site, locale from the site language" "$(calls | grep 'checkout/sessions' | grep -o "success_url=$U/vr/welcome\|cancel_url=$U/vr/twig\|locale=en" | sort -u | tr '\n' ' ')" "cancel_url=$U/vr/twig locale=en success_url=$U/vr/welcome "
ok "Lite: no promotion codes on the session" "$(calls | grep -c 'promotion_code')" "0"
setadmin() { tk "\$m=$VR\\Models\\Member::forUser($ADMIN); \$m->stripe_id='cus_harness_admin'; \$m->save(); \$m->subscriptions()->updateOrCreate(['stripe_id'=>'sub_harness_admin'],['type'=>'${3:-gold}','stripe_status'=>'$1','stripe_price'=>'price_harness_${3:-gold}','past_due_since'=>$2,'ends_at'=>null]); echo 1;" >/dev/null; }
setadmin active null
ok "already a member of the plan → straight to the success page" "$(redirect actions/velvetrope/checkout/gold "$J")" "$U/vr/welcome"
ok "Lite: a member checking out another plan → billing portal (switch there)" "$(redirect actions/velvetrope/checkout/silver "$J")" "$U/actions/velvetrope/billing"
ok "billing portal → Stripe's portal" "$(redirect actions/velvetrope/billing "$J")" "https://billing.stripe.test/p/session/bps_mock"
ok "billing portal while logged out → login" "$(redirect actions/velvetrope/billing)" "$U/login"

section "Phase 2: one template API, three front doors (Twig, Blade, route middleware)"
setadmin canceled null
for engine in twig blade; do
  ok "$engine: non-member sees GOLD-NO" "$(mark vr/$engine gold)" "GOLD-NO"
done
ok "Twig and Blade agree (non-member)" "$(marks vr/twig)" "$(marks vr/blade)"
for g in twig-gated blade-gated route-gated; do
  ok "$g: guest → login" "$(redirect vr/$g)" "$U/login"
  ok "$g: non-member → the plan's checkout" "$(redirect vr/$g "$J")" "$U/actions/velvetrope/checkout/gold"
done
setadmin active null
ok "Twig and Blade agree (member)" "$(marks vr/twig)" "$(marks vr/blade)"
ok "member sees GOLD-YES + status" "$(mark vr/blade gold)/$(mark vr/blade status)" "GOLD-YES/active"
ok "plans listed with names (both engines)" "$(mark vr/twig plans)/$(mark vr/blade plans)" "gold:Gold,silver:Silver,broken:Broken/gold:Gold,silver:Silver,broken:Broken"
ok "Lite: no gift options or gift links (both engines)" "$(mark vr/twig gifts)$(mark vr/twig gifturl)$(mark vr/blade gifts)$(mark vr/blade gifturl)" ""
ok "twig-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/twig-gated" | grep -c TWIG-SECRET)" "1"
ok "blade-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/blade-gated" | grep -c BLADE-SECRET)" "1"
ok "route-gated: member gets the page" "$(curl -sk -b "$J" "$U/vr/route-gated")" "ROUTE-SECRET"
ok "a gate fed an unset variable fails closed for a member (500, not the page)" "$(code vr/twig-badgate "$J")" "500"
setadmin past_due "now()->subDays(10)"
ok "lapsed grace: Twig and Blade both say GOLD-NO" "$(mark vr/twig gold)$(mark vr/blade gold)" "GOLD-NOGOLD-NO"
setadmin canceled null

section "Phase 2: deleting a user cancels their Stripe billing (Stripe mock)"
rm -f "$MOCK/calls.log"
out=$(ddev artisan tinker --execute "require '$FX/delete-users.php';" 2>/dev/null | grep -v WARNING)
ok "a cancel job is queued per deleted user (soft + hard)" "$(echo "$out" | grep -c '^job user')" "2"
ok "soft-deleted user's subscription cancelled at Stripe" "$(calls | grep -c 'DELETE /v1/subscriptions/sub_soft_1 ')" "1"
ok "hard-deleted user's subscription cancelled at Stripe (captured before cascade)" "$(calls | grep -c 'DELETE /v1/subscriptions/sub_hard_1 ')" "1"
ok "already-cancelled subscriptions aren't re-sent" "$(calls | grep -c '_old')" "0"
ok "soft-deleted user's local row marked canceled" "$(echo "$out" | grep -o 'row status: [a-z]*')" "row status: canceled"
req fixtures.php >/dev/null
M1=$(uid velvetrope-member1)
reset1

section "Phase 3: CP members index, member screen, permissions"
send customer.subscription.created active >/dev/null
send customer.subscription.created trialing '{"trial_end": '$(( $(date +%s) + 86400 * 7 ))'}' price_harness_silver cus_harness_1 sub_harness_silver 900 silver >/dev/null
setadmin active null
S=$(cpstrings velvetrope "$J")
ok "index lists member1 by email" "$(echo "$S" | grep -c '^velvetrope-member1@velvetrope.test$')" "1"
ok "index shows member1's plans with status" "$(echo "$S" | grep -o 'Gold · Active\|Silver · Trialing' | sort -u | tr '\n' ' ')" "Gold · Active Silver · Trialing "
ok "MRR counts paying subscriptions, not trials (\$15.00)" "$(echo "$S" | grep -o 'velvetrope-mrr" style="[^"]*">[^<]*' | sed 's/.*>//')" "\$15.00"
ok "members and trial counts" "$(echo "$S" | grep -o 'velvetrope-\(members\|trialing\)" style="[^"]*">[^<]*' | sed 's/.*">//' | tr '\n' '/')" "2/1/"
ok "a paying subscription with no known price is called out, not guessed" "$(echo "$S" | grep -c 'no known price yet')" "1"
ok "search narrows to one member" "$(cpstrings 'velvetrope?search=member1' "$J" | grep -cE '@velvetrope\.test$|^admin@example\.test$')" "1"
ok "status filter (trialing) → only member1" "$(cpstrings 'velvetrope?status=trialing' "$J" | grep -cE '@velvetrope\.test$|^admin@example\.test$')" "1"
M=$(cpstrings "users/$M1/membership" "$J")
ok "Membership screen: plans, Stripe ids, price and MRR" "$(echo "$M" | grep -Fx -e 'sub_harness_1' -e 'sub_harness_silver' -e '$15.00 / month' -e '$9.00 / month' | sort | tr '\n' ' ')" "\$15.00 / month \$9.00 / month sub_harness_1 sub_harness_silver "
ok "Membership screen is in the user's account nav" "$(echo "$M" | grep -c "^$U/admin/users/$M1/membership\(?site=[a-z0-9]*\)\{0,1\}$")" "1"
ok "Membership screen offers resync (admin can manage)" "$(echo "$M" | grep -c 'velvetrope/members/'$M1'/resync')" "1"
ok "Lite: no complimentary-access form" "$(echo "$M" | grep -c 'Grant access')" "0"
ok "settings page loads" "$(code admin/settings/plugins/velvetrope "$J")" "200"
V=$("$HERE/login.sh" velvetrope-viewer velvetrope-local-only)
N=$("$HERE/login.sh" velvetrope-nobody velvetrope-local-only)
ok "setup: viewer and no-permission users logged in" "$([ -s "$V" ] && [ -s "$N" ] && echo yes)" "yes"
ok "“View members” can open the index" "$(code admin/velvetrope "$V")" "200"
ok "no permission → index 403" "$(code admin/velvetrope "$N")" "403"
ok "no permission → Membership screen 403" "$(code "admin/users/$M1/membership" "$N")" "403"
ok "no permission → no sidebar item" "$(curl -sLk -b "$N" "$U/admin/dashboard" | python3 -I "$HERE/cp-strings.py" nav | grep -c "^$U/admin/velvetrope\(?site=[a-z0-9]*\)\{0,1\}$")" "0"
ok "viewer sees the sidebar item" "$(curl -sLk -b "$V" "$U/admin/dashboard" | python3 -I "$HERE/cp-strings.py" nav | grep -c "^$U/admin/velvetrope\(?site=[a-z0-9]*\)\{0,1\}$")" "1"
ok "view-only can't resync (403)" "$(cppost "$V" "velvetrope/members/$M1/resync")" "403"
ok "resync without a CSRF token refused" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' -X POST "$U/admin/velvetrope/members/$M1/resync")" "419"
ok "admin resync → back to the Membership screen" "$(cppost "$J" "velvetrope/members/$M1/resync")" "302"
ok "Lite: granting access is Pro (404)" "$(cppost "$J" "velvetrope/members/$M1/grants" --data 'plan=gold&months=1')" "404"
subobj active '' price_harness_gold cus_harness_1 sub_harness_1 1500 gold > "$MOCK/fixtures/sub_harness_1.json"
subobj trialing '{"trial_end": '$(( $(date +%s) + 86400 * 7 ))'}' price_harness_silver cus_harness_1 sub_harness_silver 900 silver > "$MOCK/fixtures/sub_harness_silver.json"
ok "MRR refresh from Stripe (--refresh) records the mock's price" "$(python3 -I -c 'import json; print(json.dumps({"id":"sub_harness_admin","object":"subscription","customer":"cus_harness_admin","status":"active","metadata":{"type":"gold"},"items":{"data":[{"id":"si_a","quantity":2,"price":{"id":"price_harness_gold","product":"p","unit_amount":12000,"currency":"usd","recurring":{"interval":"year","interval_count":1}}}]},"discount":{"coupon":{"percent_off":50,"duration":"forever"}}}))' > "$MOCK/fixtures/sub_harness_admin.json"; ddev artisan craft:velvetrope:sync --refresh 2>/dev/null | grep -o 'Refreshed [0-9]* subscriptions' | head -1)" "Refreshed 3 subscriptions"
ok "…yearly × 2 seats at 50% off → \$10.00/month for admin, total \$25.00" "$(cpstrings velvetrope "$J" | grep -o 'velvetrope-mrr" style="[^"]*">[^<]*' | sed 's/.*>//')" "\$25.00"

section "Guest checkout (create the Craft user from the Checkout session)"
NEWMAIL=velvetrope-guest-$(date +%s)@velvetrope.test
ok "guest checkout off → guests are sent to log in" "$(redirect actions/velvetrope/checkout/gold)" "$U/login"
settings "['guestCheckout' => true]"
rm -f "$MOCK/calls.log"
ok "guest checkout on → Stripe Checkout, no login" "$(redirect actions/velvetrope/checkout/gold | grep -c '^https://checkout.stripe.test/')" "1"
ok "guest session is marked for Velvet Rope with the plan and site" "$(calls | grep 'checkout/sessions' | grep -o 'metadata\[velvetrope\]=guest\|metadata\[plan\]=gold\|metadata\[site\]=default\|mode=subscription' | sort | tr '\n' ' ')" "metadata[plan]=gold metadata[site]=default metadata[velvetrope]=guest mode=subscription "
ok "…and no Stripe customer was created up front" "$(calls | grep -c 'POST /v1/customers')" "0"
gsession() { python3 -I -c 'import json,sys; print(json.dumps({"id":sys.argv[1],"object":"checkout.session","mode":"subscription","status":"complete","payment_status":sys.argv[5],"customer":sys.argv[2],"subscription":sys.argv[3],"customer_details":{"email":sys.argv[4],"name":"Guest Person"},"metadata":{"velvetrope":"guest","plan":"gold","site":sys.argv[6]}}))' "$@"; }
subobj active '' price_harness_gold cus_guest_1 sub_guest_1 1500 gold > "$MOCK/fixtures/sub_guest_1.json"
ok "subscription.created before the customer is known → ignored by Cashier" "$(send customer.subscription.created active '' price_harness_gold cus_guest_1 sub_guest_1)" "200"
ok "checkout completed, payment still pending → nothing yet" "$(sendevent checkout.session.completed "$(gsession cs_guest_1 cus_guest_1 sub_guest_1 "$NEWMAIL" unpaid default)")/$(tk "echo $UE::find()->email('$NEWMAIL')->status(null)->count();")" "200/0"
ok "checkout.session.async_payment_succeeded → handled" "$(sendevent checkout.session.async_payment_succeeded "$(gsession cs_guest_1 cus_guest_1 sub_guest_1 "$NEWMAIL" paid default)")" "200"
GC=$(tk "echo json_encode($VR\\Models\\GuestCheckout::where('stripeSessionId','cs_guest_1')->first(['outcome','userId','reason','email']));")
ok "the guest checkout is recorded as a created account" "$(echo "$GC" | grep -o '"outcome":"[a-z]*"')" '"outcome":"created"'
ok "a new user is created, pending (nobody can log in yet)" "$(tk "\$u=$UE::find()->email('$NEWMAIL')->status(null)->one(); echo \$u ? (\$u->pending ? 'pending' : 'active') : 'none';")" "pending"
ok "…linked to the Stripe customer, subscription fetched from Stripe, in Gold" "$(tk "\$u=$UE::find()->email('$NEWMAIL')->status(null)->one(); \$m=$VR\\Models\\Member::where('userId',\$u->id)->first(); echo \$m->stripe_id.' '.\$m->subscriptions()->value('stripe_id');")/$(groups "$NEWMAIL" email)" "cus_guest_1 sub_guest_1/velvetropeGold"
queue
ok "…and sent Craft's activation email" "$(subjects "$NEWMAIL" | grep -ci 'activ')" "1"
ok "a retried webhook creates nothing twice" "$(sendevent checkout.session.completed "$(gsession cs_guest_1 cus_guest_1 sub_guest_1 "$NEWMAIL" paid default)")/$(tk "echo $UE::find()->email('$NEWMAIL')->status(null)->count().' '.$VR\\Models\\GuestCheckout::count();")" "200/1 1"
ok "the pending guest can't log in with any password" "$("$HERE/login.sh" "$NEWMAIL" velvetrope-local-only 2>&1 | grep -c 'login failed')" "1"
subobj active '' price_harness_gold cus_guest_m2 sub_guest_m2 1500 gold > "$MOCK/fixtures/sub_guest_m2.json"
ok "email of an existing account → membership attached to it (no new user)" "$(sendevent checkout.session.completed "$(gsession cs_guest_2 cus_guest_m2 sub_guest_m2 velvetrope-member2@velvetrope.test paid default)")/$(tk "echo $VR\\Models\\GuestCheckout::where('stripeSessionId','cs_guest_2')->value('outcome');")/$(groups velvetrope-member2 username)" "200/attached/velvetropeGold"
ok "existing account that is already another Stripe customer → held for review, nothing attached" "$(sendevent checkout.session.completed "$(gsession cs_guest_3 cus_guest_other sub_guest_other velvetrope-member1@velvetrope.test paid default)")/$(tk "echo $VR\\Models\\GuestCheckout::where('stripeSessionId','cs_guest_3')->value('outcome').' '.$VR\\Models\\Member::where('userId',$M1)->value('stripe_id');")" "200/review cus_harness_1"
ok "review shows on the index and the member's screen" "$(cpstrings velvetrope "$J" | grep -c 'need.* review')/$(cpstrings "users/$M1/membership" "$J" | grep -c 'cus_guest_other')" "1/1"
ok "a checkout session that isn't Velvet Rope's is ignored" "$(sendevent checkout.session.completed '{"id":"cs_other","object":"checkout.session","mode":"subscription","payment_status":"paid","customer":"cus_x","subscription":"sub_x","customer_details":{"email":"x@velvetrope.test"},"metadata":{}}')/$(tk "echo $VR\\Models\\GuestCheckout::where('stripeSessionId','cs_other')->count();")" "200/0"
ok "a forged checkout webhook (bad signature) creates nobody" "$(body=$(event checkout.session.completed "$(gsession cs_forged cus_f sub_f velvetrope-forged@velvetrope.test paid default)"); post "$body" -H "Stripe-Signature: $(echo -n "$body" | sign whsec_wrong)")/$(tk "echo $UE::find()->email('velvetrope-forged@velvetrope.test')->status(null)->count();")" "403/0"
settings "['guestCheckout' => false]"

section "Pro: concurrent plans, promotion codes"
edition pro
rm -f "$MOCK/calls.log"
ok "Pro: a member can check out a second plan" "$(redirect actions/velvetrope/checkout/silver "$J" | grep -c '^https://checkout.stripe.test/')" "1"
ok "Pro: no promo box unless switched on" "$(calls | grep -c 'allow_promotion_codes')" "0"
settings "['allowPromotionCodes' => true]"
rm -f "$MOCK/calls.log"
redirect actions/velvetrope/checkout/silver "$J" >/dev/null
ok "Pro: promo box on Checkout" "$(calls | grep 'checkout/sessions' | grep -c 'allow_promotion_codes=true')" "1"
rm -f "$MOCK/calls.log"
redirect 'actions/velvetrope/checkout/silver?promo=SPRING' "$J" >/dev/null
ok "Pro: ?promo=SPRING looked up and applied (and no second promo box)" "$(calls | grep -c 'GET /v1/promotion_codes?code=SPRING')/$(calls | grep 'checkout/sessions' | grep -c 'discounts\[0\]\[promotion_code\]=promo_harness_spring')/$(calls | grep 'checkout/sessions' | grep -c allow_promotion_codes)" "1/1/0"
rm -f "$MOCK/calls.log"
NOPE=$(redirect 'actions/velvetrope/checkout/silver?promo=NOPE' "$J")
ok "Pro: an unknown promo code falls back to the promo box" "$(calls | grep 'checkout/sessions' | grep -c 'allow_promotion_codes=true')" "1" || true
[ "$(calls | grep 'checkout/sessions' | grep -c 'allow_promotion_codes=true')" = 1 ] || { echo "   redirect: $NOPE"; calls | cut -c1-200 | sed 's/^/   /'; }
rm -f "$MOCK/calls.log"
redirect 'actions/velvetrope/checkout/silver?promo=%3Cscript%3E' "$J" >/dev/null
ok "Pro: a malformed promo code is never sent to Stripe" "$(calls | grep -c 'GET /v1/promotion_codes')" "0"
ok "promo checkout link (both engines)" "$(mark vr/twig promo)|$(mark vr/blade promo)" "$U/actions/velvetrope/checkout/gold?promo=SPRING|$U/actions/velvetrope/checkout/gold?promo=SPRING"

section "Pro: dunning emails"
reset1; mailclear
send customer.subscription.created active >/dev/null
send customer.subscription.updated past_due >/dev/null; queue
ok "payment failed → one email to the member" "$(subjects velvetrope-member1@velvetrope.test)" "Payment failed for your Gold membership"
ok "…with the billing link and the grace end date" "$(mailtext velvetrope-member1@velvetrope.test 'Payment failed' | grep -c "$U/actions/velvetrope/billing")/$(mailtext velvetrope-member1@velvetrope.test 'Payment failed' | grep -c "$(date -v+3d +%Y-%m-%d)")" "1/1"
send customer.subscription.updated past_due >/dev/null; queue
ok "a repeated past_due webhook doesn't email again" "$(subjects velvetrope-member1@velvetrope.test | tr '|' '\n' | grep -c 'Payment failed')" "1"
tk "$VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->update(['past_due_since' => now()->subDays(2)->subHours(2)]); echo 1;" >/dev/null
ddev artisan craft:velvetrope:sync >/dev/null 2>&1; queue
ok "a day before grace ends → reminder" "$(subjects velvetrope-member1@velvetrope.test | tr '|' '\n' | grep -c 'access to Gold ends')" "1"
ddev artisan craft:velvetrope:sync >/dev/null 2>&1; queue
ok "…sent once" "$(subjects velvetrope-member1@velvetrope.test | tr '|' '\n' | grep -c 'access to Gold ends')" "1"
tk "$VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->update(['past_due_since' => now()->subDays(4)]); echo 1;" >/dev/null
ddev artisan craft:velvetrope:sync >/dev/null 2>&1; queue
ok "grace over → access-ended email, Gold removed" "$(subjects velvetrope-member1@velvetrope.test | tr '|' '\n' | grep -c 'Gold membership has ended')/$(g1)" "1/velvetropeStaff"
send customer.subscription.updated active >/dev/null
ok "paying again resets dunning (stage 0)" "$(tk "echo $VR\\Models\\Subscription::where('stripe_id','sub_harness_1')->value('dunning_stage');")" "0"
ok "system messages are registered (editable per language)" "$(tk "echo collect(app(CraftCms\\Cms\\SystemMessage\\SystemMessages::class)->messages()->keys())->filter(fn(\$k)=>str_starts_with(\$k,'velvetrope_'))->sort()->join(',');")" "velvetrope_access_ended,velvetrope_gift_purchased,velvetrope_grace_ending,velvetrope_payment_failed"
settings "['dunningEmails' => false]"
mailclear; send customer.subscription.updated past_due >/dev/null; queue
ok "dunning switched off → no email" "$(subjects velvetrope-member1@velvetrope.test)" ""
settings "['dunningEmails' => true]"
reset1

section "Pro: gift memberships"
mailclear
ok "gift options and links (both engines)" "$(mark vr/twig gifts)|$(mark vr/blade gifts)|$(mark vr/twig gifturl)" "gold-year:12|gold-year:12|$U/actions/velvetrope/gift/gold-year"
rm -f "$MOCK/calls.log"
ok "buy a gift (logged out) → Stripe Checkout" "$(redirect actions/velvetrope/gift/gold-year | grep -c '^https://checkout.stripe.test/')" "1"
ok "gift session: one-time payment of the gift price, marked as a gift" "$(calls | grep 'checkout/sessions' | grep -o 'mode=payment\|line_items\[0\]\[price\]=price_harness_gift_gold\|metadata\[velvetrope\]=gift\|metadata\[months\]=12' | sort | tr '\n' ' ')" "line_items[0][price]=price_harness_gift_gold metadata[months]=12 metadata[velvetrope]=gift mode=payment "
ok "unknown gift option → 404" "$(code actions/velvetrope/gift/platinum)" "404"
gift() { python3 -I -c 'import json,sys; print(json.dumps({"id":sys.argv[1],"object":"checkout.session","mode":"payment","status":"complete","payment_status":sys.argv[2],"customer_details":{"email":"velvetrope-buyer@velvetrope.test"},"metadata":{"velvetrope":"gift","option":"gold-year","plan":"gold","months":"12","site":sys.argv[3]}}))' "$@"; }
ok "unpaid gift session → no code" "$(sendevent checkout.session.completed "$(gift cs_gift_1 unpaid default)")/$(tk "echo $VR\\Models\\Gift::count();")" "200/0"
ok "paid → one gift, code emailed to the buyer" "$(sendevent checkout.session.async_payment_succeeded "$(gift cs_gift_1 paid default)")/$(tk "echo $VR\\Models\\Gift::count();")/$(subjects velvetrope-buyer@velvetrope.test)" "200/1/Your gift: 12 months of Gold"
CODE=$(mailtext velvetrope-buyer@velvetrope.test 'Your gift' | grep -oE '[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}' | head -1)
ok "the email carries a code and the redeem page link" "$( [ -n "$CODE" ] && echo yes)/$(mailtext velvetrope-buyer@velvetrope.test 'Your gift' | grep -c "$U/vr/twig")" "yes/1"
ok "only a hash of the code is stored" "$(tk "echo $VR\\Models\\Gift::where('codeHash', hash('sha256', str_replace('-', '', '$CODE')))->count().' '.$VR\\Models\\Gift::where('codeHash', 'like', '%$CODE%')->count();")" "1 0"
ok "a retried webhook issues no second gift" "$(sendevent checkout.session.completed "$(gift cs_gift_1 paid default)")/$(tk "echo $VR\\Models\\Gift::count();")" "200/1"
R=$V
redeem() { local jar=$1; shift; curl -sk -b "$jar" "$U/vr/twig" -c "$jar" -o /dev/null; curl -sk -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' -H "X-XSRF-TOKEN: $(xsrf "$jar")" -H "Referer: $U/vr/twig" -X POST "$U/actions/velvetrope/redeem" "$@"; }
G=$(mktemp)
ok "redeem while logged out → login" "$(redeem "$G" --data "code=$CODE")" "$U/login"
ok "redeem without CSRF → refused" "$(curl -sk -b "$R" -o /dev/null -w '%{http_code}' -X POST "$U/actions/velvetrope/redeem" --data "code=$CODE")" "419"
ok "a failed redeem never follows a foreign Referer" "$(redeem "$R" -H 'Referer: https://evil.example/' --data 'code=AAAA-BBBB-CCCC-DDDD' | grep -c '^https://evil')" "0"
ok "a wrong code → back, with an error (both engines)" "$(redeem "$R" --data 'code=AAAA-BBBB-CCCC-DDDD' >/dev/null; mark vr/twig notice "$R" | cut -c1-4)" "ERR:"
VIEWER=$(uid velvetrope-viewer)
ok "redeem (lower case, no dashes) → success page" "$(redeem "$R" --data "code=$(echo "$CODE" | tr -d - | tr 'A-Z' 'a-z')")" "$U/vr/welcome"
ok "the redeemer has Gold through a gift grant ending in 12 months (Twig = Blade)" "$(mark vr/twig gold "$R")/$(mark vr/twig grant "$R")/$(mark vr/blade grant "$R")/$(groups velvetrope-viewer username)/$(tk "echo $VR\\Models\\Grant::where('userId',$VIEWER)->value('endsAt')?->toDateString();")" "GOLD-YES/gift/gift/velvetropeGold/$(date -v+12m +%Y-%m-%d)"
ok "a used code can't be redeemed again" "$(redeem "$J" --data "code=$CODE" >/dev/null; mark vr/blade notice "$J" | cut -c1-4)" "ERR:"
ok "a gift isn't MRR but counts as a member" "$(cpstrings velvetrope "$J" | grep -o 'velvetrope-gifted" style="[^"]*">[^<]*' | sed 's/.*>//')" "1"

section "Pro: complimentary access from the CP"
ok "grant: unknown plan refused" "$(cppost "$J" "velvetrope/members/$M1/grants" --data 'plan=platinum&months=1')" "422"
ok "grant gold for 2 months → member1 in Gold" "$(cppost "$J" "velvetrope/members/$M1/grants" --data 'plan=gold&months=2&note=press')/$(g1)" "302/velvetropeGold,velvetropeStaff"
ok "the screen lists it with a revoke button" "$(cpstrings "users/$M1/membership" "$J" | grep -c 'Revoke')" "1"
GID=$(tk "echo $VR\\Models\\Grant::where('userId',$M1)->value('id');")
ok "view-only can't grant (403)" "$(cppost "$V" "velvetrope/members/$M1/grants" --data 'plan=gold&months=1')" "403"
ok "revoking a grant of another user's id → 404" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' -H "X-XSRF-TOKEN: $(xsrf "$J")" -X DELETE "$U/admin/velvetrope/members/$VIEWER/grants/$GID")" "404"
ok "revoke → Gold removed" "$(curl -sk -b "$J" -o /dev/null -w '%{http_code}' -H "X-XSRF-TOKEN: $(xsrf "$J")" -X DELETE "$U/admin/velvetrope/members/$M1/grants/$GID")/$(g1)" "302/velvetropeStaff"

section "Pro: plan gates (pages by URI and by section, Twig and Blade)"
setadmin canceled null
for p in gate-twig gate-blade; do
  ok "$p: guest → login" "$(redirect vr/$p)" "$U/login"
  ok "$p: non-member → gold checkout" "$(redirect vr/$p "$J")" "$U/actions/velvetrope/checkout/gold"
done
ok "section gate: guest → login" "$(redirect vr-premium/velvetrope-premium-1)" "$U/login"
ok "section gate (any plan): non-member → join… (no join path, several plans → 403)" "$(code vr-premium/velvetrope-premium-1 "$J")" "403"
settings "['joinPath' => 'vr/twig']"
ok "section gate: non-member → join path" "$(redirect vr-premium/velvetrope-premium-1 "$J")" "$U/vr/twig"
ok "the gated content is never sent to a non-member" "$(curl -sk -b "$J" "$U/vr-premium/velvetrope-premium-1" | grep -c 'PREMIUM-SECRET')" "0"
setadmin active null silver
nostore() { curl -sk -b "$J" -D - -o /dev/null "$U/$1" | grep -i '^cache-control:' | grep -i private | grep -ci no-store; }
ok "silver member: section gate (any plan) lets them in, marked private/no-store" "$(curl -sk -b "$J" "$U/vr-premium/velvetrope-premium-1" | grep -c PREMIUM-SECRET)/$(nostore vr-premium/velvetrope-premium-1)" "1/1"
ok "silver member: gold-only URI gate still keeps them out" "$(redirect vr/gate-twig "$J")" "$U/vr/twig"
setadmin active null gold
ok "gold member: URI-gated Twig and Blade pages" "$(curl -sk -b "$J" "$U/vr/gate-twig")|$(curl -sk -b "$J" "$U/vr/gate-blade")" "TWIG-GATED-BY-URI|BLADE-GATED-BY-URI"
ok "URI-gated pages are marked no-store too; ungated ones aren't" "$(nostore vr/gate-twig)/$(nostore vr/welcome)" "1/0"
settings "['gates' => [['type' => 'uri', 'match' => 'vr/gate-*', 'plans' => 'platinum']]]"
ok "a gate naming an unknown plan keeps everyone out (fails closed)" "$(redirect vr/gate-twig "$J")" "$U/vr/twig"
edition lite
setadmin canceled null
ok "Lite: gates don't apply (Pro feature)" "$(curl -sk -b "$J" "$U/vr/gate-twig")" "TWIG-GATED-BY-URI"
ok "Lite: gift links 404" "$(code actions/velvetrope/gift/gold-year)" "404"
req fixtures.php >/dev/null

section "GraphQL"
ok "plans: handle, name, trial, checkout URL (no price IDs in the schema)" "$(gq plans '{ velvetRopePlans { handle name trialDays checkoutUrl } }' 'j(*[p["handle"]+":"+p["name"]+":"+str(p["trialDays"])+":"+p["checkoutUrl"] for p in b["velvetRopePlans"]])')" "gold:Gold:0:$U/actions/velvetrope/checkout/gold,silver:Silver:14:$U/actions/velvetrope/checkout/silver,broken:Broken:0:$U/actions/velvetrope/checkout/broken"
ok "plans: one by handle" "$(gq plans '{ velvetRopePlans(handle: "silver") { handle } }' 'j(*[p["handle"] for p in b["velvetRopePlans"]])')" "silver"
ok "the type has no price ID, member or status fields" "$(gq plans '{ __type(name: "VelvetRopePlan") { fields { name } } }' 'j(*sorted(f["name"] for f in b["__type"]["fields"]))')" "checkoutUrl,giftOptions,handle,name,trialDays"
ok "Lite: no gift options over GraphQL" "$(gq plans '{ velvetRopePlans(handle: "gold") { giftOptions { handle } } }' 'len(b["velvetRopePlans"][0]["giftOptions"])')" "0"
edition pro
ok "Pro: gift options over GraphQL" "$(gq plans '{ velvetRopePlans(handle: "gold") { giftOptions { handle months url } } }' 'j(*[g["handle"]+":"+str(g["months"])+":"+g["url"] for g in b["velvetRopePlans"][0]["giftOptions"]])')" "gold-year:12:$U/actions/velvetrope/gift/gold-year"
edition lite
ok "a schema without the permission has no velvetRopePlans query" "$(gq none '{ velvetRopePlans { handle } }' '0' | grep -c 'Cannot query field "velvetRopePlans"')" "1"
ok "a site the schema can't read is refused" "$(gq plans '{ velvetRopePlans(site: "nope") { handle } }' '0')" "ERR:This schema can’t read the “nope” site."

# ---- Multisite (only when the harness has Velvet Rope's own `velvetropefr` site) ----
if [ "$(tk "echo $F\\Sites::getSiteByHandle('velvetropefr') ? 'yes' : 'no';")" = yes ]; then
  section "Multisite (velvetropefr)"
  req multisite-fixtures.php >/dev/null
  FRU=$U/velvetropefr
  ok "fr: template links are on the French site (Twig = Blade)" "$(mark velvetropefr/vr/twig checkout)|$(mark velvetropefr/vr/blade checkout)" "$FRU/actions/velvetrope/checkout/gold|$FRU/actions/velvetrope/checkout/gold"
  rm -f "$MOCK/calls.log"
  ok "fr: checkout from the French site → Stripe" "$(redirect velvetropefr/actions/velvetrope/checkout/silver "$J" | grep -c '^https://checkout.stripe.test/')" "1"
  ok "fr: Stripe returns to the French site, in French" "$(calls | grep 'checkout/sessions' | grep -o "success_url=$FRU/vr/welcome\|cancel_url=$FRU/vr/twig\|locale=fr" | sort -u | tr '\n' ' ')" "cancel_url=$FRU/vr/twig locale=fr success_url=$FRU/vr/welcome "
  settings "['guestCheckout' => true]"
  rm -f "$MOCK/calls.log"
  redirect velvetropefr/actions/velvetrope/checkout/gold >/dev/null
  ok "fr: a guest session records the French site" "$(calls | grep 'checkout/sessions' | grep -c 'metadata\[site\]=velvetropefr')" "1"
  FRMAIL=velvetrope-fr-$(date +%s)@velvetrope.test
  subobj active '' price_harness_gold cus_guest_fr sub_guest_fr 1500 gold > "$MOCK/fixtures/sub_guest_fr.json"
  sendevent checkout.session.completed "$(gsession cs_guest_fr cus_guest_fr sub_guest_fr "$FRMAIL" paid velvetropefr)" >/dev/null
  ok "fr: the guest's new account is affiliated with the French site" "$(tk "echo $UE::find()->email('$FRMAIL')->status(null)->one()?->getAffiliatedSite()?->handle ?? 'none';")" "velvetropefr"
  ok "fr: an unknown site in metadata creates the user with no affiliation (never a made-up site)" "$(subobj active '' price_harness_gold cus_guest_x sub_guest_x 1500 gold > "$MOCK/fixtures/sub_guest_x.json"; sendevent checkout.session.completed "$(gsession cs_guest_x cus_guest_x sub_guest_x velvetrope-x-$(date +%s)@velvetrope.test paid nope)" >/dev/null; tk "echo $UE::find()->email('velvetrope-x-*')->status(null)->one()?->affiliatedSiteId ?? 'null';")" "null"
  settings "['guestCheckout' => false]"
  edition pro; mailclear
  sendevent checkout.session.completed "$(gift cs_gift_fr paid velvetropefr)" >/dev/null
  ok "fr: the gift email links the French redeem page" "$(mailtext velvetrope-buyer@velvetrope.test 'gift' | grep -c "$FRU/vr/twig")" "1"
  ok "fr gql: site argument gives that site's checkout links" "$(gq plans '{ velvetRopePlans(site: "velvetropefr", handle: "gold") { checkoutUrl } }' 'b["velvetRopePlans"][0]["checkoutUrl"]')" "$FRU/actions/velvetrope/checkout/gold"
  ok "fr: the gate applies on the French site too (to that site's login)" "$(redirect velvetropefr/vr/gate-twig)" "$FRU/login"
  edition lite
else
  echo; echo "(multisite section skipped: no velvetropefr site — see README)"
fi

# ---- Cleanup ----
edition lite
ok "cleanup" "$(req cleanup.php)" "cleaned"
rm -f "$H/resources/views/vr/"{_premium.twig,gate-twig.twig,gate-blade.blade.php,welcome.twig}
echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
