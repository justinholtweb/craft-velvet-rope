<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Jobs;

use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\SystemMessage\Mailables\SystemMessageMailable;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one of Velvet Rope's system messages (editable in Utilities → System
 * Messages, per language) to a Craft user, or to a bare email address (a gift
 * buyer who has no account), in that person's language.
 */
class SendMemberEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public string $message,
        public ?int $userId,
        public ?string $email,
        public array $variables = [],
        public ?string $siteHandle = null,
    ) {}

    public function handle(): void
    {
        $user = $this->userId !== null ? User::find()->id($this->userId)->status(null)->one() : null;
        $email = $user instanceof User ? $user->email : $this->email;

        if (! is_string($email) || $email === '') {
            return;
        }

        // The person's own language, else that of the site they bought or signed up on.
        $site = ($this->siteHandle !== null ? Sites::getSiteByHandle($this->siteHandle) : null)
            ?? ($user?->affiliatedSiteId !== null ? Sites::getSiteById($user->affiliatedSiteId) : null);
        $language = $user?->getPreferredLanguage() ?? $site?->language;

        $mailable = new SystemMessageMailable(
            key: $this->message,
            variables: [...$this->variables, 'user' => $user],
            language: $language,
            siteId: $site?->id,
        );
        $mailable->to($email, $user?->fullName);

        if ($language !== null) {
            $mailable->locale($language);
        }

        Mail::send($mailable);
    }
}
