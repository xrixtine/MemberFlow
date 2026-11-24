<x-layouts.app title="Categories">
    <x-page-header eyebrow="Membership" title="Membership categories"
                   subtitle="Each member belongs to exactly one category. Inactive categories are hidden from new registrations.">
        <a href="{{ route('admin.categories.create') }}" class="btn-primary"><x-icon name="tag" class="size-4"/> New category</a>
    </x-page-header>

    @if ($categories->isEmpty())
        <div class="card">
            <x-empty-state icon="tag" title="No categories yet" message="Create a category such as Student or Regular.">
                <a href="{{ route('admin.categories.create') }}" class="btn-primary">New category</a>
            </x-empty-state>
        </div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col">Category</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="text-right">Members</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($categories as $category)
                    <tr>
                        <td class="font-bold">{{ $category->name }}</td>
                        <td class="max-w-md text-muted">{{ $category->description ?: '—' }}</td>
                        <td class="text-right">
                            <a class="hover:underline" href="{{ route('admin.members.index', ['category' => $category->id]) }}">
                                {{ $category->members_count }}
                            </a>
                            <span class="block text-xs text-muted">{{ $category->active_members_count }} active</span>
                        </td>
                        <td><x-badge :color="$category->is_active ? 'green' : 'gray'">{{ $category->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td>
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.categories.edit', $category) }}" class="btn-secondary btn-sm">Edit</a>
                                @if ($category->members_count === 0)
                                    <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                                          data-confirm="Delete the category &quot;{{ $category->name }}&quot;?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger btn-sm">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-muted">Categories in use cannot be deleted — deactivate them instead. This keeps every member's history intact.</p>
    @endif
</x-layouts.app>
