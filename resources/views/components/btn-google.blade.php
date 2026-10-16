@props([
    'href',
    'label' => 'Continuer avec Google',
])

{{-- Bouton de marque Google : fond blanc, filet gris et « G » quadrichrome
     dans les deux thèmes — les règles de marque interdisent de le reteinter.
     Seul l'enfoncement de 2px est repris du système, pour qu'il réponde au
     doigt comme les autres boutons de Tars. --}}
<a
    href="{{ $href }}"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-3 rounded-full border border-[#747775] bg-white px-[18px] py-[10px] text-[14px] font-semibold whitespace-nowrap text-[#1F1F1F] shadow-[0_2px_0_#C6C9CE] transition-[background-color,box-shadow,translate] duration-(--dur-tap) ease-(--ease) hover:bg-[#F7F8F8] active:translate-y-[2px] active:shadow-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ac)']) }}
>
    <x-brand-google class="size-[18px] shrink-0" />
    {{ $label }}
</a>
