<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\AccountException;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administrator user management. No create (accounts come from invitations)
 * and no delete (accounts are deactivated so history stays intact).
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAccountService $accounts) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'role' => UserRole::tryFrom((string) $request->query('role')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : null,
        ];

        $users = User::query()
            ->with(['acceptedInvitation', 'inviter'])
            ->withCount(['assignedTasks as open_tasks_count' => fn ($q) => $q->whereNull('archived_at')->where('status', '!=', TaskStatus::Completed->value)])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['role'], fn ($q, $r) => $q->where('role', $r->value))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filters' => $filters,
            'hasFilters' => collect($filters)->filter()->isNotEmpty(),
        ]);
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $user->load(['acceptedInvitation.inviter', 'inviter', 'accountEvents.actor', 'member']);

        return view('users.edit', [
            'user' => $user,
            'openTasks' => Task::active()->where('assigned_to', $user->id)->where('status', '!=', TaskStatus::Completed->value)->count(),
            'activeAdmins' => User::admins()->active()->count(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        try {
            $this->accounts->updateDetails($user, $request->validated(), $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.edit', $user)->with('success', 'User details updated.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);

        try {
            $openTasks = $this->accounts->deactivate($user, $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "{$user->name}'s account was deactivated. They can no longer sign in; their history is kept.";
        if ($openTasks > 0) {
            return back()->with('success', $message)
                ->with('warning', "{$openTasks} open ".str('task')->plural($openTasks).' are still assigned to them. Reassign them from Task management.');
        }

        return back()->with('success', $message);
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);

        try {
            $this->accounts->reactivate($user, $request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$user->name}'s account was reactivated.");
    }
}
