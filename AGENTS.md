# Repository Guidelines
Do not use Sub-agents for this task. Unless you are stuck and ask me for permissions first.
## Project Structure & Module Organization
- `OnCoreIntegration.php` is the main External Module entry point and hook implementation.
- `classes/` contains the core PHP classes for clients, protocols, subjects, mapping, and entities.
- `ajax/` holds request handlers used by the REDCap UI (e.g., `ajax/handler.php`).
- `pages/` contains UI pages rendered inside REDCap (mapping, sync, logs, etc.).
- `assets/` holds JS/CSS, images, and REDCap template exports under `assets/data_dictionary/`.
- `logs/` contains JSONL logs produced by the module (treat as sensitive).

## Build, Test, and Development Commands
- No build system is defined for this module; it runs inside REDCap as an External Module.
- Optional syntax check for PHP changes:
  - `php -l OnCoreIntegration.php`
- REDCap cron drives scheduled sync via `ajax/cron.php`; validate changes using a test project.

## Coding Style & Naming Conventions
- PHP files use 4-space indentation and class/method names in `StudlyCase`; constants in `UPPER_SNAKE_CASE`.
- Follow existing patterns in `classes/*.php` for method organization and property usage.
- JavaScript and CSS live under `assets/`; keep filenames descriptive (`*_modal.js`, `*_mapping.css`).

## Testing Guidelines
- No automated test framework is present in this repository.
- Validate behavior by exercising the REDCap UI pages in `pages/` and performing a sync run.
- When modifying mapping or sync logic, confirm expected updates in the `logs/` JSONL output.

## Commit & Pull Request Guidelines
- Recent commit messages are short, sentence-style summaries (e.g., “add flag to prevent re-loading…”).
- Use concise, imperative summaries; include a trailing period if you follow existing history.
- PRs should describe user-facing impact, REDCap/OnCore versions tested, and any config changes.

## Security & Configuration Tips
- OnCore API credentials are configured in REDCap External Module system settings, not in code.
- Do not commit secrets or institution-specific URLs; keep `config.json` and logs free of PHI.
