> **Maintenu par TARS et Thomas.** Modifications de fond : dans le repo TARS,
> `resources/vault/README.md`. Ce fichier est reposé dans le vault à chaque
> réindexation — l'éditer ici serait écrasé.

# Le vault — la seconde mémoire de Thomas

Ce dépôt est la mémoire longue de Thomas : ce qu'il sait, qui il connaît, ce
qu'il a décidé, ce qu'il suit. Il est écrit par trois mains, et **git est le
seul mécanisme de synchronisation** entre elles :

| Qui | Par quoi | Où |
|---|---|---|
| **Thomas** | Obsidian | partout |
| **Hermes** | les outils MCP de TARS (`upsert_note`, `add_note`) | partout, via TARS |
| **les agents TARS** | le service vault de TARS | partout, via TARS |

TARS commit et pousse après chaque écriture machine. Il fait un `pull --rebase`
**avant** d'écrire : en cas de divergence, il n'écrase rien — il garde le
contenu dans le sas (`TARS/`) et prévient Thomas.

## Par où commencer quand on est une IA

Dans cet ordre, et pas un autre :

1. **`README.md`** — ce fichier : les règles et la carte.
2. **`Profil/`** — qui est Thomas (`perso.md`, `pro.md`, `preferences.md`, `capacite.md`).
3. **`Projets/_index.md`** — ce qui est en cours, puis le `README.md` du projet concerné.
4. Le dossier correspondant au besoin (`Personnes/`, `Réunions/`, `Notes/`…), via son `_index.md`.

Chaque dossier structurant porte un `_index.md` **généré par TARS** : titre,
type, statut, dernière mise à jour, lien. C'est le sommaire — ne jamais
l'éditer à la main, il est réécrit à chaque réindexation.

Pour chercher sans savoir où regarder : `get_memory_map` (ce fichier + les
index), puis `search_brain` (plein texte), puis `read_note`.

## L'ontologie — les types de choses dont TARS se souvient

| Type | Vit où (structuré) | Vit où (texte) | Exemple |
|---|---|---|---|
| **Projet** | table `projects` | `Projets/<slug>/` | « Spreak — intégration HubSpot » |
| **Objectif** | `goals` | ancre `goal:` | « 500k CA » |
| **Entité** (personne, organisation, lieu, outil) | `entities` | `Personnes/`, `Organisations/`, ancre `entity:` | « Marc », « SARL Alpha » |
| **Tâche / Événement / Liste** | `tasks`, `events`, `lists` | — jamais dans le vault | |
| **Décision** | `decisions` | `Décisions/AAAA-MM-JJ-sujet.md` | |
| **Note datée** (réunion, appel) | — | `Réunions/AAAA-MM-JJ-sujet.md` | |
| **Note vivante** (connaissance) | — | `Notes/sujet.md` | « fiscalité SARL » |
| **Journal** | `journal_entries` | `Journal/AAAA/` | exclu de `get_context` |
| **Veille** | — | `Veille/<sujet>/AAAA-MM-JJ.md` | |
| **Skill** | lu depuis le disque | `Skills/<categorie>/<nom>/SKILL.md` | |
| **Profil** | — | `Profil/` | sacré, relu chaque mois |
| **Sas** | — | `TARS/` | objectif : vide |
| **Archives** | — | `Archives/` | |

Deux principes qui tranchent la plupart des cas :

- **Le dossier dit la nature, l'ancre dit le sujet.** Une note mal rangée mais
  ancrée reste retrouvable ; l'inverse non.
- **Daté ou vivant ?** Si l'information change la semaine prochaine et que je
  *corrige* la note → vivant, un seul fichier réécrit en place. Si j'en
  *écris une autre* → daté, immuable, empilé.

## Où écrire, selon le type

Le chemin est **toujours calculé par TARS**, jamais choisi par l'appelant. Pour
mémoire, voici la règle qu'il applique :

