{{-- Le fil ouvert : une conversation, pas un journal de bord. Ce que l'agent a
     fait pour répondre est vrai mais secondaire — il se replie dès que la
     réponse arrive, et reste ouvert tant qu'elle n'est pas là. --}}
<a href="{{ route('missions.index') }}" wire:navigate class="press mb-3 inline-block text-[13px] text-(--mut) hover:text-(--tx) lg:hidden">← Conversations</a>

<div class="mb-6 flex flex-wrap items-baseline gap-2.5">
    <span class="font-display text-[20px] font-bold text-(--tx)">{{ $mission->teamAgent->name }}</span>

    @if ($mission->isFromHermes())
        <span class="rounded-full bg-(--aibg) px-2.5 py-[2px] text-[11px] font-semibold text-(--ai)">lancée par Hermès</span>
    @endif

    @if ($mission->skill_slug)
        <span class="font-mono text-[12px] text-(--ai)">/{{ $mission->skill_slug }}</span>
    @endif

    <span class="ml-auto flex items-baseline gap-3 text-[12px] text-(--mut)">
        @if ($mission->cost_usd !== null)
            <span>{{ number_format((float) $mission->cost_usd, 2) }} $</span>
        @endif
        @if ($this->subagentCount > 0)
            <span class="text-(--ai)">{{ $this->subagentCount }} {{ Str::plural('délégation', $this->subagentCount) }}</span>
        @endif
    </span>

    @if ($mission->status->isOpen())
        <x-btn wire:click="stop" variant="danger" size="xs" wire:loading.attr="disabled">Arrêter</x-btn>
    @elseif ($mission->status !== App\Enums\MissionStatus::Succeeded)
        <x-badge type="pill" :tone="$mission->status->tone()" class="!text-[12px]">{{ $mission->status->label() }}</x-badge>
    @endif
</div>

