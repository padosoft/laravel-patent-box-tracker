---
title: Classification Hygiene
description: Practices that keep classifier output reviewable and defensible.
---

# Classification Hygiene

Good classifier output starts with clean inputs.

::: steps
1. Keep commit messages specific to the technical activity.
2. Use branch names that distinguish features, research, fixes, and chores.
3. Keep design docs near the code or use predictable filenames.
4. Record AI-assisted work through commit trailers or committer metadata.
5. Review low-confidence or high-impact classifications before filing.
:::

::: callout warning
Do not treat the qualification flag as final tax treatment. Treat it as structured evidence for review.
:::

## Review Focus

Prioritize manual review for:

- commits near release boundaries,
- generic refactors with unclear R&D connection,
- post-release bug fixes,
- commits with AI-authored attribution,
- large mixed commits touching product and operations files.
