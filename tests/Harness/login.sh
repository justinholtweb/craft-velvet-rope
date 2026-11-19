#!/bin/bash
# Usage: J=$(login.sh <username> <password>) — a site/CP session cookie jar for any harness user.
set -euo pipefail
B=${BASE_URL:-https://plugin-testing-craft6.ddev.site}
J=$(mktemp)
# Logins are rate-limited per IP, and every agent on this machine shares the IP: wait out a 429.
for _ in $(seq 1 30); do
  curl -sk -c "$J" -o /dev/null "$B/admin/login"
  X=$(awk '$6=="XSRF-TOKEN"{print $7}' "$J" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
  code=$(curl -sk -b "$J" -c "$J" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $X" \
    -X POST "$B/actions/users/login" --data-urlencode "loginName=$1" --data-urlencode "password=$2")
  [ "$code" = 429 ] || break
  sleep 5
done
[ "$code" = 200 ] || { echo "login failed for $1: $code" >&2; exit 1; }
echo "$J"
