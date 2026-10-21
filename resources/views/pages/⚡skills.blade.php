<?php

use App\Support\Missions\RegistrySkill;
use App\Support\Missions\Skill;
use App\Support\Missions\SkillCatalog;
use App\Support\Missions\SkillInstaller;
use App\Support\Missions\SkillRegistry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Skills')] class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    /**
     * Ce qu'on a demandé au registre, par identifiant de skill : soit la
     * description du skill, soit la raison pour laquelle on ne l'a pas.
     *
     * @var array<string, array{text: string, isNote: bool}>
     */
    public array $descriptions = [];

    public string $package = '';

    public string $onlySkill = '';

    public string $output = '';

    /**
     * Les skills installés, groupés par portée : ce que le projet apporte,
     * et ce qui est disponible partout.
     *
     * @return array<string, list<Skill>>
     */
    #[Computed]
    public function grouped(): array
    {
        $grouped = [];

        foreach (app(SkillCatalog::class)->all() as $skill) {
            $grouped[$skill->scope][] = $skill;
        }

        return $grouped;
    }

    /**
     * Ce que le registre skills.sh propose pour cette recherche.
     *
     * @return list<RegistrySkill>
     */
    #[Computed]
    public function results(): array
    {
        return app(SkillRegistry::class)->search($this->query);
    }

    #[Computed]
    public function searching(): bool
    {
        return mb_strlen(trim($this->query)) >= 2;
    }

    /**
     * Ce qu'un skill fait, demandé au registre sans l'installer. On ne le
     * demande que pour celui qu'on regarde : la réponse coûte un appel au CLI.
     */
    public function describe(string $source, string $skillId): void
    {
        $read = app(SkillRegistry::class)->describe($source, $skillId);

        $this->descriptions[$source.'/'.$skillId] = [
            'text' => $read['description'] ?? $read['note'] ?? '',
            'isNote' => $read['description'] === null,
        ];
    }

    public function installFromRegistry(string $source, string $skillId): void
    {
        $result = app(SkillInstaller::class)->install($source, $skillId);

        $this->output = $result['output'];

        unset($this->grouped, $this->results);
    }

    public function refresh(): void
    {
        app(SkillCatalog::class)->forget();

        unset($this->grouped, $this->results);

        $this->output = '';
    }

    /**
     * Ce qu'un dépôt propose, avant de rien installer.
     */
    public function preview(): void
    {
        if (! $this->validPackage()) {
            return;
        }

        $this->output = app(SkillInstaller::class)->preview($this->package)['output'];
    }

    public function install(): void
    {
        if (! $this->validPackage()) {
            return;
        }

        $result = app(SkillInstaller::class)->install($this->package, $this->onlySkill ?: null);

        $this->output = $result['output'];

        if ($result['ok']) {
            unset($this->grouped, $this->results);

            $this->package = '';
            $this->onlySkill = '';
        }
    }

    private function validPackage(): bool
    {
        if (! SkillCatalog::isValidPackage($this->package)) {
            $this->addError('package', 'Donne un dépôt sous la forme <code>propriétaire/dépôt</code>.');

            return false;
        }

        $this->resetErrorBag('package');

        return true;
    }
};
?>

