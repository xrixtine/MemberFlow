<x-layouts.app title="Register member">
    <x-page-header eyebrow="Members" title="Register a member"
                   subtitle="A membership number is generated automatically when you save.">
        <a href="{{ route('admin.members.index') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back to directory</a>
    </x-page-header>

    @if ($categories->isEmpty() || $levels->isEmpty())
        <div class="alert-warning mb-6" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>At least one active category and one active level are needed before members can be registered.
                <a class="font-bold underline" href="{{ route('admin.categories.index') }}">Manage categories</a> ·
                <a class="font-bold underline" href="{{ route('admin.levels.index') }}">Manage levels</a></p>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.members.store') }}" novalidate>
        @csrf
        @include('members._form')

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <a href="{{ route('admin.members.index') }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" data-loading="Registering…">Register member</button>
        </div>
    </form>
</x-layouts.app>
