<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Database\Eloquent\Model;
use JustinHoltWeb\VelvetRope\Jobs\CancelStripeSubscriptions;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;

/**
 * Note the user's live subscriptions while they still exist (a hard delete
 * cascades the member rows away), then queue the cancellation only once the
 * deletion has committed — a vetoed or failed delete cancels nothing.
 */
class CancelOnUserDelete
{
    /** @var array<int, list<string>> */
    private array $pending = [];

    public function deleting(ElementDeleting $event): void
    {
        if (! $event->element instanceof User || $event->element->id === null) {
            return;
        }

        $member = Member::where('userId', $event->element->id)->first();

        $ids = $member?->subscriptions()->get()
            ->filter(fn (Model $s) => $s instanceof Subscription && ! in_array($s->stripe_status, ['canceled', 'incomplete_expired'], true) && ($s->ends_at === null || $s->ends_at->isFuture()))
            ->pluck('stripe_id')->values()->all() ?? [];

        if ($ids !== []) {
            $this->pending[(int) $event->element->id] = $ids;
        }
    }

    public function deleted(ElementDeleted $event): void
    {
        $id = $event->element instanceof User ? (int) $event->element->id : null;

        if ($id !== null && isset($this->pending[$id])) {
            CancelStripeSubscriptions::dispatch($id, $this->pending[$id]);
            unset($this->pending[$id]);
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            ElementDeleting::class => 'deleting',
            ElementDeleted::class => 'deleted',
        ];
    }
}
