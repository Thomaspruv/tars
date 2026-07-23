@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="TARS" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="TARS" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-icon class="size-8" />
        </x-slot>
    </flux:brand>
@endif
