<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers\Cp;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Http\Requests\TableRequest;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Shared\Enums\Color;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Table as UiTable;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use Illuminate\Database\Eloquent\Builder;
use JustinHoltWeb\VelvetRope\Cp\MemberReport;
use JustinHoltWeb\VelvetRope\Cp\Money;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Models\Member;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * CP → Members: everyone with a subscription or a grant, their plans and status,
 * and site-wide members/MRR totals. Needs “View members”.
 */
class MembersController
{
    public const array STATUSES = ['active', 'trialing', 'past_due', 'canceled', 'gift', 'comp'];

    public function index(TableRequest $request, MemberReport $report): CpScreenResponse
    {
        $search = trim($request->search() ?? '');
        $status = in_array($request->input('status'), self::STATUSES, true) ? (string) $request->input('status') : null;

        $paginator = Member::query()
            ->join(Table::USERS.' as u', 'u.id', '=', 'velvetrope_members.userId')
            ->select('velvetrope_members.*', 'u.username', 'u.email', 'u.fullName')
            ->where(fn (Builder $q) => $q->whereHas('subscriptions')->orWhereIn('velvetrope_members.userId', Grant::query()->select('userId')))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('u.email', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('u.username', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('u.fullName', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($status !== null, fn (Builder $q) => in_array($status, ['gift', 'comp'], true)
                ? $q->whereIn('velvetrope_members.userId', Grant::query()->current()->where('source', $status)->select('userId'))
                : $q->whereHas('subscriptions', fn (Builder $s) => $s->where('stripe_status', $status)))
            ->orderByDesc('velvetrope_members.id')
            ->paginate($request->limit(), pageName: Cms::config()->getPageTriggerParam(), page: $request->page())
            ->appends($request->except(Cms::config()->getPageTriggerParam()));

        $rows = $paginator->getCollection()->map(function (Member $member) use ($report): array {
            $plans = $report->plansFor($member->userId);
            $mrr = [];
            foreach ($plans as $p) {
                if ($p['mrr'] !== null && $p['currency'] !== null) {
                    $mrr[$p['currency']] = ($mrr[$p['currency']] ?? 0) + $p['mrr'];
                }
            }
            $name = (string) ($member->getAttribute('fullName') ?: $member->getAttribute('username'));

            return [
                'id' => $member->userId,
                '_status' => collect($plans)->contains('granting', true) ? 'enabled' : 'disabled',
                'member' => ['label' => $name, 'url' => cp_url("users/{$member->userId}/membership")],
                'email' => (string) $member->getAttribute('email'),
                'plans' => ['html' => self::badges($plans)],
                'mrr' => $mrr === [] ? '—' : Money::formatAll($mrr),
                'since' => ['date' => $member->created_at?->toIso8601String()],
            ];
        });

        $table = UiTable::make('velvetrope-members')
            ->columns([
                ['key' => 'member', 'label' => t('Member', category: 'velvetrope')],
                ['key' => 'email', 'label' => t('Email', category: 'velvetrope')],
                ['key' => 'plans', 'label' => t('Plans', category: 'velvetrope'), 'wrap' => true],
                ['key' => 'mrr', 'label' => t('MRR', category: 'velvetrope'), 'thin' => true],
                ['key' => 'since', 'label' => t('Member since', category: 'velvetrope'), 'thin' => true],
            ])
            ->rows($rows)
            ->perPage($paginator->perPage())
            ->pagination(Arr::only($paginator->toArray(), [
                'total', 'per_page', 'current_page', 'last_page',
                'next_page_url', 'prev_page_url', 'from', 'to',
            ]))
            ->searchable(t('Search by name, username or email', category: 'velvetrope'))
            ->statusFilter([
                ['value' => 'active', 'label' => t('Active', category: 'velvetrope'), 'fill' => Color::Green->value],
                ['value' => 'trialing', 'label' => t('Trialing', category: 'velvetrope'), 'fill' => Color::Blue->value],
                ['value' => 'past_due', 'label' => t('Past due', category: 'velvetrope'), 'fill' => Color::Orange->value],
                ['value' => 'canceled', 'label' => t('Cancelled', category: 'velvetrope'), 'fill' => Color::Red->value],
                ['value' => 'gift', 'label' => t('Gift', category: 'velvetrope'), 'fill' => Color::Violet->value],
                ['value' => 'comp', 'label' => t('Complimentary', category: 'velvetrope'), 'fill' => Color::Gray->value],
            ])
            ->emptyMessage(t('No members yet.', category: 'velvetrope'));

        $totals = $report->totals();
        $nodes = [TemplateContent::make('velvetrope-totals', self::totalsHtml($totals))->trusted()];

        if ($totals['review'] > 0) {
            $nodes[] = Callout::make('velvetrope-review', t('{count, plural, =1{One guest checkout needs} other{# guest checkouts need}} review: the email matched an account that already has a different Stripe customer, so nothing was attached. See the member’s Membership screen.', ['count' => $totals['review']], category: 'velvetrope'))
                ->variant('warning');
        }

        $nodes[] = $table;

        return new CpScreenResponse()
            ->title(t('Members', category: 'velvetrope'))
            ->ui(Ui::make($nodes));
    }

    /**
     * @param  list<array{planName: string, status: string, granting: bool, endsAt: ?\DateTimeInterface}>  $plans
     */
    public static function badges(array $plans): string
    {
        $html = '';

        foreach ($plans as $p) {
            $fill = match (true) {
                $p['status'] === 'past_due' && $p['granting'] => Color::Orange,
                $p['status'] === 'gift' => Color::Violet,
                $p['status'] === 'comp' => Color::Gray,
                $p['status'] === 'trialing' => Color::Blue,
                $p['granting'] => Color::Green,
                default => Color::Red,
            };
            $html .= Html::tag('craft-badge', Html::encode($p['planName'].' · '.self::statusLabel($p['status'])), ['fill' => $fill->value]);
        }

        return Html::tag('div', $html !== '' ? $html : '—', ['class' => 'flex flex-wrap gap-xs']);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => t('Active', category: 'velvetrope'),
            'trialing' => t('Trialing', category: 'velvetrope'),
            'past_due' => t('Past due', category: 'velvetrope'),
            'canceled' => t('Cancelled', category: 'velvetrope'),
            'unpaid' => t('Unpaid', category: 'velvetrope'),
            'incomplete' => t('Incomplete', category: 'velvetrope'),
            'incomplete_expired' => t('Expired', category: 'velvetrope'),
            'paused' => t('Paused', category: 'velvetrope'),
            'gift' => t('Gift', category: 'velvetrope'),
            'comp' => t('Complimentary', category: 'velvetrope'),
            default => $status,
        };
    }

    /**
     * @param  array{members: int, trialing: int, pastDue: int, gifted: int, mrr: array<string, int>, unknownPrice: int, review: int}  $t
     */
    private static function totalsHtml(array $t): string
    {
        $stat = fn (string $id, string $label, string $value) => Html::tag('div',
            Html::tag('div', Html::encode($label), ['class' => 'light smalltext'])
            .Html::tag('div', Html::encode($value), ['id' => "velvetrope-{$id}", 'style' => 'font-size: 1.5rem; font-weight: 600;']),
            ['style' => 'min-width: 9rem;']);

        $note = $t['unknownPrice'] > 0
            ? Html::tag('p', Html::encode(t('{count, plural, =1{One paying subscription has} other{# paying subscriptions have}} no known price yet and isn’t in MRR. Run `craft:velvetrope:sync --refresh` to fetch prices from Stripe.', ['count' => $t['unknownPrice']], category: 'velvetrope')), ['class' => 'light smalltext'])
            : '';

        return Html::tag('div',
            $stat('members', t('Members', category: 'velvetrope'), (string) $t['members'])
            .$stat('mrr', t('MRR', category: 'velvetrope'), Money::formatAll($t['mrr']))
            .$stat('trialing', t('On trial', category: 'velvetrope'), (string) $t['trialing'])
            .$stat('pastdue', t('Past due (in grace)', category: 'velvetrope'), (string) $t['pastDue'])
            .$stat('gifted', t('Gift or complimentary', category: 'velvetrope'), (string) $t['gifted']),
            ['class' => 'flex flex-wrap', 'style' => 'gap: 2rem; margin-bottom: 1rem;']).$note;
    }
}
