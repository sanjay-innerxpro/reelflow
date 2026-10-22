<?php

namespace App\Http\Controllers;

use App\Http\Requests\PostingRuleRequest;
use App\Models\PostingRule;
use App\Models\SocialAccount;
use App\Services\Scheduling\SchedulePlanner;
use App\Services\Scheduling\SlotCalculator;
use App\Support\ActivityLogger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PostingRuleController extends Controller
{
    public function index(Settings $settings, SlotCalculator $slots): View
    {
        $accounts = SocialAccount::with('postingRule')->orderBy('platform')->get();
        $preview = [];
        foreach ($accounts as $account) {
            if ($rule = $account->postingRule) {
                $preview[$account->id] = array_map(
                    fn ($s) => $s->setTimezone($rule->timezone)->format('D H:i'),
                    array_merge(...array_map(fn ($d) => $slots->slotsForDay($rule, CarbonImmutable::now($rule->timezone)->addDays($d)), [0, 1, 2])),
                );
            }
        }

        return view('rules.index', [
            'accounts' => $accounts,
            'preview' => $preview,
            'crossPost' => $settings->bool('publishing.cross_post_all'),
            'requireApproval' => $settings->bool('publishing.require_approval'),
            'paused' => $settings->bool('publishing.paused'),
        ]);
    }

    public function edit(SocialAccount $account): View
    {
        Gate::authorize('update', $account);
        $rule = $account->postingRule ?? new PostingRule(PostingRule::defaultsFor($account));

        return view('rules.edit', ['account' => $account, 'rule' => $rule]);
    }

    public function update(PostingRuleRequest $request, SocialAccount $account, ActivityLogger $activity, SchedulePlanner $planner): RedirectResponse
    {
        Gate::authorize('update', $account);
        $rule = PostingRule::updateOrCreate(
            ['social_account_id' => $account->id],
            array_merge($request->ruleAttributes(), ['platform' => $account->platform]),
        );
        $activity->log('rule.updated', $account, 'Posting rule updated ('.($rule->enabled ? 'enabled' : 'disabled').')');

        $created = $rule->enabled ? $planner->planAccount($account->fresh('postingRule')) : 0;

        return redirect()->route('rules.index')->with('status', "Posting rule saved. {$created} new post(s) scheduled.");
    }

    public function global(Request $request, Settings $settings, ActivityLogger $activity): RedirectResponse
    {
        $request->validate(['cross_post_all' => ['boolean'], 'require_approval' => ['boolean'], 'paused' => ['boolean']]);
        $settings->set([
            'publishing.cross_post_all' => $request->boolean('cross_post_all'),
            'publishing.require_approval' => $request->boolean('require_approval'),
            'publishing.paused' => $request->boolean('paused'),
        ]);
        $activity->log('settings.publishing', null, 'Publishing settings updated'.($request->boolean('paused') ? ' (all publishing paused)' : ''));

        return back()->with('status', 'Publishing settings saved.');
    }
}
