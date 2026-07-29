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

**Domain model**: `Goal` → `Milestone`/`Task`/`Event`/`Note`/`Decision`, with `Entity` (people/orgs/projects) cross-cutting most of those. `InboxItem` is the capture-first, triage-later staging table (see `App\Support\QuickAdd`). `Checklist`/`ChecklistItem` are freeform lists, distinct from `Task`. `Review` drives the periodic review flow. `AiProvider`/`AgentConfig`/`AgentRun` back the `reviewer`/`curateur` agents on the `/agents` screen (provider/model config, run history, manual + scheduled triggers) — `triage`/`planner` remain unavailable placeholders. There used to be a `LifeArea` model above `Goal`/`Entity` in this hierarchy; it was removed (2026-07-29) as unnecessary overhead — don't reintroduce a `life_area_id` column or a domain-of-life grouping concept without a fresh decision to do so.

**`App\Support\QuickAdd\QuickAddParser`** turns one line of free text (the omnipresent quick-add bar, `⚡quick-add.blade.php`) into a structured `QuickAddResult`: it extracts dates, `#tag`-style entity references, `@goal` references, and priority markers via regex, then fuzzy-matches entity/goal names against the DB with `similar_text()`.

**`App\Support\Recurrence\RecurrenceCalculator`** computes the next occurrence for a recurring `Task` from its `recurrence` string (`daily`, `weekly`, `monthly:N`, `yearly:MM-DD`, etc.) — this is plain Carbon date math, no queue/scheduler involved; recurrence advances when a task is completed, not on a cron.

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
