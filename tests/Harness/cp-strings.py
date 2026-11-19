# Prints every string in a Craft 6 CP page's Inertia props (one per line), so checks can grep them.
# Usage: curl … | python3 -I cp-strings.py [nav]   (nav: print the sidebar's hrefs instead)
import json, re, sys

html = sys.stdin.read()
m = re.search(r'<script[^>]*data-page="app"[^>]*>(.*?)</script>', html, re.S)
if not m:
    sys.exit(0)

def walk(v):
    if isinstance(v, str):
        print(v.replace('\n', ' '))
    elif isinstance(v, dict):
        for k, x in v.items():
            if k not in ('craft', 'queue', 'sharedProps'):
                walk(x)
    elif isinstance(v, list):
        for x in v:
            walk(x)

page = json.loads(m.group(1))
if sys.argv[1:] == ['nav']:
    # The sidebar: every href in craft.nav.
    def hrefs(v):
        if isinstance(v, dict):
            if isinstance(v.get('href'), str):
                print(v['href'])
            for x in v.values():
                hrefs(x)
        elif isinstance(v, list):
            for x in v:
                hrefs(x)
    hrefs(page.get('props', {}).get('craft', {}).get('nav', []))
else:
    walk(page.get('props', {}))
