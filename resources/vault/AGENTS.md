# AGENTS.md — le règlement du cerveau

> **Un seul cerveau, un seul règlement.** Ce fichier remplace les trois textes
> qui se contredisaient : l'ancien `AGENTS.md`, `00 - HOT/regles.md` (section
> mémoire) et le `README.md` que TARS reposait ici toutes les quinze minutes.
> Il régit ce dépôt pour **toute** application qui s'y branche — Obsidian,
> Hermès, TARS, et les suivantes.
>
> Décision du 23/10/2026 : **un dépôt unique**. Révoque la séparation
> « Contexte = référence / TARS = vivant » actée le 31/08/2026.

## 1. Les trois règles qui tranchent tout le reste

**1. Le frontmatter dit ce qu'est une note. Le dossier dit seulement où on la
range.** C'est le frontmatter qui est lu par les machines (`type:`), jamais le
chemin. Une application qui écrit un frontmatter conforme est comprise
immédiatement, quel que soit le dossier qu'elle a choisi. C'est ça, et rien
d'autre, qui empêche d'avoir cinquante versions du même cerveau.

**2. Une chose, une note, un endroit.** Jamais deux notes sur le même sujet.
Si une information en concerne deux, elle vit dans une seule et l'autre
**pointe** (`related:`). Un cerveau devient un bordel par duplication, pas par
volume.

**3. Daté ou vivant, il faut choisir.** Si l'information se *corrige* →
vivant : un fichier, réécrit en place, chemin stable. Si elle s'*ajoute* →
daté : un fichier par fois, immuable, empilé. Un fichier vivant qu'on empile
devient un journal illisible ; un fichier daté qu'on réécrit perd l'historique.

## 2. Ce qu'une IA lit au démarrage

Dans cet ordre, et **jamais tout le dépôt** :

1. `AGENTS.md` — ce fichier.
2. `00 - HOT/etat-present.md` — l'état daté du moment.
3. `00 - HOT/regles.md` — les règles absolues.
4. `00 - HOT/profil.md` — qui est l'utilisateur.
5. `get_today()` / `get_context()` — la vie courante structurée (tâches,
   agenda, objectifs). En cas de divergence avec un fichier, **la base gagne**.

Le reste se trouve à la demande : `search_brain` (la requête est découpée en
mots, les accents repliés, les résultats classés — une question en langage
naturel fonctionne), puis `read_note` avec le chemin rendu.

Le numérotage des dossiers (`00`, `01`, …) **est** l'ordre de lecture : il n'a
pas d'autre fonction, et c'est la raison de le garder.

## 3. L'arborescence

| Dossier | Ce qu'il contient | `type:` |
|---|---|---|
| `00 - HOT/` | le présent : règles, profil, état, index. Une page max chacun | `profil` |
| `01 - PROJETS/<slug>/` | un dossier par projet : `fiche.md` vivant, `AGENTS.md`, specs | `project` |
| `02 - SYSTEME/` | infra, accès, pipeline, config | `note` |
| `03 - DECISIONS/` | `decision-log.md` — **un seul fichier**, en ajout, tagué `[projet]` | `decision` |
| `04 - MEMOIRE-AGENTS/snapshots/` | fin de session, daté | — |
| `05 - ARCHIVES/` | ce qui est terminé, avec sa date d'archivage | `archive` |
| `06 - PERSO/` | vie privée : `BIENS/ FAMILLE/ FINANCES/ SANTE/ SOCIETES/` | `note` |
| `07 - IDEES/` | `idees.md` — backlog, avec statut | — |
| `08 - INBOX/` | dépôt libre, **objectif : vide** | `a-traiter` |
| `09 - GENS/` | une fiche vivante par personne et par organisation | `person`, `org` |
| `10 - SAVOIR/` | connaissance vivante, une note par sujet | `note` |
| `11 - JOURNAL/AAAA/` · `11 - JOURNAL/Bilans/` | un fichier par jour, un par bilan | `journal` |
| `12 - VEILLE/<sujet>/` | daté, un passage par fichier | `veille` |
| `13 - REUNIONS/` | daté, `AAAA-MM-JJ-sujet.md` | `meeting` |
| `14 - SKILLS/<categorie>/<nom>/` | `SKILL.md` | `skill` |

Les dossiers `09` à `14` sont **nouveaux** : ils sont ce qu'il manquait pour
que la vie courante tienne dans ce dépôt plutôt que dans un second.

