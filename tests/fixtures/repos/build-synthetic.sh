#!/usr/bin/env bash
# Build synthetic-r-and-d.git — a deterministic bare git repo used by the
# Unit suite as a known-state fixture. Re-running this script produces
# identical SHAs because every commit pins GIT_AUTHOR_DATE and
# GIT_COMMITTER_DATE explicitly, plus a fixed author identity, plus
# `commit --no-gpg-sign` to bypass any user-level signing config.
#
# Usage:
#   bash tests/fixtures/repos/build-synthetic.sh
#
# The bare repo is written to:
#   tests/fixtures/repos/synthetic-r-and-d.git
#
# The contents are committed to source control so contributors do not need
# to run this script unless they are adding new fixture commits.

set -euo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
TARGET="${THIS_DIR}/synthetic-r-and-d.git"

cleanup() { rm -rf "${WORK_DIR}"; }
trap cleanup EXIT

rm -rf "${TARGET}"

git init --quiet "${WORK_DIR}"
cd "${WORK_DIR}"

# Identity and config — pinned for determinism.
git config user.name 'Lorenzo Padovani'
git config user.email 'lorenzo.padovani@padosoft.com'
git config commit.gpgsign false
git config tag.gpgsign false
git config init.defaultBranch main
git config core.autocrlf false
git config core.eol lf

# Ensure HEAD points at main even on git versions that default elsewhere.
git checkout --quiet -B main

commit() {
  local subject="$1"
  local body="${2:-}"
  local file="${3:-README.md}"
  local content="${4:-content}"
  local date="$5"
  local author_name="${6:-Lorenzo Padovani}"
  local author_email="${7:-lorenzo.padovani@padosoft.com}"

  mkdir -p "$(dirname "${file}")"
  printf '%s\n' "${content}" > "${file}"
  git add "${file}"

  local message
  if [[ -n "${body}" ]]; then
    message=$(printf '%s\n\n%s' "${subject}" "${body}")
  else
    message="${subject}"
  fi

  GIT_AUTHOR_DATE="${date}" \
  GIT_COMMITTER_DATE="${date}" \
  GIT_AUTHOR_NAME="${author_name}" \
  GIT_AUTHOR_EMAIL="${author_email}" \
  GIT_COMMITTER_NAME="${author_name}" \
  GIT_COMMITTER_EMAIL="${author_email}" \
  git commit --quiet --no-gpg-sign -m "${message}"
}

# 1. Research phase — initial scoping.
commit \
  "research: scope analysis for canonical compilation" \
  "Investigate prior art on RAG retrieval engines." \
  "docs/PLAN-canonical.md" \
  "# PLAN: canonical compilation" \
  "2026-01-05T09:00:00Z"

# 2. Design phase.
commit \
  "design: ADR for typed knowledge base" \
  "Defines the 9 canonical types and 10 edge types." \
  "docs/adr/0001-typed-kb.md" \
  "# ADR-0001: Typed knowledge base" \
  "2026-01-12T10:00:00Z"

# 3. Implementation — human only.
commit \
  "feat: implement parser entrypoint" \
  "" \
  "src/Parser.php" \
  "<?php class Parser {}" \
  "2026-01-20T11:00:00Z"

# 4. Implementation — Claude AI-assisted.
commit \
  "feat: chunker FSM (W3 milestone)" \
  $'Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>' \
  "src/Chunker.php" \
  "<?php class Chunker {}" \
  "2026-02-01T14:00:00Z"

# 5. Implementation — Copilot AI-assisted.
commit \
  "feat: edge weighting heuristic" \
  $'Co-Authored-By: GitHub Copilot <bot@github.com>' \
  "src/EdgeWeights.php" \
  "<?php class EdgeWeights {}" \
  "2026-02-08T15:00:00Z"

# 6. Validation phase.
commit \
  "test: feature suite for chunker" \
  "" \
  "tests/ChunkerTest.php" \
  "<?php class ChunkerTest {}" \
  "2026-02-15T16:00:00Z"

# 7. Documentation phase.
commit \
  "docs: lessons learned from W3" \
  "" \
  "docs/plans/lessons-learned.md" \
  "# Lessons learned" \
  "2026-02-22T17:00:00Z"

# 8. Bot commit — dependabot. Should be filtered out.
commit \
  "build(deps): bump phpunit from 11.0 to 12.0" \
  "" \
  "composer.json" \
  '{"require":{"phpunit/phpunit":"^12.0"}}' \
  "2026-02-23T08:00:00Z" \
  "dependabot[bot]" \
  "49699333+dependabot[bot]@users.noreply.github.com"

# 9. Bot commit — github-actions[bot]. Should be filtered out.
commit \
  "ci: auto-update lockfile" \
  "" \
  "composer.lock" \
  '{}' \
  "2026-02-24T08:00:00Z" \
  "github-actions[bot]" \
  "41898282+github-actions[bot]@users.noreply.github.com"

# 10. Documentation phase — final.
commit \
  "docs: SPEC for hashing pipeline" \
  "Slug reference: PLAN-canonical." \
  "docs/superpowers/specs/hashing.md" \
  "# SPEC: hashing pipeline" \
  "2026-03-01T18:00:00Z"

# Convert to bare so tests use a stable layout.
git clone --quiet --bare "${WORK_DIR}" "${TARGET}"

# Strip the cloned origin remote so the fixture config does not embed the
# transient temp directory path from WORK_DIR (which would leak the local
# username and break determinism across rebuilds on different machines).
git --git-dir="${TARGET}" config --remove-section remote.origin 2>/dev/null || true

# Final cleanup.
echo "Built fixture at: ${TARGET}"