| `type` | Dossier | Nommage |
|---|---|---|
| `project` | `Projets/<slug>/` | `README.md` (le brief vivant) |
| `person` | `Personnes/` | `<slug>.md` — vivant |
| `org` | `Organisations/` | `<slug>.md` — vivant |
| `meeting` | `Réunions/` | `AAAA-MM-JJ-<slug>.md` — daté |
| `note` | `Notes/` | `<slug>.md` — vivant |
| `decision` | `Décisions/` | `AAAA-MM-JJ-<slug>.md` — daté |
| `journal` | `Journal/AAAA/` | `AAAA-MM-JJ.md` — un par jour |
| `veille` | `Veille/<sujet>/` | `AAAA-MM-JJ-<slug>.md` — daté |
| `skill` | `Skills/<categorie>/<nom>/` | `SKILL.md` |
| `profil` | `Profil/` | `<slug>.md` — vivant |
| `a-traiter` | `TARS/` | brut, à trier — objectif : dossier vide |
| `archive` | `Archives/` | le fichier d'origine, avec sa date d'archivage |

Une note datée écrite deux fois le même jour ne remplace pas la première : elle
est suffixée (`-2`, `-3`). Une note vivante réécrite est une **mise à jour** :
même chemin, corps remplacé, `date` d'origine conservée, `updated` rafraîchie.

## Le contrat de fichier (frontmatter v3)

Tout fichier du vault commence par un frontmatter YAML. `schema`, `type`,
`title` et `date` sont **obligatoires** ; `status` l'est pour `project`,
`veille` et `a-traiter`.

```yaml
---
schema: 3                      # version du contrat
type: meeting                  # project|person|org|meeting|note|decision|journal|veille|skill|profil|a-traiter|archive
title: "Point HubSpot avec Marc"
date: 2026-10-16               # l'événement, ou la création
updated: 2026-10-16            # dernière modification de fond
status: active                 # active|paused|done|archived
project: "spreak-hubspot"      # slug du projet, si la note en dépend
entity: ["Marc", "SARL Alpha"] # noms naturels — TARS les résout en fuzzy
goal: "500k CA"
tags: [crm, integration]
source: hermes                 # thomas|hermes|agent-<nom>|connector-<nom>
related: ["[[fiscalite-sarl]]"]
---
```

**Ce que coûte une note non conforme :** TARS l'indexe en plein texte, mais ne
résout pas ses ancres — elle n'apparaît alors sur aucune fiche entité ou
objectif, et le curateur propose sa complétion. C'est volontaire : le contrat
doit être le chemin le plus court.

Exempts du contrat : `Profil/` (rédigé à la main, par Thomas) et `.hermes/`
(la mémoire technique d'Hermes). Toujours indexés, ancres résolues, jamais
signalés.

## Ce qu'on ne fait jamais

- **Supprimer.** Jamais, par aucun chemin. On archive (`Archives/`, avec
  `archived:`). Aucun outil MCP ne supprime de note.
- **Écrire dans `.hermes/`** quand on n'est pas Hermes : c'est son territoire,
  le curateur n'y touche pas.
- **Écrire hors du sas quand on est un connecteur.** Un connecteur (mail,
  calendrier) dépose des *candidats* dans `TARS/` en `a-traiter` avec
  `source: connector-<nom>` ; le curateur et le triage font le reste.
- **Choisir un chemin soi-même** quand on écrit via MCP : c'est TARS qui le
  calcule, d'après le `type`.
- **Éditer un `_index.md`** : il est généré.

## Les deux indicateurs de santé

1. **Le sas `TARS/` proche de zéro** — comme une inbox.
2. **Zéro note non conforme** hors zones exemptées — donc zéro note sans ancre
   là où une ancre a du sens.

Le curateur les surveille (mission conformité, chaque dimanche et à la
demande), `/cerveau` les affiche, la revue hebdo les rappelle.
