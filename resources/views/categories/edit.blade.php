<x-layouts.app :title="'Edit '.$category->name">
    <x-page-header eyebrow="Categories" :title="'Edit '.$category->name"
                   :subtitle="$category->members_count.' '.str('member')->plural($category->members_count).' in this category'">
        <a href="{{ route('admin.categories.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.categories.update', $category) }}" novalidate>
        @csrf
        @method('PUT')
        @include('categories._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Saving…">Save changes</button>
            <a href="{{ route('admin.categories.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
