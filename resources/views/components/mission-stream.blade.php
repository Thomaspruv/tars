@props(['rows', 'depth' => 0])

{{-- Le flux d'une mission, en arbre.

     Une délégation n'est pas un appel d'outil comme un autre : c'est un agent
     qui en missionne un second. Elle porte donc le nom du coéquipier, se replie
     d'un clic, et tout ce que le second produit s'indente sous elle. La
     hiérarchie vient telle quelle du `parent_tool_use_id` du flux. --}}
<div class="flex flex-col gap-2">
    @foreach ($rows as $row)
        @php
            $event = $row['event'];
            $result = $row['result'];
            $children = $row['children'];
            $failed = (bool) ($result?->payload['is_error'] ?? false);
        @endphp

        <div wire:key="event-{{ $event->id }}" class="flex gap-2.5">
            @if ($depth === 0)
                {{-- La colonne du temps : une mission se lit comme un journal,
                     et savoir qu'une étape a pris trente secondes change ce
                     qu'on en pense. --}}
                <span class="w-[42px] shrink-0 pt-[13px] text-right font-mono text-[11px] text-(--mut)">{{ $row['elapsed'] !== null ? '+'.$row['elapsed'].'s' : '' }}</span>
            @endif

            <div class="min-w-0 flex-1">
                @if ($event->type === 'alert')
                    {{-- Ce qu'il a jugé assez important pour me déranger. --}}
                    <div class="rounded-[16px] border-2 border-(--ac) bg-(--acbg) px-[18px] py-[14px]">
                        <p class="mb-1 text-[12px] font-bold text-(--ac)">Il t'a prévenu</p>
                        <p class="text-[14px] leading-[1.6] whitespace-pre-wrap text-(--tx)">{{ $event->text }}</p>
                        @if ($event->payload['link'] ?? null)
                            <a href="{{ $event->payload['link'] }}" target="_blank" rel="noopener" class="mt-2 inline-block text-[13px] font-semibold text-(--ac) underline">Y aller →</a>
                        @endif
                    </div>

                @elseif ($event->isMine())
                    {{-- La seule chose de ce fil qui ne vient pas d'une
                         machine. Elle porte donc l'abricot, la couleur de ce
                         que je fais, et jamais le vert de l'agent. --}}
                    <div class="rounded-[18px] bg-(--acbg) px-[20px] py-[16px]">
                        <p class="mb-1 text-[12px] font-bold text-(--ac)">Moi</p>
                        <p class="text-[14px] leading-[1.65] whitespace-pre-wrap text-(--tx)">{{ $event->text }}</p>
                    </div>

                @elseif ($event->isBriefing())
                    <div class="rounded-[14px] bg-(--sunk) px-[18px] py-[12px]">
                        <p class="mb-1 text-[12px] font-semibold text-(--mut)">Sa consigne</p>
                        <p class="text-[13px] leading-[1.6] whitespace-pre-wrap text-(--mut)">{{ Str::limit($event->text, 400) }}</p>
                    </div>

                @elseif ($event->subtype === 'text' && $row['conclusion'])
                    {{-- Le dernier mot : c'est ce que Thomas est venu lire. Il
                         ne doit pas avoir le poids d'une étape intermédiaire. --}}
                    <x-agent-block title="Le rapport" padding="22" rail="4">
                        <p class="text-[15px] leading-[1.7] whitespace-pre-wrap text-(--tx)">{{ $event->text }}</p>
                    </x-agent-block>

                @elseif ($event->subtype === 'text')
                    <x-agent-block padding="16" rail="3">
                        <div class="brain-content text-[14px] leading-[1.65] text-(--tx)">{!! $this->asHtml($event->text) !!}</div>
                    </x-agent-block>

                @elseif ($event->subtype === 'thinking')
                    <div x-data="{ open: false }">
                        <button type="button" x-on:click="open = ! open" class="press text-[12px] text-(--mut) hover:text-(--tx)">
                            <span x-text="open ? '− il réfléchit' : '+ il réfléchit'">+ il réfléchit</span>
                        </button>
                        <p x-show="open" x-cloak class="mt-1.5 text-[12px] leading-[1.6] whitespace-pre-wrap text-(--mut) italic">{{ $event->text }}</p>
                    </div>

                @elseif ($event->isDelegation())
                    <div x-data="{ open: true }" class="rounded-[16px] bg-(--aibg) p-[4px]">
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            class="press flex w-full flex-wrap items-baseline gap-2.5 rounded-[13px] px-[16px] py-[11px] text-left hover:bg-(--surf)/20"
                        >
                            <span class="text-[13px] font-bold text-(--ai)">{{ $row['speaker'] ?? 'Un coéquipier' }}</span>
                            @if ($event->text)
                                <span class="min-w-0 flex-1 truncate text-[13px] text-(--mut)">{{ $event->text }}</span>
                            @endif
                            <span class="shrink-0 text-[11px] text-(--mut)">
                                @if ($result === null)
                                    au travail…
                                @elseif ($failed)
                                    <span class="text-(--warn)">a échoué</span>
                                @else
                                    {{ $row['steps'] }} {{ Str::plural('étape', $row['steps']) }}
                                @endif
                            </span>
                            <span class="shrink-0 text-[11px] text-(--mut)" x-text="open ? '▾' : '▸'">▾</span>
                        </button>

                        @if (count($children) > 0)
                            <div x-show="open" x-cloak class="px-[14px] pt-1 pb-[10px]">
                                <x-mission-stream :rows="$children" :depth="$depth + 1" />
                            </div>
                        @endif
                    </div>

                @elseif ($event->subtype === 'tool_use')
                    <div x-data="{ open: false }" class="rounded-[14px] bg-(--surf)">
                        <button
                            type="button"
                            @if ($result?->text) x-on:click="open = ! open" @endif
                            @class([
                                'flex w-full flex-wrap items-baseline gap-2.5 px-[18px] py-[11px] text-left',
                                'press' => $result?->text !== null,
                                'cursor-default' => $result?->text === null,
                            ])
                        >
                            <span class="font-mono text-[12px] font-bold text-(--tx)">{{ $event->tool_name }}</span>
                            @if ($event->text)
                                <span class="min-w-0 flex-1 truncate text-[13px] text-(--mut)">{{ $event->text }}</span>
                            @endif
                            @if ($result === null)
                                <span class="shrink-0 text-[11px] text-(--mut)">en cours…</span>
                            @elseif ($failed)
                                <span class="shrink-0 text-[11px] text-(--warn)">a échoué</span>
                            @else
                                <span class="shrink-0 text-[12px] text-(--ai)" aria-label="terminé">✓</span>
                            @endif
                        </button>

                        @if ($result?->text)
                            <pre
                                x-show="open"
                                x-cloak
                                class="max-h-[260px] overflow-auto px-[18px] pb-[14px] font-mono text-[11px] leading-[1.55] whitespace-pre-wrap text-(--mut)"
                            >{{ $result->text }}</pre>
                        @endif
                    </div>

                @elseif ($event->type === 'system')
                    <p class="py-1 text-[12px] text-(--mut)">{{ $event->text }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
