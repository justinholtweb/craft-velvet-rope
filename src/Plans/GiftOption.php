<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Plans;

/**
 * Pro: a gift someone can buy — `months` of a plan, paid once through a one-time
 * Stripe price.
 */
final readonly class GiftOption
{
    public function __construct(
        public string $handle,
        public string $plan,
        public int $months,
        public string $priceId,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $handle = trim((string) ($row['handle'] ?? ''));
        $plan = trim((string) ($row['plan'] ?? ''));
        $priceId = trim((string) ($row['priceId'] ?? ''));
        $months = (int) ($row['months'] ?? 0);

        if (! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $handle) || ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $plan)
            || ! str_starts_with($priceId, 'price_') || $months < 1 || $months > 120) {
            return null;
        }

        return new self($handle, $plan, $months, $priceId);
    }
}
