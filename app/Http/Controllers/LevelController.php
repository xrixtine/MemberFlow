<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLevelRequest;
use App\Models\MembershipLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Membership levels (Basic, Silver, Gold, Platinum, ...) with fee, validity
 * and benefits. Changing a fee affects new transactions only: existing
 * transactions keep the amount that was snapshotted when they were created.
 */
class LevelController extends Controller
{
    public function index(): View
    {
        $levels = MembershipLevel::query()
            ->withCount(['members', 'transactions'])
            ->ordered()
            ->get();

        return view('levels.index', compact('levels'));
    }

    public function create(): View
    {
        $next = (int) MembershipLevel::max('sort_order') + 1;

        return view('levels.create', [
            'level' => new MembershipLevel(['is_active' => true, 'validity_months' => 12, 'sort_order' => min($next, 255)]),
        ]);
    }

    public function store(SaveLevelRequest $request): RedirectResponse
    {
        $level = MembershipLevel::create($request->levelData());

        return redirect()->route('admin.levels.index')
            ->with('success', "Level \"{$level->name}\" created with a fee of {$level->formattedFee()}.");
    }

    public function edit(MembershipLevel $level): View
    {
        $level->loadCount(['members', 'transactions']);

        return view('levels.edit', compact('level'));
    }

    public function update(SaveLevelRequest $request, MembershipLevel $level): RedirectResponse
    {
        $level->update($request->levelData());

        return redirect()->route('admin.levels.index')
            ->with('success', "Level \"{$level->name}\" updated. Existing transactions keep their original amounts.");
    }

    public function destroy(MembershipLevel $level): RedirectResponse
    {
        $members = $level->members()->withTrashed()->count();
        $transactions = $level->transactions()->count();

        if ($members > 0 || $transactions > 0) {
            return back()->with('error',
                "\"{$level->name}\" is used by {$members} ".str('member')->plural($members)
                ." and {$transactions} ".str('transaction')->plural($transactions)
                .' and cannot be deleted. Deactivate it instead to hide it from new registrations.');
        }

        $level->delete();

        return redirect()->route('admin.levels.index')
            ->with('success', "Level \"{$level->name}\" deleted.");
    }
}
