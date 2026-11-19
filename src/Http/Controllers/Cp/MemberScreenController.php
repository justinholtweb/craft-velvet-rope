<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers\Cp;

use CraftCms\Cms\Http\Controllers\Users\EditUserTrait;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\Table as UiTable;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\VelvetRope\Cp\MemberReport;
use JustinHoltWeb\VelvetRope\Cp\Money;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Models\GuestCheckout;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\Settings;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * The “Membership” screen on a user's edit page (users/{id}/membership): their
 * subscriptions with Stripe status and MRR, gift/complimentary grants, guest
 * checkouts, and — with “Manage memberships” — resync and (Pro) grant access.
 */
class MemberScreenController
{
    use EditUserTrait;

    public const string SCREEN = 'membership';

    public function show(MemberReport $report, Plans $plans, Settings $settings, ?int $userId = null): CpScreenResponse
    {
        Gate::authorize(Plugin::PERMISSION_VIEW);
        $user = $this->editedUser($userId);
        $id = (int) $user->id;
        $member = Member::where('userId', $id)->first();
        $canManage = Gate::check(Plugin::PERMISSION_MANAGE);
        $nodes = [];

        foreach (GuestCheckout::where('userId', $id)->where('outcome', GuestCheckout::REVIEW)->get() as $review) {
            $nodes[] = Callout::make("velvetrope-review-{$review->id}", t('A guest paid for “{plan}” with this account’s email, but this account is already a different Stripe customer, so nothing was attached. Stripe customer {customer}, subscription {subscription}. Resolve it in Stripe (refund or cancel), then ask them to check out while logged in.', [
                'plan' => $review->plan, 'customer' => (string) $review->stripeCustomerId, 'subscription' => (string) $review->stripeSubscriptionId,
            ], category: 'velvetrope'))->variant('warning');
        }

        $nodes[] = Heading::make('velvetrope-plans-heading', t('Plans', category: 'velvetrope'))
            ->description($member?->stripe_id ? t('Stripe customer {id}', ['id' => $member->stripe_id], category: 'velvetrope') : null);
        $nodes[] = UiTable::make('velvetrope-plans')
            ->columns([
                ['key' => 'plan', 'label' => t('Plan', category: 'velvetrope')],
                ['key' => 'status', 'label' => t('Status', category: 'velvetrope')],
                ['key' => 'price', 'label' => t('Price', category: 'velvetrope')],
                ['key' => 'mrr', 'label' => t('MRR', category: 'velvetrope')],
                ['key' => 'ends', 'label' => t('Ends / trial ends', category: 'velvetrope')],
                ['key' => 'ref', 'label' => t('Reference', category: 'velvetrope')],
            ])
            ->rows(array_map(function (array $p): array {
                $s = $p['subscription'];
                $date = $s !== null ? ($s->ends_at ?? $s->trial_ends_at) : $p['endsAt'];

                return [
                    'plan' => $p['planName'],
                    'status' => ['html' => MembersController::badges([$p])],
                    'price' => $s !== null ? self::price($s) : '—',
                    'mrr' => $p['mrr'] !== null && $p['currency'] !== null ? Money::format($p['mrr'], $p['currency']) : '—',
                    'ends' => $date !== null ? ['date' => Carbon::instance($date)->toIso8601String()] : '—',
                    'ref' => $s !== null ? $s->stripe_id : ($p['grant']?->note ?: '—'),
                ];
            }, $report->plansFor($id)))
            ->emptyMessage(t('No plans.', category: 'velvetrope'));

        if ($canManage) {
            $nodes[] = TemplateContent::make('velvetrope-actions', $this->actionsHtml($id, $plans, $settings))->trusted();
        }

        return $this->asEditUserScreen($user, self::SCREEN)->ui(Ui::make($nodes));
    }

    private static function price(Subscription $s): string
    {
        if ($s->amount === null || $s->currency === null || $s->interval === null) {
            return '—';
        }

        $per = ($s->interval_count ?? 1) > 1 ? "{$s->interval_count} {$s->interval}s" : $s->interval;

        return Money::format($s->amount, $s->currency)." / {$per}";
    }

    private function actionsHtml(int $userId, Plans $plans, Settings $settings): string
    {
        $csrf = Html::hiddenInput('_token', csrf_token());
        $html = Html::tag('form',
            $csrf.Html::tag('button', Html::encode(t('Resync user groups', category: 'velvetrope')), ['type' => 'submit', 'class' => 'btn']),
            ['method' => 'post', 'action' => cp_url("velvetrope/members/{$userId}/resync"), 'style' => 'margin: 1rem 0;']);

        if (! Plugin::isPro()) {
            return $html;
        }

        $grants = Grant::where('userId', $userId)->where('endsAt', '>', now())->orderBy('endsAt')->get();
        $list = '';
        foreach ($grants as $grant) {
            $list .= Html::tag('li',
                Html::encode(t('{plan} ({source}) until {date}', ['plan' => $grant->plan, 'source' => MembersController::statusLabel($grant->source), 'date' => $grant->endsAt->toDateString()], category: 'velvetrope'))
                .' '.Html::tag('form', $csrf.Html::hiddenInput('_method', 'DELETE').Html::tag('button', Html::encode(t('Revoke', category: 'velvetrope')), ['type' => 'submit', 'class' => 'btn small']),
                    ['method' => 'post', 'action' => cp_url("velvetrope/members/{$userId}/grants/{$grant->id}"), 'style' => 'display: inline;']));
        }

        $options = '';
        foreach ($plans->all() as $plan) {
            $options .= Html::tag('option', Html::encode($plan->name), ['value' => $plan->handle]);
        }

        return $html
            .Html::tag('h2', Html::encode(t('Complimentary access', category: 'velvetrope')))
            .($list !== '' ? Html::tag('ul', $list) : '')
            .Html::tag('form',
                $csrf
                .Html::tag('label', Html::encode(t('Plan', category: 'velvetrope')).' '.Html::tag('select', $options, ['name' => 'plan', 'required' => true]))
                .' '.Html::tag('label', Html::encode(t('Months', category: 'velvetrope')).' '.Html::input('number', 'months', '1', ['min' => 1, 'max' => 120, 'required' => true, 'class' => 'text', 'style' => 'width: 5rem;']))
                .' '.Html::tag('label', Html::encode(t('Note', category: 'velvetrope')).' '.Html::input('text', 'note', '', ['maxlength' => 255, 'class' => 'text']))
                .' '.Html::tag('button', Html::encode(t('Grant access', category: 'velvetrope')), ['type' => 'submit', 'class' => 'btn submit']),
                ['method' => 'post', 'action' => cp_url("velvetrope/members/{$userId}/grants"), 'class' => 'flex flex-wrap', 'style' => 'gap: .5rem; align-items: center;']);
    }
}
