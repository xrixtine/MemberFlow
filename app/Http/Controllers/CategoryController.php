<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCategoryRequest;
use App\Models\MembershipCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Membership categories (Student, Regular, Senior Citizen, Corporate, ...).
 * Admin-only: protected by the "role:admin" route middleware and the
 * FormRequest authorize() gate check.
 */
class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = MembershipCategory::query()
            ->withCount(['members', 'members as active_members_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        return view('categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('categories.create', ['category' => new MembershipCategory(['is_active' => true])]);
    }

    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        $category = MembershipCategory::create($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" created.");
    }

    public function edit(MembershipCategory $category): View
    {
        $category->loadCount('members');

        return view('categories.edit', compact('category'));
    }

    public function update(SaveCategoryRequest $request, MembershipCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" updated.");
    }

    /**
     * A category that any member (including archived members) belongs to cannot be
     * deleted; deactivate it instead. The foreign key enforces the same rule in MySQL.
     */
    public function destroy(MembershipCategory $category): RedirectResponse
    {
        $inUse = $category->members()->withTrashed()->count();

        if ($inUse > 0) {
            return back()->with('error',
                "\"{$category->name}\" is assigned to {$inUse} ".str('member')->plural($inUse)
                .' and cannot be deleted. Deactivate it instead to hide it from new registrations.');
        }

        $category->delete();

        return redirect()->route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" deleted.");
    }
}
