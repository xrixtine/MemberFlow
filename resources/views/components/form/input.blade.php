@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'bag' => 'default', 'id' => null])
@php
    $id ??= $name;
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $error = $errors->getBag($bag)->first($errorKey);
    $current = $type === 'password' ? null : old($errorKey, $value);
@endphp
<div {{ $attributes->only('class')->class('') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">
            {{ $label }}@if ($attributes->has('required'))<span class="text-red-600" aria-hidden="true"> *</span>@endif
        </label>
    @endif
    <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}"
           @if (! is_null($current)) value="{{ $current }}" @endif
           @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif ($hint) aria-describedby="{{ $id }}-hint" @endif
        {{ $attributes->except('class')->class(['form-input', 'form-input-invalid' => $error]) }}>
    @if ($error)
        <p id="{{ $id }}-error" class="form-error">{{ $error }}</p>
    @elseif ($hint)
        <p id="{{ $id }}-hint" class="form-hint">{{ $hint }}</p>
    @endif
</div>
