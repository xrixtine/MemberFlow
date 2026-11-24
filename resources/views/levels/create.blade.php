<x-layouts.app title="New level">
    <x-page-header eyebrow="Levels" title="New level">
        <a href="{{ route('admin.levels.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.levels.store') }}" novalidate>
        @csrf
        @include('levels._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Saving…">Create level</button>
            <a href="{{ route('admin.levels.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
