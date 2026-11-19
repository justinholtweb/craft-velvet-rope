<?php

// English source strings for Velvet Rope. Most strings are their own key; the system messages
// (emails) use keys, and are edited per language in Utilities → System Messages.
return [
    'Velvet Rope' => 'Velvet Rope',

    'velvetrope_payment_failed_heading' => 'When a member’s payment fails (Velvet Rope Pro):',
    'velvetrope_payment_failed_subject' => 'Payment failed for your {{ planName }} membership',
    'velvetrope_payment_failed_body' => "Hi {{ user.friendlyName }},\n\nWe couldn’t take the latest payment for your {{ planName }} membership on {{ systemName }}.{% if graceEndsAt %} You’ll keep access until {{ graceEndsAt }} while it’s sorted out.{% endif %}\n\nYou can update your card here:\n\n<{{ billingUrl }}>",

    'velvetrope_grace_ending_heading' => 'A day before a member’s grace period ends (Velvet Rope Pro):',
    'velvetrope_grace_ending_subject' => 'Tomorrow your access to {{ planName }} ends',
    'velvetrope_grace_ending_body' => "Hi {{ user.friendlyName }},\n\nYour {{ planName }} membership on {{ systemName }} still hasn’t been paid, and your access ends on {{ graceEndsAt }}.\n\nUpdate your card to keep it:\n\n<{{ billingUrl }}>",

    'velvetrope_access_ended_heading' => 'When a member loses access after a failed payment (Velvet Rope Pro):',
    'velvetrope_access_ended_subject' => 'Your {{ planName }} membership has ended',
    'velvetrope_access_ended_body' => "Hi {{ user.friendlyName }},\n\nWe weren’t able to take payment, so your {{ planName }} membership on {{ systemName }} has ended.\n\nYou can rejoin or update your card at any time:\n\n<{{ billingUrl }}>",

    'velvetrope_gift_purchased_heading' => 'When someone buys a gift membership (Velvet Rope Pro):',
    'velvetrope_gift_purchased_subject' => 'Your gift: {{ months }} months of {{ planName }}',
    'velvetrope_gift_purchased_body' => "Thank you for buying {{ months }} months of {{ planName }} on {{ systemName }} as a gift.\n\nHere is the gift code. Pass it on to the lucky person; they redeem it once, while logged in, here:\n\n**{{ code }}**\n\n<{{ redeemUrl }}>\n\nKeep this email safe: anyone with the code can redeem it, and we can’t show it again.",
];
