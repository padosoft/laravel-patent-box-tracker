---
name: copilot-pr-review-loop
description: Mandatory loop after each push/PR. Require Copilot review request, CI green, comment resolution, and repeat until convergence.
---

# Copilot PR Review + CI Loop

## Rule

Do not stop after a single push. A PR is complete only when:

1. Copilot review has no unresolved actionable comments.
2. Reported CI checks are green (or explicitly expected skipped).

## Canonical Flow

1. Run local tests.
2. Push branch.
3. Open PR to correct base.
4. Request Copilot reviewer.
5. Verify requested reviewer includes Copilot.
6. Wait for CI and review feedback.
7. Fix issues, run tests, push.
8. Repeat from step 5 until clean.
9. Merge.

## Primary Command

```bash
gh pr edit <PR> --add-reviewer @copilot
```

## GraphQL Fallback

When primary command fails before requesting reviewer (for example missing `read:project` token scope), use:

```bash
pr_node_id="$(gh pr view <PR> --json id --jq .id)"

query='
mutation RequestReviewsByLogin($pullRequestId: ID!, $botLogins: [String!], $union: Boolean!) {
  requestReviewsByLogin(input: {pullRequestId: $pullRequestId, botLogins: $botLogins, union: $union}) {
    clientMutationId
  }
}
'

gh api graphql \
  -f query="$query" \
  -F pullRequestId="$pr_node_id" \
  -F botLogins[]='copilot-pull-request-reviewer[bot]' \
  -F union=true

gh api repos/<owner>/<repo>/pulls/<PR>/requested_reviewers
```

The `reviewers[]=copilot` REST fallback is not sufficient by itself.

