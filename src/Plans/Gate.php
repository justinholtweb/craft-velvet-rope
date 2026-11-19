<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Plans;

use Illuminate\Support\Str;

/**
 * Pro: a plan gate — pages in a section, or at URIs matching a pattern, that only
 * members of the given plans (any plan when none are given) may see.
 */
final readonly class Gate
{
    public const string SECTION = 'section';

    public const string URI = 'uri';

    /**
     * @param  list<string>  $plans
     */
    public function __construct(
        public string $type,
        public string $match,
        public array $plans,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): ?self
    {
        $type = (string) ($row['type'] ?? '');
        $match = trim(trim((string) ($row['match'] ?? '')), '/');

        if (! in_array($type, [self::SECTION, self::URI], true) || $match === '') {
            return null;
        }

        if ($type === self::SECTION && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $match)) {
            return null;
        }

        $plans = array_values(array_filter(array_map(trim(...), explode(',', (string) ($row['plans'] ?? ''))), fn (string $p) => $p !== ''));

        return new self($type, $match, $plans);
    }

    /**
     * Whether a site-relative path is covered (`*` matches anything, including slashes).
     * Case-insensitive, like Craft's own element URI routing: `/Members/x` is `members/x`.
     */
    public function matchesUri(string $path): bool
    {
        return $this->type === self::URI && Str::is(mb_strtolower($this->match), mb_strtolower(trim($path, '/')));
    }

    /** Whether this is a section gate for the given section handle. */
    public function matchesSection(?string $handle): bool
    {
        return $this->type === self::SECTION && $handle !== null && $this->match === $handle;
    }
}
