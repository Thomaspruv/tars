<?php

use App\Models\InboxItem;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Poser dans l\'inbox')] class extends Component
{
    public string $content = '';

    public bool $captured = false;

    /**
     * La cible de partage arrive en GET (c'est le contrat du Web Share Target,
     * et c'est aussi ce qu'un raccourci iOS sait ouvrir). On ne crée donc rien
     * au chargement : on pré-remplit, et c'est le pouce qui valide.
     */
    public function mount(): void
    {
        $this->content = $this->composeSharedContent(
            $this->sharedParam('title'),
            $this->sharedParam('text'),
            $this->sharedParam('url'),
        );
    }

    public function capture(): void
    {
        $this->validate([
            'content' => ['required', 'string', 'max:5000'],
        ], attributes: ['content' => 'note']);

        InboxItem::create(['content' => trim($this->content)]);

        $this->captured = true;
    }

    public function captureAnother(): void
    {
        $this->reset(['content', 'captured']);
    }

    private function sharedParam(string $key): string
    {
        $value = request()->query($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Les applications ne s'accordent pas sur ce qu'elles mettent où : les unes
     * répètent le titre dans le texte, les autres glissent l'URL dans le texte
     * et laissent `url` vide. On recompose une note lisible sans doublon.
     */
    private function composeSharedContent(string $title, string $text, string $url): string
    {
        $lines = [];

        if ($title !== '' && ! str_contains($text, $title)) {
            $lines[] = $title;
        }

        if ($text !== '') {
            $lines[] = $text;
        }

        if ($url !== '' && ! str_contains($title.' '.$text, $url)) {
            $lines[] = $url;
        }

        return trim(implode("\n", $lines));
    }
};
?>

<div class="mx-auto max-w-[640px]">
    @if ($captured)
        <x-screen-header kicker="c'est posé" title="Dans l'inbox." class="mb-6" />

        <p class="mb-6 text-[15px] leading-[1.5] text-(--mut)">
            Rien à décider maintenant — le tri viendra plus tard.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <x-btn variant="primary" size="md" wire:click="captureAnother">Poser autre chose</x-btn>
            <x-btn variant="secondary" size="md" :href="route('inbox.index')" wire:navigate>Voir l'inbox</x-btn>
            <x-btn variant="ghost" size="md" :href="route('today')" wire:navigate>Aujourd'hui</x-btn>
        </div>
    @else
        <x-screen-header kicker="capture" title="Poser dans l'inbox" class="mb-[18px]" />

        <form wire:submit="capture">
            <textarea
                wire:model="content"
                autofocus
                rows="5"
                placeholder="Poser quelque chose…"
                class="w-full resize-y rounded-[18px] bg-(--surf2) px-[18px] py-4 text-[16px] text-(--tx) placeholder:text-(--mut) focus:outline-2 focus:outline-offset-2 focus:outline-(--ac)"
            >{{ $content }}</textarea>

            @error('content')
                <p class="mt-2 text-[13px] text-(--dgr)">{{ $message }}</p>
            @enderror

            <div class="mt-[14px] flex flex-wrap items-center gap-2">
                <x-btn variant="primary" size="md" type="submit" wire:loading.attr="disabled">Poser</x-btn>
                <x-btn variant="ghost" size="md" :href="route('inbox.index')" wire:navigate>Annuler</x-btn>
            </div>
        </form>
    @endif
</div>
