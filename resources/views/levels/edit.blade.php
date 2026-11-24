<x-layouts.app :title="'Edit '.$level->name">
    <x-page-header eyebrow="Levels" :title="'Edit '.$level->name"
                   :subtitle="$level->members_count.' '.str('member')->plural($level->members_count).' at this level'">
        <a href="{{ route('admin.levels.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.levels.update', $level) }}" novalidate>
        @csrf
        @method('PUT')
        @include('levels._form')
        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary" data-loading="Saving…">Save changes</button>
            <a href="{{ route('admin.levels.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
