# Laravel Patent Box Tracker Rules

## Source Of Truth

- Master plan: `docs/ENTERPRISE_PLAN.md`
- Handoff log: `docs/PROGRESS.md`
- Reusable discoveries: `docs/LESSON.md`
- Agent entrypoints: `AGENTS.md`, `CLAUDE.md`
- Core skills: `.claude/skills/patent-box-enterprise/SKILL.md`, `.claude/skills/copilot-pr-review-loop/SKILL.md`

## Core Engineering

- Preserve existing package behavior unless task explicitly changes it.
- Prefer extracting reusable actions/services over duplicating command logic.
- Keep API opt-in through config.
- Keep route/version contracts explicit and tested.
- Keep error shapes deterministic and UI-consumable.

## Task Completion Loop

A task/subtask is complete only when all of these hold:

1. Local tests for touched surface are green.
2. PR is opened to the correct base branch.
3. Copilot review has been requested and actually triggered.
4. Reported CI checks are green.
5. Actionable Copilot comments are resolved.
6. PR is merged.

If any point fails, fix and loop again.

## Autoloop Rule (Mandatory)

- Do not stop after completing one block and do not wait for a user "go ahead" between roadmap blocks.
- Continue automatically to the next roadmap block until 100% completion.
- End of each single step/substep: explicitly continue to the next roadmap point without asking for confirmation.
- Stop only for hard blockers:
  - missing external access (GitHub push/PR/Copilot/CI unavailable),
  - required secrets/credentials unavailable,
  - contradictory requirements that cannot be safely resolved.
- When blocked, record blocker + exact next action in `docs/PROGRESS.md`, then continue with every other unblocked roadmap item.

## Copilot Review Request

Primary:

```bash
gh pr edit <PR> --add-reviewer @copilot
```

Fallback when GH CLI is blocked by missing `read:project` scope:

1. Get PR node id via `gh pr view <PR> --json id --jq .id`.
2. Call GraphQL `requestReviewsByLogin` with `botLogins[]='copilot-pull-request-reviewer[bot]'` and `union=true`.
3. Verify via `gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers`.

The REST call using `reviewers[]=copilot` is not equivalent and may return success without a visible Copilot review request.

## Testing

- Backend/API tasks: PHPUnit feature + unit tests covering happy path and failure paths.
- Contract/API tasks: add API contract tests and response shape assertions.
- UI tasks: mandatory Playwright scenarios for all primary interactions touched.
- Non-UI code: no Playwright required.

## Documentation

- Update `docs/PROGRESS.md` incrementally.
- Update `docs/LESSON.md` when new reusable findings appear.
- Keep README and docs aligned with implemented behavior.
