@props(['fields', 'prefix', 'target' => null])

@if (! empty($fields))
    <div class="grid grid-cols-2 gap-3">
        @foreach ($fields as $field)
            <div>
                <label class="text-xs text-(--mut)">{{ $field['label'] }}</label>
                @if ($field['type'] === 'select')
                    <select wire:model="{{ $prefix }}.{{ $field['key'] }}" @if ($target) wire:loading.attr="disabled" wire:target="{{ $target }}" @endif class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                        <option value="">—</option>
                        @foreach ($field['options'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    <input
                        type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : 'text') }}"
                        wire:model="{{ $prefix }}.{{ $field['key'] }}"
                        @if ($target) wire:loading.attr="disabled" wire:target="{{ $target }}" @endif
                        class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"
                    />
                @endif
            </div>
        @endforeach
    </div>
@endif
