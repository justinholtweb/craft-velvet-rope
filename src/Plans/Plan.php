<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Plans;

/**
 * A membership plan: a Stripe price that puts its members in a Craft user group.
 */
final readonly class Plan
{
    public function __construct(
        public string $handle,
        public string $name,
        public string $priceId,
        public ?string $userGroupUid,
        public int $trialDays = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $handle = trim((string) ($row['handle'] ?? ''));
        $priceId = trim((string) ($row['priceId'] ?? ''));

        if (! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $handle) || ! str_starts_with($priceId, 'price_')) {
            return null;
        }

        $group = trim((string) ($row['userGroup'] ?? ''));

        return new self(
            handle: $handle,
            name: trim((string) ($row['name'] ?? '')) ?: $handle,
            priceId: $priceId,
            userGroupUid: $group !== '' ? $group : null,
            trialDays: max(0, (int) ($row['trialDays'] ?? 0)),
        );
    }
}
