<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Models\Member;

/**
 * Re-applies every member's groups from their local subscription rows. Runs
 * hourly so grace periods end even when Stripe sends nothing new.
 */
class SyncCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:velvetrope:sync';

    protected $description = 'Re-syncs members’ user groups from their subscriptions';

    public function handle(GroupSync $groupSync): int
    {
        $changed = 0;

        Member::query()->chunkById(200, function ($members) use ($groupSync, &$changed): void {
            foreach ($members as $member) {
                $r = $groupSync->sync($member);
                $changed += (int) ($r['before'] !== $r['after']);
            }
        });

        $this->components->info("Synced members; {$changed} changed.");

        return self::SUCCESS;
    }
}
