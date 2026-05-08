# Laravel Patent Box Tracker Agent Guide

This repository is the reusable package `padosoft/laravel-patent-box-tracker`.

If a session restarts with missing context, read these files first, in this order:

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `.claude/skills/patent-box-enterprise/SKILL.md`
6. `.claude/skills/copilot-pr-review-loop/SKILL.md`

## Stable Baseline

- The package currently exposes Patent Box flows via fluent API, collectors, classifiers, renderers, Eloquent models, and Artisan commands.
- API HTTP layer is planned but not yet shipped.
- Existing CLI and fluent behavior must remain backward compatible while API work is introduced.

## Operating Rules

- Track work with macro branches and subtask PRs.
- Update `docs/PROGRESS.md` after every meaningful step.
- Update `docs/LESSON.md` after reusable discoveries, especially Copilot/CI findings and tooling workarounds.
- Keep package code standalone-agnostic: no product-specific dependencies in `src/`.
- Keep compatibility with Laravel 12/13 and PHP 8.3+ unless a macro task explicitly changes the matrix.
- Never leak secrets in logs, docs, API responses, debug payloads, or test fixtures.
- Do not mark a task as done until local tests are green, PR review loop is complete, and merge is done.
- Do not pause between roadmap blocks waiting for new user confirmation; continue automatically block-by-block until full roadmap completion or a hard external blocker.

## Branch And PR Loop

Macro branches for the API program:

- `task/api-enterprise-bootstrap`
- `task/api-foundations`
- `task/api-read-models`
- `task/api-write-jobs`
- `task/api-security-hardening`
- `task/api-contract-tests-docs`
- `task/release-readme-tag`

For each subtask:

1. Create subtask branch from current macro branch.
2. Implement one coherent slice.
3. Run local gates.
4. Open PR from subtask branch into macro branch.
5. Request GitHub Copilot Code Review.
6. Wait for Copilot review and CI checks.
7. Fix all actionable comments and failing checks.
8. Repeat until both review and CI are clean.
9. Merge subtask PR into macro branch.
10. When all subtasks are merged, open macro PR to `main` and run the same loop.

Copilot review means GitHub Copilot Code Review. If `gh pr edit <PR> --add-reviewer @copilot` fails before requesting reviewer (common when token lacks `read:project`), use the GraphQL fallback documented in `.claude/skills/copilot-pr-review-loop/SKILL.md`.

## Local Gates

For package-only backend work:

```bash
composer validate --strict --no-check-publish
composer test
```

When frontend/UI exists in a related repo:

```bash
npm run test
npm run build
npm run e2e
```

Playwright is mandatory only for UI/UX tasks.

## Remote Blockers

If GitHub/Copilot/CI checks cannot be verified in the current session, do not fake completion. Record exact blocker and next remote action in `docs/PROGRESS.md`.
