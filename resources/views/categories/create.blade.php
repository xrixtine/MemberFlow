<x-layouts.app title="New category">
    <x-page-header eyebrow="Categories" title="New category">
        <a href="{{ route('admin.categories.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.categories.store') }}" novalidate>
        @csrf
        @include('categories._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Saving…">Create category</button>
            <a href="{{ route('admin.categories.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