@if ($mission->isWatching())
    {{-- Une veille tourne sans moi : l'écran doit dire qu'elle tourne, à quel
         rythme, et quand elle repassera — sinon on ne sait pas si elle est
         vivante. --}}
    <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[16px] bg-(--aibg) px-[18px] py-[13px]">
        <span class="flex items-center gap-2 text-[13px] font-bold text-(--ai)">
            <span class="inline-block size-[7px] animate-pulse rounded-full bg-(--ai)"></span>
            En veille
        </span>
        <span class="text-[12px] text-(--mut)">
            toutes les {{ $mission->watch_every_minutes }} min ·
            {{ $mission->watch_runs }} {{ Str::plural('passage', $mission->watch_runs) }}
            @if ($mission->watch_next_at)
                · prochain {{ $mission->watch_next_at->diffForHumans() }}
            @endif
            @if ($mission->watch_until)
                · jusqu'au {{ $mission->watch_until->translatedFormat('d M') }}
            @endif
            @if ($mission->watch_max_usd)
                · {{ number_format((float) $mission->cost_usd, 2) }} / {{ number_format((float) $mission->watch_max_usd, 2) }} $
            @endif
        </span>
        <x-btn wire:click="stopWatch" variant="ghost" size="xs" class="ml-auto" wire:loading.attr="disabled">Arrêter la veille</x-btn>

        @if ($mission->hasSentinel())
            {{-- La sentinelle est ce qui rend la veille abordable : on dit ce
                 qu'elle garde, et combien de réveils elle a évités. --}}
            <p class="w-full border-t border-(--bd) pt-2.5 text-[12px] text-(--mut)">
                Tars garde <span class="font-mono text-(--tx)">{{ Str::limit($mission->watch_url, 70) }}</span>
                et ne le réveille que si la page bouge
                @if ($mission->watch_skips > 0)
                    — {{ $mission->watch_skips }} {{ Str::plural('vérification', $mission->watch_skips) }} sans rien déranger
                @endif
                @if ($mission->watch_checked_at)
                    · vue {{ $mission->watch_checked_at->diffForHumans() }}
                @endif
            </p>
        @endif
    </div>
@elseif ($mission->watch_ended_reason)
    <p class="mb-5 rounded-[16px] bg-(--sunk) px-[18px] py-[13px] text-[13px] text-(--mut)">
        Veille terminée après {{ $mission->watch_runs }} {{ Str::plural('passage', $mission->watch_runs) }} :
        {{ $mission->watch_ended_reason->label() }}.
    </p>
@endif

<div class="flex flex-col gap-7">
    @foreach ($this->turns as $turn)
        <div wire:key="turn-{{ $turn['turn'] }}" class="flex flex-col gap-3.5">
            @if ($turn['prompt'])
                {{-- Ce que j'écris : une bulle, à droite, et jamais sur toute la
                     largeur. L'abricot est la couleur de ce que je fais. --}}
                <div class="flex justify-end">
                    <p class="max-w-[85%] rounded-[20px] bg-(--acbg) px-[18px] py-[12px] text-[15px] leading-[1.6] whitespace-pre-wrap text-(--tx)">{{ $turn['prompt']->text }}</p>
                </div>
            @endif

            @if (count($turn['work']) > 0)
                <div x-data="{ open: @js($turn['running']) }">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        class="press flex items-center gap-2 text-[12px] text-(--mut) hover:text-(--tx)"
                    >
                        @if ($turn['running'])
                            <span class="inline-block size-[7px] animate-pulse rounded-full bg-(--ai)"></span>
                            <span class="font-semibold text-(--ai)">au travail</span>
                        @else
                            <span>{{ count($turn['work']) }} {{ Str::plural('étape', count($turn['work'])) }}</span>
                        @endif

                        @if ($turn['seconds'] > 0)
                            <span>· {{ $turn['seconds'] }} s</span>
                        @endif

                        <span x-text="open ? '▾' : '▸'">▸</span>
                    </button>

                    <div x-show="open" x-cloak class="mt-2.5">
                        <x-mission-stream :rows="$turn['work']" />
                    </div>
                </div>
            @endif

            @foreach ($turn['alerts'] as $alert)
                {{-- Ce qu'il a jugé assez important pour me déranger. Jamais
                     replié : c'est ce pour quoi la veille existait. --}}
                <div wire:key="alert-{{ $alert->id }}" class="rounded-[16px] border-2 border-(--ac) bg-(--acbg) px-[20px] py-[16px]">
                    <p class="mb-1.5 text-[12px] font-bold text-(--ac)">Il t'a prévenu</p>
                    <p class="text-[15px] leading-[1.65] whitespace-pre-wrap text-(--tx)">{{ $alert->text }}</p>
                    @if ($alert->payload['link'] ?? null)
                        <a href="{{ $alert->payload['link'] }}" target="_blank" rel="noopener" class="mt-2.5 inline-block text-[13px] font-semibold text-(--ac) underline">Y aller →</a>
                    @endif
                </div>
            @endforeach

            @if ($turn['answer'])
                {{-- La réponse : pleine largeur, sans bulle, avec le filet vert
                     qui dit qu'elle vient d'une machine. --}}
                <div class="flex gap-[14px]">
                    <div class="w-[3px] shrink-0 self-stretch rounded-full bg-(--ai)"></div>
                    <div class="brain-content min-w-0 flex-1 text-[15px] leading-[1.7] text-(--tx)">{!! $this->asHtml($turn['answer']['event']->text) !!}</div>
                </div>
            @endif
        </div>
    @endforeach

    @if (count($this->turns) === 0)
        <p class="text-[14px] text-(--mut)">
            @if ($mission->status === App\Enums\MissionStatus::Queued)
                En file. Il démarre dès que le worker le prend — vérifie que <span class="font-mono">queue:listen --queue=missions</span> tourne.
            @else
                Rien n'est encore arrivé.
            @endif
        </p>
    @endif

    @if ($mission->error)
        <p class="rounded-[14px] bg-(--surf) px-[18px] py-[14px] font-mono text-[12px] leading-[1.6] whitespace-pre-wrap text-(--warn)">{{ $mission->error }}</p>
    @endif
</div>

{{-- La zone de réponse, collée en bas. Elle se ferme pendant qu'il travaille :
     on ne parle pas à un agent au milieu d'un appel d'outil. --}}
<div class="sticky bottom-0 mt-7 bg-(--bg) pt-3 pb-5">
    @if ($mission->status->isOpen())
        <p class="flex items-center gap-2 rounded-[24px] bg-(--surf) px-5 py-4 text-[13px] text-(--mut)">
            <span class="inline-block size-[7px] animate-pulse rounded-full bg-(--ai)"></span>
            {{ $mission->teamAgent->name }} travaille. Tu pourras lui répondre quand il aura rendu.
        </p>
    @else
        <x-chat-box placeholder="Lui répondre…" />
    @endif
</div>
