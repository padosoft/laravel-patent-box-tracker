# Claude Instructions For Laravel Patent Box Tracker

This file mirrors `AGENTS.md` for Claude-compatible flows.

## Read First

1. `docs/PROGRESS.md`
2. `docs/ENTERPRISE_PLAN.md`
3. `docs/RULES.md`
4. `docs/LESSON.md`
5. `.claude/skills/patent-box-enterprise/SKILL.md`
6. `.claude/skills/copilot-pr-review-loop/SKILL.md`

## Non-Negotiable Rules

- Use macro branches and subtask PRs.
- Request GitHub Copilot Code Review on every PR and wait for feedback.
- Merge only after local gates pass and reported CI checks are green.
- Update `docs/PROGRESS.md` during work and `docs/LESSON.md` when discovering reusable patterns.
- Keep API additions backward-compatible with existing CLI/fluent surfaces.
- Keep the package standalone and reusable across Laravel hosts.

## Skills

Use these repo-local skills when relevant:

- `.claude/skills/patent-box-enterprise/SKILL.md`
- `.claude/skills/copilot-pr-review-loop/SKILL.md`

## Release Rule

The release macro includes README polish, lessons-to-rules consolidation, version tag, and GitHub release publication only after all previous macro branches are merged.

