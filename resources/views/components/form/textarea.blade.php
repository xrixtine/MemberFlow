@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'rows' => 4, 'id' => null])
@php
    $id ??= $name;
    $error = $errors->first($name);
@endphp
<div {{ $attributes->only('class')->class('') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">
            {{ $label }}@if ($attributes->has('required'))<span class="text-red-600" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
              @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except('class')->class(['form-input', 'form-input-invalid' => $error]) }}>{{ old($name, $value) }}</textarea>
    @if ($error)
        <p id="{{ $id }}-error" class="form-error">{{ $error }}</p>
    @elseif ($hint)
        <p class="form-hint">{{ $hint }}</p>
    @endif
</div>
