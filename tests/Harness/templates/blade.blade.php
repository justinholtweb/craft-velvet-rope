{{-- Velvet Rope harness: Blade side --}}
<p id="any">{{ $velvetRope->subscribed() ? 'MEMBER' : 'NOT-MEMBER' }}</p>
<p id="gold">@subscribed('gold')GOLD-YES @else GOLD-NO @endsubscribed</p>
<p id="status">{{ $velvetRope->subscription('gold')['status'] ?? 'none' }}</p>
<p id="grant">{{ $velvetRope->grant('gold')['source'] ?? 'none' }}</p>
<p id="checkout">{{ $velvetRope->checkoutUrl('gold') }}</p>
<p id="promo">{{ $velvetRope->checkoutUrl('gold', 'SPRING') }}</p>
<p id="billing">{{ $velvetRope->billingUrl() }}</p>
<p id="plans">{{ collect($velvetRope->plans())->map(fn ($p) => $p->handle.':'.$p->name)->join(',') }}</p>
<p id="gifts">{{ collect($velvetRope->giftOptions())->map(fn ($g) => $g['handle'].':'.$g['months'])->join(',') }}</p>
<p id="gifturl">{{ $velvetRope->giftUrl('gold-year') }}</p>
@php($notice = $velvetRope->notice())
<p id="notice">{{ $notice ? ($notice['ok'] ? 'OK' : 'ERR').':'.$notice['message'] : 'none' }}</p>
<form method="post" action="{{ $velvetRope->redeemUrl() }}">@csrf<input name="code"></form>
