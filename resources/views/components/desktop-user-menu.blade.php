<flux:dropdown position="top" align="start" class="w-full">
    <button type="button" class="sidebar-aside flex w-full items-center gap-[11px] border-t border-(--bd) px-4 pt-3 text-(--tx)" data-test="sidebar-menu-button">
        <span class="inline-flex size-[28px] items-center justify-center rounded-full bg-(--ac) text-[13px] font-bold text-(--acT)">
            {{ auth()->user()->initials() }}
        </span>
        <span class="truncate">{{ auth()->user()->name }}</span>
    </button>

    <flux:menu>
        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
            {{ __('Settings') }}
        </flux:menu.item>
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
