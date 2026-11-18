{{-- Velvet Rope harness: Blade side --}}
<p id="any">{{ $velvetRope->subscribed() ? 'MEMBER' : 'NOT-MEMBER' }}</p>
<p id="gold">@subscribed('gold')GOLD-YES @else GOLD-NO @endsubscribed</p>
<p id="status">{{ $velvetRope->subscription('gold')['status'] ?? 'none' }}</p>
<p id="checkout">{{ $velvetRope->checkoutUrl('gold') }}</p>
<p id="billing">{{ $velvetRope->billingUrl() }}</p>
<p id="plans">{{ count($velvetRope->plans()) }}</p>
