<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A gift membership bought through Stripe Checkout (Pro). Only the SHA-256 of
 * its code is stored; the code itself is emailed to the buyer once.
 *
 * @property int $id
 * @property string $codeHash
 * @property string $option
 * @property string $plan
 * @property int $months
 * @property string $buyerEmail
 * @property string $stripeSessionId
 * @property string|null $siteHandle
 * @property int|null $redeemedByUserId
 * @property Carbon|null $redeemedAt
 */
class Gift extends Model
{
    protected $table = 'velvetrope_gifts';

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $casts = ['redeemedAt' => 'datetime', 'months' => 'integer'];

    public static function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }

    /** Codes are case-insensitive, and dashes and spaces are ignored. */
    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
