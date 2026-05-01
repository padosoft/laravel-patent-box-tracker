# Contributing to laravel-patent-box-tracker

Thank you for your interest in contributing. The package is a community open-source project under the [Apache-2.0 license](LICENSE) and follows the Padosoft contribution conventions.

## Quick start for contributors

```bash
git clone https://github.com/padosoft/laravel-patent-box-tracker.git
cd laravel-patent-box-tracker
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The default `phpunit` invocation runs only the offline `Unit` testsuite — it never hits a real LLM provider. The `Live` testsuite is opt-in (see the README "Running the live test suite" section).

## Branching model

This is a community Padosoft repository, so PRs target `main` directly (no integration branches). Open one PR per cohesive change.

Branch-name conventions:

- `feature/<topic>` — new capability, new collector, new renderer, new commands.
- `fix/<topic>` — bug fix on shipped behaviour.
- `docs/<topic>` — README, CHANGELOG, or `docs/` updates.
- `chore/<topic>` — dependency bumps, CI tweaks, repo hygiene.

## Pull request expectations

- The default `Unit` suite must stay green on the full PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 matrix.
- Any new collector implements `EvidenceCollector` and is registered with the boot-time validation pattern (FQCN check + `supports()` mutex per the standalone-agnostic architecture test).
- Any new classifier prompt change requires a hand-graded regression run on the bundled golden set; F1 must stay ≥ 80%.
- `vendor/bin/pint --test` must pass with no diffs.
- `vendor/bin/phpstan analyse` must report zero errors at level 6.
- The README's "Features at a glance" bullet list stays in sync with what the code does — add a bullet when you ship a feature, remove one when you remove it.

## Commits

We follow the conventional `<type>(scope): subject` shape used across all `padosoft/*` repositories — for example:

```
feat(classifier): add deterministic-seed override per session
fix(collector): reject author emails matching dependabot[bot]
docs(readme): add cost-cap example for cross-repo runs
```

Co-Authored-By trailers for AI-assisted commits are encouraged — the package's own `AiAttributionExtractor` reads them when this repository is one of the tracked targets.

## Code of conduct

Participation in this project is subject to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Security issues

Do not file public issues for vulnerabilities. See [SECURITY.md](SECURITY.md) for the responsible-disclosure policy.

## License

By contributing, you agree your contribution is released under the [Apache-2.0 license](LICENSE).
