{{-- En mobile, la marque et le thème remontent en tête de page : la sidebar
     desktop qui les portait est remplacée par la barre d'onglets du bas. --}}
<div class="flex items-center gap-3 px-5 pt-4 lg:hidden">
    <a href="{{ route('today') }}" wire:navigate class="flex items-center gap-2.5">
        <x-app-logo-icon class="size-[20px]" />
        <span class="font-display text-[17px] font-bold tracking-[-.01em] text-(--tx)">TARS</span>
    </a>

    <x-theme-toggle class="ml-auto text-[13px] text-(--mut)" />
</div>