<div x-data="{ adding: false }">
    @php $total = collect($this->grouped)->flatten()->count(); @endphp

    <x-space-tabs space="hermes" class="mb-3.5 -ml-3.5" />

    <x-screen-header :kicker="$total.' '.Str::plural('skill', $total).' installés'" title="Skills" class="mb-4">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-btn wire:click="refresh" variant="ghost" size="sm">Relire le disque</x-btn>
                <x-btn x-on:click="adding = ! adding" variant="secondary" size="sm">Par dépôt</x-btn>
            </div>
        </x-slot:actions>
    </x-screen-header>

    {{-- Chercher d'abord : un skill qu'on n'a pas est plus utile qu'un rappel
         de ceux qu'on a déjà. La recherche tape le registre public de
         skills.sh, qui ne connaît qu'un nom, un dépôt et une popularité — ce
         que le skill fait se demande à la demande, pour celui qu'on regarde. --}}
    <div class="mb-6">
        <label class="relative block">
            <span class="sr-only">Chercher un skill sur skills.sh</span>
            <input
                wire:model.live.debounce.600ms="query"
                type="search"
                placeholder="Chercher un skill sur skills.sh — obsidian, pdf, gmail, finance…"
                class="w-full rounded-full bg-(--surf2) px-5 py-3.5 text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
            />
            <span wire:loading wire:target="query" class="absolute top-1/2 right-5 -translate-y-1/2 text-[12px] text-(--mut)">…</span>
        </label>
    </div>

    <div x-show="adding" x-cloak class="mb-6">
        <x-card padding="22">
            <p class="mb-4 text-[13px] leading-[1.6] text-(--mut)">
                Si le dépôt n'est pas dans le registre, donne-le à la main.
            </p>

            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-[220px] flex-1">
                    <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Dépôt</span>
                    <x-input variant="technical" wire:model="package" placeholder="anthropics/skills" class="w-full" />
                </label>
                <label class="min-w-[180px] flex-1">
                    <span class="mb-1.5 block text-[13px] font-semibold text-(--mut)">Un skill en particulier</span>
                    <x-input variant="technical" wire:model="onlySkill" placeholder="facultatif" class="w-full" />
                </label>
                <div class="flex gap-2 pb-[1px]">
                    <x-btn wire:click="preview" variant="secondary" size="md" wire:loading.attr="disabled">Voir</x-btn>
                    <x-btn wire:click="install" variant="primary" size="md" wire:loading.attr="disabled">Installer</x-btn>
                </div>
            </div>

            @error('package')<p class="mt-2 text-[12px] text-(--warn)">{!! $message !!}</p>@enderror
        </x-card>
    </div>

    @if ($output !== '')
        {{-- La queue seulement : un CLI commence par une bannière en art ASCII
             et finit par ce qu'on voulait savoir. --}}
        <pre class="mb-6 max-h-[200px] overflow-auto rounded-[14px] bg-(--sunk) p-4 font-mono text-[11px] leading-[1.6] whitespace-pre-wrap text-(--mut)">{{ collect(explode("\n", $output))->filter(fn (string $line): bool => trim($line) !== '')->take(-10)->implode("\n") }}</pre>
    @endif

    @if ($this->searching)
        <h2 class="mb-3 text-[12px] text-(--mut) kicker">
            Sur skills.sh · {{ count($this->results) }}
        </h2>

        @if ($this->results === [])
            <x-card tone="dashed" padding="22" class="mb-7">
                <p class="text-[14px] text-(--mut)">
                    Rien pour « {{ $this->query }} ». Essaie un autre mot, ou donne le dépôt à la main.
                </p>
            </x-card>
        @else
            <div class="mb-8 grid items-start gap-2.5" style="grid-template-columns: repeat(auto-fill, minmax(340px, 1fr))">
                @foreach ($this->results as $found)
                    @php $detail = $this->descriptions[$found->id()] ?? null; @endphp

                    <x-card padding="18" wire:key="found-{{ $found->id() }}">
                        <div class="mb-1.5 flex items-baseline gap-2">
                            <span class="font-mono text-[13px] font-bold text-(--tx)">{{ $found->skillId }}</span>
                            <span class="ml-auto text-[11px] text-(--mut)">{{ $found->installsLabel() }} ↓</span>
                        </div>

                        <p class="mb-2.5 truncate text-[12px] text-(--mut)">{{ $found->source }}</p>

                        @if ($detail)
                            {{-- Ce qu'on est allé chercher exprès : en entier, ou
                                 la raison pour laquelle on n'a rien. --}}
                            <p @class([
                                'mb-3.5 text-[13px] leading-[1.6]',
                                'text-(--mut) italic' => $detail['isNote'],
                                'text-(--tx)' => ! $detail['isNote'],
                            ])>{{ Str::limit($detail['text'], 400) }}</p>
                        @elseif ($found->description)
                            <p class="mb-3.5 line-clamp-3 text-[13px] leading-[1.6] text-(--mut)">{{ $found->description }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($found->installed)
                                <x-badge type="pill" tone="agent" class="!text-[12px]">déjà installé</x-badge>
                            @else
                                <x-btn
                                    wire:click="installFromRegistry('{{ $found->source }}', '{{ $found->skillId }}')"
                                    wire:target="installFromRegistry('{{ $found->source }}', '{{ $found->skillId }}')"
                                    wire:loading.attr="disabled"
                                    variant="primary"
                                    size="xs"
                                >Installer</x-btn>
                            @endif

                            @unless ($detail)
                                <x-btn
                                    wire:click="describe('{{ $found->source }}', '{{ $found->skillId }}')"
                                    wire:target="describe('{{ $found->source }}', '{{ $found->skillId }}')"
                                    wire:loading.attr="disabled"
                                    variant="ghost"
                                    size="xs"
                                >{{ $found->description ? 'En entier' : "Ce qu'il fait" }}</x-btn>
                            @endunless

                            <span
                                wire:loading
                                wire:target="describe('{{ $found->source }}', '{{ $found->skillId }}'),installFromRegistry('{{ $found->source }}', '{{ $found->skillId }}')"
                                class="text-[12px] text-(--mut)"
                            >un instant…</span>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    @endif

    @forelse ($this->grouped as $scope => $skills)
        <h2 class="mb-3 text-[12px] text-(--mut) kicker">
            {{ $scope === 'projet' ? 'Dans ce projet' : 'Installés, disponibles partout' }} · {{ count($skills) }}
        </h2>

        <div class="mb-7 grid items-start gap-2.5" style="grid-template-columns: repeat(auto-fill, minmax(340px, 1fr))">
            @foreach ($skills as $skill)
                {{-- La carte entière emmène à la mission : le geste de cet
                     écran est « missionner avec celui-là », pas « lire ». --}}
                <a
                    href="{{ route('missions.index', ['skill' => $skill->slug]) }}"
                    wire:navigate
                    wire:key="skill-{{ $skill->scope }}-{{ $skill->slug }}"
                    class="press group flex h-full flex-col rounded-[18px] bg-(--surf) px-[20px] py-[18px] hover:bg-(--surf2)"
                >
                    <div class="mb-2 flex items-baseline gap-2">
                        <span class="font-mono text-[13px] font-bold text-(--tx)">{{ $skill->slug }}</span>
                        @if ($skill->source)
                            <span class="ml-auto truncate text-[11px] text-(--mut)">{{ $skill->source }}</span>
                        @endif
                    </div>

                    <p class="mb-3.5 line-clamp-3 text-[13px] leading-[1.6] text-(--mut)">{{ $skill->shortDescription(220) }}</p>

                    {{-- Visible en permanence, pas au survol : au doigt, il n'y
                         a pas de survol, et l'affordance disparaîtrait. --}}
                    <span class="mt-auto text-[12px] font-semibold text-(--mut) transition-colors group-hover:text-(--ai)">
                        Missionner avec ce skill →
                    </span>
                </a>
            @endforeach
        </div>
    @empty
        <x-card tone="dashed" padding="22">
            <p class="text-[14px] text-(--mut)">Aucun skill installé. Cherche-en un ci-dessus pour commencer.</p>
        </x-card>
    @endforelse
</div>
