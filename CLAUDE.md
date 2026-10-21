# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Tars is a personal life-management app (goals, tasks, entities, lists, inbox capture, daily review) built on the official Laravel + Livewire starter kit. The domain layer (models, enums, quick-add parser, recurrence engine) and the whole UI are custom; auth/2FA/passkeys come from the starter kit's Fortify setup.

The section below `<laravel-boost-guidelines>` is auto-managed by `laravel/boost` (regenerated via `composer post-update-cmd` → `php artisan boost:update`) — don't hand-edit inside it. Add project-specific notes above it, here.

## Commands

- `composer setup` — first-time install: composer deps, `.env`, app key, migrate, npm deps, asset build.
- `./start-dev` or `composer dev` — run the app. This project uses **Laravel Valet**, not `php artisan serve` (see the boost guidelines' "valet rules" below); `start-dev` links the Valet site if needed and runs the queue listener, `pail` logs, and `npm run dev` (Vite HMR) concurrently. Without `npm run dev`/`./start-dev` running, the browser serves the last `npm run build` output.
- `composer lint` — `pint --parallel` (auto-fixes style).
- `composer lint:check` — `pint --parallel --test` (check only, CI uses this).
- `composer types:check` — `phpstan analyse` (Larastan, level 7, scoped to `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/` per `phpstan.neon`).
- `composer test` — the full CI gate: `config:clear` → `lint:check` → `types:check` → `php artisan test`.
- `php artisan test --compact` — run the suite without the lint/types gate. Add `--filter=testName` or a path (e.g. `php artisan test tests/Feature/GoalsTest.php`) to run a subset.
- `npm run build` — required after editing Blade/CSS/JS whenever Vite isn't running in dev mode; a stale `public/build` is a recurring source of "the UI looks wrong" reports.

## Architecture

**Route → Livewire page mapping.** `routes/web.php` binds each URL directly to a full-page Livewire single-file component via `Route::livewire('path', 'pages::name')`, e.g. `Route::livewire('objectifs', 'pages::goals')->name('goals.index')`. There are no controllers for these routes — the SFC's PHP block *is* the controller. Nested pages use dot namespaces: `pages::goals.show` resolves to `resources/views/pages/goals/⚡show.blade.php`. Account-settings routes live separately in `routes/settings.php`.

**Livewire SFCs use the ⚡ prefix** (`resources/views/pages/⚡today.blade.php`, `resources/views/components/⚡quick-add.blade.php`) — this is Livewire 4's default `make:livewire` naming; there's no `config/livewire.php` overriding it, so keep using the emoji prefix for new page/component SFCs rather than switching to class-based or MFC without reason.

**Models use PHP 8 attributes instead of legacy properties**: `#[Fillable([...])]` replaces `protected $fillable`, and `#[Table('name')]` is used when the model name doesn't match the table (e.g. `Checklist`/`ChecklistItem` map to the `lists`/`list_items` tables — a deliberate naming split between the DB schema and the domain vocabulary used in code and UI). Follow this pattern for new models rather than reintroducing `$fillable` arrays.

**Domain model**: `Goal` → `Milestone`/`Task`/`Event`/`Note`/`Decision`, with `Entity` (people/orgs/projects) cross-cutting most of those. `InboxItem` is the capture-first, triage-later staging table (see `App\Support\QuickAdd`). `Checklist`/`ChecklistItem` are freeform lists, distinct from `Task`. `Review` drives the periodic review flow. `AiProvider`/`AgentConfig`/`AgentRun` back the `reviewer`/`curateur`/`triage`/`planner` agents on the `/agents` screen (provider/model config, run history, manual + scheduled triggers). `App\Support\Triage\TriageAgent` turns `InboxItem`s into `InboxSuggestion`s (convert to task/event/goal, etc.); `App\Support\Planner\PlannerAgent` generates `PlannerProposal`/`PlannerProposalItem` scheduling proposals for a given period. There used to be a `LifeArea` model above `Goal`/`Entity` in this hierarchy; it was removed (2026-07-29) as unnecessary overhead — don't reintroduce a `life_area_id` column or a domain-of-life grouping concept without a fresh decision to do so.

**`App\Support\QuickAdd\QuickAddParser`** turns one line of free text (the omnipresent quick-add bar, `⚡quick-add.blade.php`) into a structured `QuickAddResult`: it extracts dates, `#tag`-style entity references, `@goal` references, and priority markers via regex, then fuzzy-matches entity/goal names against the DB with `similar_text()`.

**`App\Support\Recurrence\RecurrenceCalculator`** computes the next occurrence for a recurring `Task` from its `recurrence` string (`daily`, `weekly`, `monthly:N`, `yearly:MM-DD`, etc.) — this is plain Carbon date math, no queue/scheduler involved; recurrence advances when a task is completed, not on a cron.

**Vault contract v3 (mémoire v3, it. 7).** Le vault est régi par une ontologie (`App\Support\Brain\Schema\NoteType` : `project, person, org, meeting, note, decision, journal, veille, skill, profil, a-traiter, archive`) et un contrat de frontmatter (`FrontmatterSchema`, `schema: 3` ; `schema`/`type`/`title`/`date` requis, `status` requis pour project/veille/a-traiter). `VaultIndexer` valide chaque document à l'indexation et remplit `brain_documents.schema_version`/`is_conformant`/`note_type`/`status`/`updated_on`. **Règle d'incitation** : les ancres (`brain_document_anchors`) ne sont résolues que pour un document conforme — une note non conforme est indexée en plein texte seulement, donc invisible sur les fiches entité/objectif. `Profil/` et `.hermes/` (territoire d'Hermes, désormais indexé) sont exemptés : ancres toujours résolues, jamais signalés ; idem pour les fichiers générés (`README.md`, `*/_index.md`).

**`App\Support\Brain\VaultWriter` est le SEUL point d'écriture machine du vault** — un `grep File::put app/` ne doit ramener que lui. Tout passe par là (outils MCP, curateur, journal, bilan, édition dans `/cerveau`) et il garantit en un endroit : `pullRebase()` avant d'écrire (conflit → aucune écriture, contenu garé dans `TARS/AAAA-MM-JJ-conflit-*.md` + alerte `brain.last_conflict_at`), frontmatter conforme refusé pour une écriture machine (`writeRaw()` existe pour l'édition humaine, écrite verbatim et non tenue au contrat), commit préfixé déduit de `source` (`hermes:` / `tars:`) via `GitRepository::commitPaths()` — jamais `git add -A`, qui ratisserait les modifications en cours de Thomas dans Obsidian —, réindexation, puis `PushVaultJob`. Le chemin d'une note machine est **toujours** calculé par `NotePathResolver` d'après son type, jamais fourni par l'appelant (`upsert_note` le refuse par construction). `IndexFileGenerator` repose `resources/vault/README.md` (la carte du vault, versionnée ici) et régénère les `_index.md` à chaque `brain:index`, en un commit et seulement si le corps a changé. Le curateur a trois missions : `curator:todo` (horaire), `curator:tidy` (22 h), `curator:conformance` (dimanche 21 h, `--limit=120` soit 3 lots de 40, propositions groupées par `brain_suggestions.batch_id`). **Rien n'est jamais supprimé du vault** : `App\Support\Brain\NoteArchiver` est le seul chemin de sortie d'une note (déplacement sous `Archives/<dossier d'origine>/` avec sa date d'archivage), utilisé par le curateur comme par l'outil MCP `delete_note` — qui archive, malgré son nom. Le journal et les bilans vivent dans `Journal/AAAA/` et `Journal/Bilans/` depuis l'it. 7 ; `brain:migrate-journal` (idempotente, `--dry-run`) a migré les anciens chemins `TARS/Journal/…` et réécrit les `note_path` en base.

**Deux familles d'agents, qui n'ont presque rien en commun.** Les *agents système* (`reviewer`/`curateur`/`triage`/`planner`, écran `/agents`, onglet « Système ») sont des appels HTTP sans outils : `App\Support\Agents\AgentRunner` poste un prompt sur `/v1/messages` et range le texte. Les *missions* (`App\Support\Missions`, écran `/missions`) sont un vrai Claude Code outillé : un `claude --print --output-format stream-json` lancé en sous-process, qui lit des fichiers, appelle les outils MCP de Tars et missionne des sous-agents. Un agent système se configure ; une mission se lance et se regarde tourner. Ne pas faire passer l'un par l'autre.

**`/missions` est une messagerie, et les deux URL rendent le même composant.** `Route::livewire('missions', 'pages::missions')` et `Route::livewire('missions/{mission}', 'pages::missions')` pointent tous deux sur `pages::missions`, dont le `mount(?Mission $mission = null)` ouvre un fil ou laisse la place au nouveau message. Les conversations sont à gauche, le fil à droite (`pages/missions/composer.blade.php` et `thread.blade.php`) : écrire, lire et retrouver sont un seul geste, et il n'y a qu'un fil à maintenir. `?agent=` (depuis le plateau de l'équipe) et `?skill=` (depuis Skills) sont des **passages de relais lus une fois dans `mount()`**, pas des `#[Url]` : accrochés à l'URL ils suivaient la navigation et donnaient `/missions/7?agent=chef`, où le paramètre ne veut plus rien dire.

**Un agent va chercher ses propres skills.** `find_skills` cherche dans les skills installés **et** dans le registre skills.sh ; `read_skill` rend le mode d'emploi **en entier**, qu'il soit installé (lu sur le disque) ou non (`skills use`, qui l'imprime sans l'installer) — l'agent l'applique donc dans le tour même, sans installation ni redémarrage. `install_skill` n'est que pour la suite : rendre le skill disponible aux prochaines missions et visible dans `/skills`. Le système prompt **nomme ces outils** à chaque agent (`MissionCommandBuilder::systemPrompt()`) : un outil qu'on ne nomme pas n'existe pas, l'agent ne parcourt pas sa liste pour se demander s'il saurait mieux faire. À ne pas confondre avec le greffon par mission (`--plugin-dir`), qui charge un skill *choisi d'avance* comme vraie commande slash ; `read_skill` est le chemin de l'agent qui découvre en cours de route.

**Une sentinelle garde la porte de l'agent** (`App\Support\Missions\Sentinel`, `missions.watch_url`). Réveiller un agent coûte **~0,35 $** ; aller chercher une page et comparer une empreinte coûte zéro. Quand la veille a une URL, c'est donc la sentinelle qui fait le guet toutes les N minutes, et l'agent n'est appelé que pour **juger** — ce qu'il est le seul à savoir faire. Mesuré sur une veille de 48 h toutes les 15 min : **67 $ → ~3 $**. L'empreinte est prise sur un texte normalisé (scripts, styles, commentaires, jetons hexadécimaux et horaires ôtés) : sans ça un jeton anti-CSRF suffirait à déclarer un changement à chaque passage et la sentinelle ne garderait plus rien. Trois cas réveillent quand même l'agent : la première empreinte, une page injoignable (c'est peut-être l'événement), et le **battement de cœur** (`watch_heartbeat_skips`, 1 sur 24) — sans lui, une sentinelle posée sur la mauvaise page garderait le silence pour toujours. L'agent pose la sienne avec `set_watch_sentinel` une fois qu'il a trouvé la bonne page ; Hermès peut aussi la donner d'emblée (`start_mission(watch_url:)`).

**Une veille repart périodiquement d'une session neuve** (`watch_fresh_session_every`, 10 ; `missions.session_started_turn`). Reprendre indéfiniment la même session CLI fait traîner tous les passages précédents : au cinquantième réveil l'agent relit quarante-neuf échanges pour dire « toujours rien », et le prix du passage grimpe avec le contexte — **en silence**, puisque rien à l'écran ne le montre. À la rotation, on lui rend le strict nécessaire : la consigne d'origine, le numéro du passage, le dernier état connu. `Mission::resumesSession()` est ce qui décide entre `--resume` et `--session-id` ; le fil affiché, lui, ne se coupe jamais — seul le CLI oublie.

**Une veille est une conversation qui se réveille toute seule** (`missions.watch_*`, commande `missions:watch` à la minute). Ce n'est pas une mission à part : c'est **la même conversation** qu'on relance, donc l'agent se souvient de ses passages précédents, et l'historique reste un seul fil au lieu de quarante missions identiques. Quatre garde-fous, parce qu'une veille dépense pendant qu'on dort : un plancher d'intervalle (`watch_min_minutes`, 15), un plafond de passages, un plafond de **dépense** (`watch_max_usd`, le plus important — mesuré : un passage qui fouille le web coûte ~0,35 $, soit 70 $ pour deux jours à quatre passages/heure), et l'arrêt d'une veille qui n'a jamais réussi. Chaque fin porte sa raison (`WatchEnded`) et l'écran la dit : une veille qui s'éteint en silence laisse croire qu'elle tourne. **L'agent doit savoir qu'il est en veille dès son premier passage** — le système prompt le lui dit (`MissionCommandBuilder::systemPrompt()`), sans quoi il répond « la surveillance n'est pas en place, je n'ai rien programmé » et cherche à créer lui-même une tâche planifiée (vu en vrai).

**`alert_thomas` est le seul chemin sortant.** L'agent l'appelle quand ce qu'il surveille arrive : l'alerte est écrite dans le fil (elle ne se replie **jamais** avec le reste du travail — c'est ce pour quoi la veille existait), la veille s'arrête, et `SendAlertJob` pousse un JSON sur le webhook configuré dans `/reglages`. Un webhook plutôt qu'un canal en dur : Thomas le branche sur son Hermès, n8n ou ntfy. L'outil sait **quelle mission l'appelle** grâce à l'en-tête `X-Tars-Mission` que `MissionCommandBuilder` écrit dans la config MCP de chaque mission (`App\Support\Mcp\CallingMission`) — Hermès, lui, appelle sans en-tête, et l'alerte part quand même.

**Hermès peut missionner l'équipe.** `start_mission` (MCP) confie un travail au chef — et peut en faire une veille (`watch_every_minutes`) — et rend l'URL où Thomas le suivra ; `get_mission` relit l'état, l'étape en cours et la réponse. Une mission ouverte par Hermès porte `missions.source = 'hermes'` — même vocabulaire que le journal et le frontmatter du vault — et l'écran le dit (puce « Hermès » dans la liste, « lancée par Hermès » en tête du fil) : une conversation que Thomas n'a pas ouverte ne doit pas se présenter comme s'il l'avait fait. Les instructions du serveur disent à Hermès de ne **jamais inventer le résultat** d'une mission qui tourne encore, et `get_mission` répond alors par l'étape en cours, pas par un verdict. Évidemment, **rien de tout ça n'est joignable tant que le serveur MCP est éteint** (`/reglages`).

**Un agent porte deux textes, qui ne s'adressent pas au même lecteur.** `team_agents.when_to_use` dit au chef **quand l'appeler** — il devient le `description` du `--agents` JSON, et c'est le **seul** texte qui décide d'un aiguillage ; `role` dit à l'agent **comment travailler** et devient son prompt. Router sur un extrait du rôle (ce qu'on faisait) donnait des choix au hasard, parce qu'un rôle est écrit pour l'agent, pas pour celui qui route. Un agent sans `when_to_use` retombe sur un extrait de son rôle — mieux que rien, mais l'écran Équipe rend le champ obligatoire à la saisie. Le banc se modifie depuis `/equipe` (recruter, modifier, mettre au repos) ; **le chef ne se met pas au repos**, sans lui Thomas n'a plus d'interlocuteur. Attention : `TeamAgentSeeder` est idempotent sur le slug et **écrase les retouches** faites à l'écran — ne le rejouer qu'en connaissance de cause.

**`/equipe` est un plateau vivant : une ligne par agent, rafraîchie toutes les trois secondes.** Elle dit ce qu'il fait *maintenant* — sa dernière étape de **premier niveau** (`currentStep()` écarte les lignes à `parent_tool_use_id` : le travail d'un coéquipier ne doit pas passer pour celui du chef), depuis combien de temps, et ce que ça a coûté. Deux choses ne se lisent nulle part ailleurs. **Les coéquipiers au travail** : un sous-agent n'a pas de ligne dans `missions` — il vit dans le process de celui qui l'a appelé —, donc `delegations()` lit le flux du tour courant (une délégation est ouverte tant qu'aucun `tool_result` ne porte son `tool_use_id`) et le rend indenté sous son chef ; sans ça, trois agents qui tournent derrière un chef « au travail » sont invisibles. **Les veilles armées** : une conversation qui dort entre deux passages n'est pas « au repos », elle va se réveiller et dépenser — la ligne dit son rythme et l'heure du prochain passage. L'ordre du banc ne bouge jamais (la pastille dit qui travaille, pas la position) : sous un `wire:poll`, des lignes qui changent de place pendant qu'on les lit rendent le plateau illisible. Enfin la dépense du jour se compte sur les événements `result`, qui portent le prix d'**un passage** à l'heure où il a eu lieu — sommer `missions.cost_usd` mettrait au compte d'aujourd'hui une veille ouverte la semaine dernière, et tairait ce qu'elle vient de dépenser cette nuit.

**Thomas ne choisit pas son interlocuteur : il écrit au chef** (`TeamAgent::chief()` — le premier agent actif qui délègue, à défaut le premier actif), et c'est au chef de trouver la bonne personne. Il n'y a donc **pas de sélecteur d'agent** à l'écran, ni dans le composeur ni sur le plateau de l'équipe, qui sert à *suivre* et non à répartir. Conséquence à garder en tête : tout passe par le modèle du chef, et une délégation coûte la somme de ses deux agents — changer le chef de modèle change le prix de toutes les conversations.

**Le fil se lit comme une conversation, pas comme un journal de bord.** `turns()` groupe les événements par tour : ce que j'ai demandé (une bulle abricot à droite), ce qu'il a fait (replié derrière « N étapes · Xs », ouvert tant qu'il travaille), et ce qu'il répond (pleine largeur, sans bulle, avec le filet vert de l'agent — le signal « ça vient d'une machine » est gardé, la boîte est tombée). Tant qu'un tour tourne, son dernier texte **n'est pas** une réponse : il reste une étape, sinon l'écran annoncerait une conclusion qui n'en est pas une. `<x-chat-box>` porte la zone d'écriture : Entrée envoie, Maj+Entrée va à la ligne, et elle grandit avec le texte jusqu'à 220px.

**Une mission est une conversation.** Le premier message l'ouvre, et chaque message suivant **reprend la session** (`--resume <session_id>` au lieu de `--session-id`) : le CLI garde le fil, on ne le rejoue pas. D'où le `session_id` que Tars nomme lui-même dès le premier tour. `missions.turn` porte le tour courant, `mission_events.turn` le tour de chaque ligne, et un message de Thomas est une ligne du fil comme une autre (`type: user`, `subtype: prompt`, `MissionEvent::isMine()`) — pas de table à part, le fil se relit dans l'ordre. **On ne parle pas à un agent qui travaille** : tant que la mission est ouverte la zone de saisie est fermée, seul « Arrêter » répond (la reprise ne permet pas d'interrompre, et tenir un process vivant avec son entrée ouverte demanderait un démon). Deux pièges mesurés : le CLI renvoie dans `total_cost_usd` le prix de **ce tour**, pas de la conversation — `MissionEventRecorder` additionne, sinon un deuxième message effacerait le prix du premier ; et un skill ne s'annonce qu'au premier tour, le rappeler rechargerait son mode d'emploi dans une conversation qui l'a déjà.

**Comment une mission tourne.** `MissionLauncher` crée la `Mission` et met `RunMissionJob` sur la file **`missions`** (dédiée : une mission dure des minutes et retiendrait la poussée du vault derrière elle — d'où la 2ᵈ ligne de listener dans `start-dev`). Le job lance `ClaudeProcess`, qui surveille le process plutôt que d'attendre sa fin : c'est ce qui permet d'envoyer un `SIGTERM` quand `missions.cancel_requested` se lève (puis un `SIGKILL` passé 10 s — un agent au milieu d'un appel réseau ignore un SIGTERM). La sortie passe par `StreamJsonParser` (les morceaux de `Process` ne sont pas alignés sur les lignes : tampon obligatoire) puis `MissionEventRecorder`, qui range un `mission_events` par bloc de message et clôt la mission sur l'événement `result`. **Une mission ne tourne que si le worker `missions` tourne** : le CLI lit les identifiants OAuth du trousseau de Thomas, ce que le PHP-FPM de Valet n'a pas — rien n'est jamais lancé depuis la requête web.

**La config de lancement d'une mission est fermée, et c'est mesuré.** Lancé sans précaution, `claude -p` hérite de tous les serveurs MCP et greffons personnels de la machine : **104 445 tokens et 0,42 $ pour faire dire « bonjour »**. `MissionCommandBuilder` passe donc toujours `--strict-mcp-config` (même sans serveur à déclarer) et `--setting-sources project`, ce qui ramène la même mission à ~25 500 tokens et 0,06 $ — le plancher de Claude Code lui-même. Ne jamais ajouter `user` à `--setting-sources` pour « avoir les skills » : ça rouvre la porte à tout le reste. **Un skill se donne par un greffon fabriqué pour la mission** (`storage/app/private/missions/<id>/plugin/`, manifeste + lien symbolique vers le dossier du skill installé), passé en `--plugin-dir` ; il vit alors sous le nom du greffon, et s'active par `/tars-mission:<slug>` en première ligne du prompt — `/<slug>` tout court n'active rien et arrive à l'agent comme du texte. Le prompt est un argument positionnel qui doit rester **en dernier et précédé d'un drapeau à valeur unique** : `--add-dir`, `--mcp-config` et `--tools` sont variadiques et l'avaleraient.

**La visualisation ne reconstruit rien.** `--forward-subagent-text` fait arriver le texte d'un sous-agent avec le `parent_tool_use_id` de l'appel qui l'a engendré : l'arbre CEO → sous-agents de `/missions/{mission}` est lu dans le flux, pas déduit. L'outil de délégation s'appelle `Agent` dans la 2.1 du CLI et `Task` dans la documentation — `MissionEvent::DELEGATION_TOOLS` accepte les deux. `BROADCAST_CONNECTION=log`, donc la vue vivante est un `wire:poll.2s` qui s'arrête dès que la mission est close. Et `SkillCatalog` ne met en cache que des tableaux : des objets `Skill` en cache reviennent en objets incomplets dans le worker, et la mission échoue avant d'avoir commencé.

**Les missions tournent en `--dangerously-skip-permissions`**, par décision explicite de Thomas (2026-10-16), et **Tars n'est pas destiné à tourner sur sa machine** : la cible est un serveur dédié, où un agent en bypass est une posture normale. En conséquence, ne pas raisonner comme si le Mac de Thomas était le terrain — mais se souvenir qu'en dehors de son Mac, `claude` n'a plus son trousseau OAuth (il faudra `ANTHROPIC_API_KEY`) et que le vault comme les dépôts de travail doivent exister là-bas : un agent missionné peut lancer n'importe quelle commande sous son identité. Les trois garde-fous sont le répertoire de travail déclaré par l'agent (`TeamAgent::workspacePath()`, `vault` ou `repo`), le plafond `--max-budget-usd`, et l'arrêt qui envoie un vrai signal. Ce ne sont pas des barreaux — ne pas présenter ce mode comme sûr, et ne pas l'étendre sans en reparler.

**Google Calendar sync** (`/calendrier` page, "Connexions" section on `/reglages`): a single global `GoogleCalendarConnection` row (no per-user scoping, matching `AiProvider`/`Setting`) holds the OAuth tokens. The consent request covers `calendar.events` (read + write on events) and `gmail.readonly` (read-only mailbox, requested but not yet consumed by any code); the scopes Google actually granted are recorded in `granted_scopes` and surfaced per-access on `/reglages` — a `null` list means a connection predating that column, read as calendar-only. `App\Support\GoogleCalendar\GoogleCalendarClient` talks to Google's OAuth + Calendar v3 REST endpoints directly via `Http` (no `google/apiclient` dependency); `GoogleCalendarSyncService` maps `Event` ↔ Google's event JSON and drives both directions. Push is automatic: `#[ObservedBy(GoogleCalendarSyncObserver::class)]` on `Event` dispatches `PushEventToGoogleCalendarJob`/`DeleteGoogleCalendarEventJob` on create/update/delete. Pull is polled by `google-calendar:sync` (`SyncGoogleCalendarCommand`, every 5 minutes via `routes/console.php`), using Calendar API `syncToken` incremental sync with a bounded full-resync fallback on `410 Gone`. Loop prevention: the pull path upserts `Event`s inside `Event::withoutEvents(...)`, and the push path persists `google_event_id`/`google_synced_at` via `forceFill()->saveQuietly()` — both bypass the observer so pulled/pushed writes don't re-trigger a sync in the other direction. `google_event_id`/`google_synced_at` are deliberately **not** in `Event`'s `#[Fillable]` — they're system-managed only.

**Le registre skills.sh.** `/skills` cherche dans le registre public via `App\Support\Missions\SkillRegistry` : `https://skills.sh/api/search?q=` est la **seule** route exposée (ni listing, ni fiche, ni description — un nom, un dépôt, un nombre d'installations ; deux signes minimum). Les descriptions courtes viennent de la balise `<meta name="description">` de la page publique de chaque skill (`https://skills.sh/<dépôt>/<skill>`), déjà tronquée pour un aperçu : `Http::pool` va chercher les pages des résultats ensemble (~1,8 s à froid pour 24, 4 ms ensuite, gardées une semaine ; un échec n'est gardé que dix minutes). La description **entière** se paie à la demande, par `skills use <dépôt>@<skill>` qui imprime le SKILL.md sans l'installer — une seconde par appel, donc jamais pour une liste. Ne pas espérer `raw.githubusercontent.com` : la recherche de chemin du SKILL.md dans un dépôt n'a pas de convention fiable. `describe()` distingue trois issues parce qu'elles n'appellent pas la même décision : la description, « le skill a quitté son dépôt » (fiche de registre périmée, fréquent) et « le CLI n'a pas répondu ». Tous les appels au CLI passent par `SkillsCli`, qui nettoie les séquences de terminal (pas seulement les couleurs : `[?25l`, `[1G` et les lignes réécrites au retour chariot), journalise les échecs, et travaille depuis `base_path()` — sous PHP-FPM le répertoire courant est `public/`.

**Le PATH d'un outil externe se construit, il ne s'hérite pas** (`App\Support\Missions\ShellPath`). Le PATH d'un processus PHP-FPM ne contient pas forcément `/bin` : `npx` y trouvait node — qu'on lui donnait — mais pas le `sh` dont il a besoin, et échouait sur `spawn sh ENOENT` alors que la même commande marchait dans un terminal. `ShellPath::env()` remet les dossiers système en toutes lettres ; `ClaudeProcess` et `SkillsCli` en dépendent tous les deux.

**Ce qu'on met en cache peut revenir d'une version précédente du code.** Deux pannes déjà : des objets `Skill` relus en objets incomplets dans le worker, et une description passée de `?string` à un tableau qui a fait tomber `/skills` en `TypeError` sur un cache tiède. `SkillCatalog` et `SkillRegistry` vérifient donc la forme de ce qu'ils relisent et le jettent s'il ne correspond plus. Les tests partent d'un cache vide et ne verront jamais ça : c'est au code de s'en méfier.

**Six portes, et Hermès à côté** (`App\Support\Navigation\Spaces`, vague 2 de la refonte). La barre latérale nomme six gestes — **Aujourd'hui · À trier · Planning · Objectifs · Mémoire · Recul** — et rien d'autre ; chaque écran reste à son URL et devient un onglet de sa porte (`<x-space-tabs space="…" />`). Les noms disent le geste, pas la table : « À trier » plutôt qu'Inbox, « Planning » plutôt que Faire, « Mémoire » plutôt que Cerveau, « Recul » plutôt que Revue. **« À trier » a sa propre porte** parce que le chiffre qui y attend est le seul de l'app dont je suis le bloquant tous les jours — sous un onglet d'Aujourd'hui, il se cachait. **Hermès n'est pas une septième porte** : il porte `aside` et descend en bas avec les Réglages (on y va pour missionner, pas pour tenir sa journée) ; `Spaces::doors()` et `Spaces::aside()` sont les deux groupes de la sidebar. Une liste est passée de Planning à Mémoire : c'est de la matière qu'on retrouve, pas du temps qu'on place. En mobile, cinq onglets (`short` sert là : « Jour », pas « Aujourd'hui ») et « Plus » porte Objectifs, Recul et le compte. Deux écrans du doc n'existent pas et ne sont **pas** simulés : « Planning · Semaine » (il n'y a pas de vue semaine) et la matrice de permissions d'Hermès dans les Réglages (aucune permission en base).

**Deux pièges de date, et ils se contredisent.** `AppServiceProvider` fait `Date::use(CarbonImmutable::class)` : `now()`, `today()` et les attributs castés rendent donc des dates **immuables**. Mais `Carbon::createFromFormat()` appelé sur `Illuminate\Support\Carbon` rend une date **mutable**. Conséquence vécue sur le Planning : `for ($c = $from; $c->lte($to); $c->addDay())` boucle à l'infini sur une date immuable, et `$c = $c->addDay()` sans copie **mutait `$from`** sur une date mutable — le mois comptait zéro événement partout. La forme qui marche dans les deux cas : partir d'une **copie** et **réassigner** (`for ($c = $from->copy(); $c->lte($to); $c = $c->addDay())`), et pousser `$c->copy()` quand on range la date dans un tableau. Même raison pour les types de retour : une méthode qui rend `today()` doit annoncer `CarbonInterface`, pas `Carbon`.

**Le rythme d'un objectif se lit, il ne se saisit pas** (`App\Support\Goals\GoalRhythm`). « Bouger » veut dire : une tâche cochée, une tâche posée, un jalon atteint, une note écrite — pas un renommage. `lastActivityFor()` fait quatre requêtes pour toute une page d'objectifs (jamais quatre par objectif) et `movedLabel()` rend la phrase (« a bougé lundi », « 3 sem. sans mouvement ») ; la carte met en avant la seconde forme seulement, en blé, **jamais en rouge**. Deux attributs déduits plutôt que deux colonnes de plus : `Goal::$horizon_label` (« ce trimestre », « cette année ») se lit dans `target_date`, et le silence d'une entité se compte sur son historique — le doc de refonte posait un *domaine de vie* et un *horizon* à saisir, et `LifeArea` a justement été retiré en juillet 2026.

**`request()->routeIs()` ment dans un composant Livewire.** Un rendu Livewire — un clic, une validation, un `wire:poll` — arrive sur la route `livewire.update` : aucune route de page ne correspond plus, et tout ce qui se conditionnait à la route courante s'éteint au premier rafraîchissement. C'est ce qui faisait **disparaître la barre d'onglets** (`<x-space-tabs />`) de l'écran au bout de cinq secondes sur `/missions`. Deux règles depuis : une page **déclare son espace** (`<x-space-tabs space="hermes" />`), la présence des onglets ne dépend donc plus d'une introspection de requête ; et tout ce qui doit savoir « quelle page regarde-t-on » passe par `App\Support\Navigation\CurrentRoute::is()`, qui retrouve le chemin d'origine dans l'instantané Livewire (`Livewire::originalUrl()`) et le repasse au routeur. `request()->routeIs()` reste correct dans le gabarit (barre latérale, barre du bas), qui n'est rendu que sur un vrai chargement de page.

**La typographie vient des maquettes, pas d'une direction parallèle.** Une seule famille, **Manrope**, en 400 à 800 (`vite.config.js`, servie par Bunny) : elle porte les titres comme le corps, exactement comme le doc de refonte. L'app a longtemps eu deux familles — Outfit pour les titres, Karla pour le reste — et ça ne rendait tout simplement pas le même écran. `--font-display` reste un token distinct parce que des centaines de classes s'y appuient, mais pointe sur Manrope. L'échelle est celle des maquettes, mesurée dessus : titre d'écran **28 / 800 / -.02em / 1,2**, titre de lecture (Revue, Bilan) **36 / 800 / -.03em / 1,15**, titre de carte **20 / 700 / -.01em**, en-tête de carte **16 / 700**, corps de lecture **17 / 1,8** (`.reading-body`), micro-libellé en capitales **11-12 / 700 / .08em** (`.kicker`, défini une fois — sans lui ces quarante libellés retombaient à 400). IBM Plex Mono survit pour les seuls chemins (arborescence exclue : c'est de la navigation).

**Theming is a custom CSS-variable design system, not Tailwind's `dark:` variant.** Tokens live in `resources/css/app.css` under `[data-theme='dark']`/`[data-theme='light']` attribute selectors on `<html>`. The active theme is persisted in a `la-theme` **cookie** (not localStorage) and read server-side in `resources/views/layouts/app/sidebar.blade.php` so the theme can be rendered directly into the HTML `wire:navigate` fetches — this exists specifically to avoid a flash-of-wrong-theme during Livewire's View Transition page swaps. Because the cookie is written by raw client-side JS rather than Laravel's `Cookie` facade, it's excluded from encryption in `bootstrap/app.php` (`$middleware->encryptCookies(except: ['la-theme'])`); if you add another client-set cookie, remember to exclude it too or `request()->cookie(...)` will silently return `null` for it.

**Testing**: Pest only auto-applies `RefreshDatabase` to `tests/Feature` (see `tests/Pest.php`); `tests/Unit` (e.g. `RecurrenceCalculatorTest`) runs against plain `PHPUnit\Framework\TestCase` with no database.

**Local dev and tests run on SQLite (`DB_CONNECTION=sqlite`); production runs on MySQL.** SQLite doesn't enforce MySQL's 64-character identifier limit, so a migration can pass `composer test` and work fine locally while still failing to deploy with `Identifier name '...' is too long` the moment it hits MySQL — this bit us once with an auto-generated multi-column unique-index name on `entity_relations` (`entity_relations_entity_id_related_entity_id_relation_type_unique`, 65 chars). When adding a `unique([...])` or multi-column index across more than two columns, or on a table with a long name, pass an explicit short name as the second argument (e.g. `$table->unique([...], 'entity_relations_unique')`, matching the existing `brain_document_anchors_unique` precedent) instead of relying on Laravel's auto-generated `{table}_{col1}_{col2}_..._unique` name. If in doubt, count the characters or test the migration against a real MySQL instance before considering it done — SQLite passing is not sufficient proof for this specific failure mode.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== valet rules ===

# Laravel Valet

- The application is served by Laravel Valet at `http://lifeassistant.test`. Never run `php artisan serve` — Valet serves the site continuously once it's linked (`valet link`) and Valet's own services are running.
- Use the `valet` CLI to manage sites (`valet links`, `valet open`, `valet secure`, `valet park`). Most `valet` commands require sudo and an interactive terminal, so run them yourself rather than asking the assistant to run them.
- `npm run dev` (or `./start-dev`) still needs to run separately for Vite hot-reloading; without it, Laravel falls back to the last `npm run build` output in `public/build`.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
