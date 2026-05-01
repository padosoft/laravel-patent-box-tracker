#!/usr/bin/env bash
# Build synthetic-support.git — a small second deterministic bare git
# repo used as the support-role peer in CrossRepoCommandTest. Re-running
# this script produces identical SHAs because every commit pins
# GIT_AUTHOR_DATE and GIT_COMMITTER_DATE explicitly, plus a fixed
# author identity, plus `commit --no-gpg-sign` to bypass any user-level
# signing config.
#
# Usage:
#   bash tests/fixtures/repos/build-second-synthetic.sh
#
# The bare repo is written to:
#   tests/fixtures/repos/synthetic-support.git
#
# The contents are committed to source control so contributors do not
# need to run this script unless they are adding new fixture commits.

set -euo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
TARGET="${THIS_DIR}/synthetic-support.git"

cleanup() { rm -rf "${WORK_DIR}"; }
trap cleanup EXIT

rm -rf "${TARGET}"

git init --quiet "${WORK_DIR}"
cd "${WORK_DIR}"

git config user.name 'Lorenzo Padovani'
git config user.email 'lorenzo.padovani@padosoft.com'
git config commit.gpgsign false
git config tag.gpgsign false
git config init.defaultBranch main
git config core.autocrlf false
git config core.eol lf

git checkout --quiet -B main

commit() {
  local subject="$1"
  local file="$2"
  local content="$3"
  local date="$4"

  mkdir -p "$(dirname "${file}")"
  printf '%s\n' "${content}" > "${file}"
  git add "${file}"

  GIT_AUTHOR_DATE="${date}" \
  GIT_COMMITTER_DATE="${date}" \
  GIT_AUTHOR_NAME='Lorenzo Padovani' \
  GIT_AUTHOR_EMAIL='lorenzo.padovani@padosoft.com' \
  GIT_COMMITTER_NAME='Lorenzo Padovani' \
  GIT_COMMITTER_EMAIL='lorenzo.padovani@padosoft.com' \
  git commit --quiet --no-gpg-sign -m "${subject}"
}

commit \
  "feat: regolo provider initial scaffold" \
  "src/RegoloProvider.php" \
  "<?php class RegoloProvider {}" \
  "2026-01-15T09:00:00Z"

commit \
  "test: regolo round-trip happy path" \
  "tests/RegoloProviderTest.php" \
  "<?php class RegoloProviderTest {}" \
  "2026-02-10T11:00:00Z"

commit \
  "docs: regolo README badges" \
  "README.md" \
  "# regolo" \
  "2026-03-05T14:00:00Z"

# Convert to bare so tests use a stable layout.
git clone --quiet --bare "${WORK_DIR}" "${TARGET}"

# Strip the cloned origin remote so the fixture config does not embed
# the transient temp directory path from WORK_DIR.
git --git-dir="${TARGET}" config --remove-section remote.origin 2>/dev/null || true

echo "Built fixture at: ${TARGET}"
