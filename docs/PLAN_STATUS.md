# Plan status

`scripts/plan-status.php` in the application root lists the development plans of a repository, or of a whole workspace, with how far each one is. It is developer tooling: it reads plans, specs and TODO markers and never edits them.

```bash
composer run plan-status                         # or: php scripts/plan-status.php
composer run plan-status -- --open               # only plans that are not done
composer run plan-status:test                    # the bash test harness (needs PHP 8.5)
```

## What it reports

For every plan in `docs/superpowers/plans/`, `.cursor/plans/` and `Modules/*/docs/plans/` it reports the title, the steps (`- [ ]` open, `- [x]` done, `- [-]` cancelled), the state of each task and of the plan, and the dates and modules described below. A plan's YAML front-matter `status:` (`completed`, `in-progress`, `open`, `superseded`) overrides its checkbox counts; when the two disagree the plan carries an inconsistency message. A cancelled step counts as resolved but not done, and its reason stays in the plan.

It also lists the specs in `docs/superpowers/specs/` that no plan mentions, and, when asked, the TODO / FIXME markers in source files (`--kind=plans,specs,todos`).

## Options

| Option | Effect |
|---|---|
| `--root=<path>` | Where to scan from. Default: the application repository. Point it at the parent folder of a multi-repository workspace to cover every repository in it; repositories are discovered, not declared. |
| `--open` | Only work that is not done. |
| `--sort=date\|repo\|progress\|updated` | Order the plans: oldest first (default), grouped by repository, least advanced first, or last changed first. |
| `--format=table\|json\|html` | Output format. `json` is the machine-readable form every consumer reads. |
| `--git-dates` | Add `started` and `finished` to every plan, read from git history. |

`--plan=<substring>`, `--repo=<name>`, `--kind`, `--summary`, `--out=<path>` and `--no-evidence` are described by `php scripts/plan-status.php --help`.

## Dates

Every plan has `date`, from its file name, and `updated`, the date of the last commit that touched the file (its modification date when the working copy has uncommitted changes or git does not track it). `--git-dates` adds two more, all as `YYYY-MM-DD` committer dates:

| Field | Meaning |
|---|---|
| `started` | The first commit that added a ticked step (`- [x]` or `- [X]`) to the plan. |
| `finished` | The last commit that added a ticked step, set only when the plan state is `done`; `null` while the plan is open. |
| `updated` | The last commit that touched the plan, ticked or not. This is the last activity. |

`started` and `finished` are `null` when git does not know the file (a plan written and never committed), and also when no commit has ticked a step yet. The history is read with `git log -p` in the repository that owns the plan, so a plan inside a module submodule (`Modules/SAO/docs/plans/x.md`) is read from the module's own repository. A step unticked and ticked again keeps the first tick as `started` and moves `finished` to the last one.

One caveat: `finished` is the last commit that added a `- [x]` line. A later commit that rewords or reflows an already ticked step adds that line again, and moves `finished` to its date.

## Modules

Every plan in the JSON carries `modules`, a non-empty list of lowercase slugs (`core`, `cms`, `ai`, `erp`, `mes`, `sao`, `platform`, ...). The first rule that matches wins:

1. The front-matter `module:`, one slug or a comma list (`module: ai, core`). Entries are trimmed and lowercased; a slug that is not a known module is kept as written.
2. The plan's own location, `Modules/<Name>/docs/plans/`.
3. The `Modules/<Name>/` paths named by the files of its tasks, ranked by how many files name each module, ties by first appearance.
4. `platform`, when none of the above shows a module.

Set `module:` in the front matter when a plan's files do not show its module, otherwise it reads as `platform`.

## Public website

The roadmap of the public website groups plans by module and shows when each one started and finished. It reads `php scripts/plan-status.php --format=json --git-dates`, so the field names above (`modules`, `started`, `finished`, `updated`, `state`) are a contract: renaming one breaks the site.
