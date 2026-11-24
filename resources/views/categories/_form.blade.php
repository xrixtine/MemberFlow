<div class="card max-w-2xl space-y-5">
    <x-form.input name="name" label="Category name" :value="$category->name" required maxlength="50" placeholder="e.g. Student"/>
    <x-form.textarea name="description" label="Description" :value="$category->description" rows="3" maxlength="1000"
                     hint="Optional. Who qualifies for this category?"/>
    <label class="flex items-start gap-3 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="mt-0.5 size-4 accent-[#5d7f24]"
            @checked(old('is_active', $category->is_active ? '1' : '0') == '1')>
        <span><span class="font-bold">Active</span>
            <span class="block text-muted">Inactive categories stay on existing members but cannot be chosen for new registrations.</span></span>
    </label>
</div>