`_index.md` dans un dossier est **généré** — sommaire avec titre, type, statut,
fraîcheur, lien. Ne jamais l'éditer. Il n'est créé que pour un dossier qui
contient des notes.

## 4. Nommage

- **Fichiers en kebab-case**, sans accents : `fiscalite-sarl.md`.
- **Dates en ISO**, toujours : `2026-10-23`. Un fichier daté commence par sa
  date : `2026-10-23-point-hubspot.md`. Ça trie à l'œil et par la machine.
- **Le titre est l'index.** Le `title:` du frontmatter doit se comprendre seul,
  hors de son dossier, et contenir les mots qu'on taperait pour le chercher.
  `Spreak — Concurrence` ✅ · `fiche.md`, `marche.md`, `spec-produit` ❌ — ces
  trois-là existent aujourd'hui et sont introuvables.
- **Un H1 dans chaque fichier**, identique au `title:`. Sans lui, le titre
  indexé retombe sur le nom de fichier.
- **Jamais de `DIVERS/`, `MISC/`, `AUTRES/`.** Une chose a sa place, on la lui
  crée, ou on décide qu'elle ne vaut pas d'être gardée.
- **Pas de `_template/` indexé** : les gabarits vivent hors du dépôt ou dans un
  dossier masqué (`.templates/`). Aujourd'hui `<Nom du projet>` sort en
  résultat de recherche.

## 5. Le contrat de frontmatter (v3)

Tout fichier commence par ça. `schema`, `type`, `title`, `date` sont
**obligatoires** ; `status` l'est pour `project`, `veille` et `a-traiter`.

```yaml
---
schema: 3
type: meeting          # project|person|org|meeting|note|decision|journal|veille|skill|profil|a-traiter|archive
title: "Point HubSpot avec Marc"
date: 2026-10-16       # l'événement, ou la création
updated: 2026-10-23    # dernière modification de fond
status: active         # active|paused|done|archived
project: "spreak"      # slug du projet, si la note en dépend
entity: ["Marc", "SARL Alpha"]   # noms naturels, résolus en fuzzy
goal: "500k CA"
tags: [crm, integration]
source: hermes         # thomas|hermes|agent-<nom>|connector-<nom>
related: ["[[fiscalite-sarl]]"]
---
```

**Ce que coûte une note non conforme :** elle est indexée en plein texte, mais
ses ancres `entity:`/`goal:` ne sont pas résolues — elle n'apparaît donc sur
aucune fiche entité ni objectif. Le curateur propose sa complétion par lots.

Exempts : `Profil/`, `.hermes/`, et les fichiers générés (`_index.md`).

## 6. Pour que ça tienne à l'échelle

1. **Promotion** : un fichier au-delà de ~200 lignes devient un dossier. Une
   idée validée monte en projet, et l'entrée d'`idees.md` pointe vers elle.
2. **Fan-out borné** : aucun dossier plat au-delà de ~100 notes. Au-delà, on
   shard sur un axe stable — l'année pour du daté, le sujet pour du vivant.
   Raison concrète : un `_index.md` est tronqué à 200 lignes quand un agent le
   lit, donc un dossier de 500 notes est **invisible**.
3. **`00 - HOT` court pour toujours.** L'ancien état part en
   `04 - MEMOIRE-AGENTS/snapshots/`.
4. **Un seul decision-log**, global, tagué par projet. Pas de log par projet.
5. **Une note = un sujet.** Une note qui couvre cinq sujets matche mal sur les
   cinq et ne tient pas dans un extrait de recherche.

## 7. Ce qu'on ne fait jamais

- **Supprimer.** Jamais, par aucun chemin : on archive (`05 - ARCHIVES/`, avec
  `archived:`). Aucun outil MCP ne supprime.
- **Écraser la porte d'entrée.** `README.md` et `AGENTS.md` à la racine
  appartiennent au dépôt, pas à l'une des applications qui y écrit. Aucune
  machine ne les régénère.
- **Inventer un dossier de haut niveau** sans l'ajouter à ce tableau.
- **Écrire dans `.hermes/`** quand on n'est pas Hermès.
- **Choisir son chemin soi-même** quand on écrit via MCP : il est calculé
  d'après le `type`.
- **Écrire « plus tard ».** Une décision, une préférence, un token se notent
  sur le moment.
- **Exposer `06 - PERSO/`** hors du dépôt.

## 8. Les indicateurs de santé

1. `08 - INBOX/` proche de zéro.
2. Zéro note non conforme hors zones exemptées.

Le curateur les surveille, `/cerveau` les affiche, la revue hebdo les rappelle.
