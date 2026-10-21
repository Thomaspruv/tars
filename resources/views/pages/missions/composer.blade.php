{{-- Le nouveau fil. Thomas ne choisit pas son interlocuteur : il écrit au
     chef, et c'est au chef de trouver la bonne personne. --}}
<div x-data="{ options: @js($skillSlug !== '') }" class="flex min-h-[55vh] flex-col justify-center">
    <div class="mb-6 text-center">
        <p class="font-display text-[26px] font-bold text-(--tx)">
            {{ $this->chief?->name ?? 'Personne pour répondre' }}
        </p>
        <p class="mt-1.5 text-[14px] text-(--mut)">
            @if ($this->chief)
                Dis-lui ce que tu veux. Il trouvera qui s'en occupe.
            @else
                L'équipe est vide ou tout le monde est au repos.
            @endif
        </p>
    </div>

    <x-chat-box placeholder="Qu'est-ce qu'on fait ?" :autofocus="true" />

    <div class="mt-3 flex flex-wrap items-center justify-center gap-2.5 text-[12px] text-(--mut)">
        @if ($skillSlug !== '')
            <span class="rounded-full bg-(--aibg) px-3 py-1 font-mono text-(--ai)">/{{ $skillSlug }}</span>
        @endif

        <button type="button" x-on:click="options = ! options" class="press rounded-full px-3 py-1 hover:text-(--tx)">
            <span x-text="options ? 'Moins' : 'Options'">Options</span>
        </button>
    </div>

    {{-- Le skill et le plafond sont des réglages, pas une question : ils
         attendent derrière un bouton, préremplis. --}}
    <div x-show="options" x-cloak class="mt-3 flex flex-wrap items-center justify-center gap-2.5">
        <x-select wire:model="skillSlug" class="min-w-[200px]">
            <option value="">Sans skill</option>
            @foreach ($this->skills as $skill)
                <option value="{{ $skill->slug }}">{{ $skill->slug }}</option>
            @endforeach
        </x-select>

        <label class="flex items-center gap-2 rounded-full bg-(--surf2) pr-1 pl-4">
            <span class="text-[13px] whitespace-nowrap text-(--mut)">max</span>
            <x-input variant="technical" wire:model="budget" class="w-[70px] !bg-transparent !px-0" inputmode="decimal" aria-label="Plafond en dollars" />
            <span class="pr-3 text-[13px] text-(--mut)">$</span>
        </label>

        {{-- Une veille : il revérifiera tout seul jusqu'à trouver. --}}
        <label class="flex items-center gap-2 rounded-full bg-(--surf2) pr-1 pl-4">
            <span class="text-[13px] whitespace-nowrap text-(--mut)">revérifier toutes les</span>
            <x-input variant="technical" wire:model="watchEvery" class="w-[56px] !bg-transparent !px-0" inputmode="numeric" placeholder="—" aria-label="Intervalle de veille en minutes" />
            <span class="text-[13px] whitespace-nowrap text-(--mut)">min pendant</span>
            <x-input variant="technical" wire:model="watchHours" class="w-[50px] !bg-transparent !px-0" inputmode="numeric" placeholder="168" aria-label="Durée de la veille en heures" />
            <span class="pr-3 text-[13px] text-(--mut)">h</span>
        </label>

        @error('budget')<p class="w-full text-center text-[12px] text-(--warn)">{{ $message }}</p>@enderror
    </div>

    <p class="mt-4 text-center text-[12px] text-(--mut)">
        Il travaille avec tous les droits dans son répertoire. Tu peux l'arrêter à tout moment.
    </p>
</div>
