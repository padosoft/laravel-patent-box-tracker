---
name: patent-box-enterprise
description: Continue or resume enterprise-grade API implementation for laravel-patent-box-tracker using macro branches, subtask PR loops, Copilot review gates, and durable progress/lesson logs.
---

# Patent Box Enterprise Skill

## Read First

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `AGENTS.md`
6. `CLAUDE.md`

## Mandatory Workflow

1. Confirm active branch and worktree status.
2. Pick current macro task from `docs/ENTERPRISE_PLAN.md`.
3. Create a subtask branch from the macro branch.
4. Implement one coherent slice.
5. Run local gates.
6. Update `docs/PROGRESS.md`.
7. Open PR to macro branch.
8. Request Copilot review and verify it exists.
9. Loop on review comments and CI until clean.
10. Merge and continue.
11. Immediately start the next roadmap block without waiting for extra user confirmation.

## Copilot Review

If `gh pr edit <PR> --add-reviewer @copilot` fails because of CLI scope or user resolution, use `.claude/skills/copilot-pr-review-loop/SKILL.md` GraphQL fallback with `copilot-pull-request-reviewer[bot]`.

## Quality Gates

- Package backend/API: `composer validate --strict --no-check-publish`, `composer test`
- Add additional checks required by the touched surface.
- UI tasks in other repos require Playwright.

## Documentation Discipline

- Keep `docs/PROGRESS.md` current with branch/PR/CI/Copilot status.
- Add reusable findings to `docs/LESSON.md`.
- Reflect durable discoveries back into rules and skills.
