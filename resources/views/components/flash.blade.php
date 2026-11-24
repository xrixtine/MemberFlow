{{-- Session flash messages (success / status / error / warning). --}}
@foreach (['success' => 'alert-success', 'status' => 'alert-info', 'error' => 'alert-error', 'warning' => 'alert-warning'] as $key => $class)
    @if (session($key))
        <div class="{{ $class }} mb-6" role="{{ $key === 'error' ? 'alert' : 'status' }}" data-dismissible>
            <x-icon :name="$key === 'success' ? 'check' : ($key === 'status' ? 'mail' : 'alert')" class="mt-0.5 size-4 shrink-0"/>
            <p class="flex-1">{{ session($key) }}</p>
            <button type="button" class="opacity-60 hover:opacity-100" data-dismiss aria-label="Dismiss message">
                <x-icon name="x" class="size-4"/>
            </button>
        </div>
    @endif
@endforeach
