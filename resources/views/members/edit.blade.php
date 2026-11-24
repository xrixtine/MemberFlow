<x-layouts.app :title="'Edit '.$member->fullName()">
    <x-page-header eyebrow="Members · {{ $member->membership_no }}" title="Edit member"
                   :subtitle="$member->fullName()">
        <a href="{{ route('admin.members.show', $member) }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back to profile</a>
    </x-page-header>

    <form method="POST" action="{{ route('admin.members.update', $member) }}" novalidate>
        @csrf
        @method('PUT')
        @include('members._form')

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <a href="{{ route('admin.members.show', $member) }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" data-loading="Saving…">Save changes</button>
        </div>
    </form>
</x-layouts.app>
