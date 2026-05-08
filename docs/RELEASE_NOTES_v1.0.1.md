# Release Notes — v1.0.1

> 8 May 2026

Security patch release. Closes the 6 GitHub Dependabot alerts opened on `main` against the optional `spatie/browsershot` dev dependency.

## Security

The package's optional Browsershot-based PDF renderer was declared with an overly permissive constraint (`^4.0|^5.0`) that allowed installation of versions vulnerable to:

| CVE             | Severity | Issue                                                              | Fixed in |
|-----------------|----------|--------------------------------------------------------------------|----------|
| CVE-2024-21544  | medium   | Improper URL validation → Local File Inclusion via leading `%20`. | 5.0.1    |
| CVE-2024-21547  | high     | Directory Traversal via `file:\\` URI normalization.               | 5.0.2    |
| CVE-2024-21549  | medium   | LFI via `view-source:file://` bypass.                              | 5.0.3    |
| CVE-2025-1022   | high     | Path Traversal in `setHtml()` via `file:` without slashes.         | 5.0.5    |
| CVE-2025-1026   | medium   | LFI via `setUrl()` (CVE-2024-21549 bypass).                        | 5.0.5    |
| CVE-2025-3192   | high     | SSRF in `setUrl()` allowing localhost access and directory listing. | 5.0.5+  |

### Fix

`spatie/browsershot` is constrained to `^5.0.5` in `composer.json` (`require-dev`). All six advisories are excluded by the new floor.

```diff
- "spatie/browsershot": "^4.0|^5.0"
+ "spatie/browsershot": "^5.0.5"
```

`composer audit` reports no remaining advisories.

### Impact

- **End users**: no impact — Browsershot is a *dev / suggested* dependency. Production installs that include Browsershot will resolve to a non-vulnerable version on the next `composer update`.
- **Contributors**: re-run `composer update spatie/browsershot` after pulling `v1.0.1` to land on a patched version (≥ 5.0.5).
- **Public API / contract**: unchanged. No HTTP API, CLI, or fluent-builder surface changes.

## Other

- Lessons consolidated in `docs/LESSON.md` ("Dependabot floor lesson") — over-permissive `|` constraints on optional renderers must specify a security-aware floor.
- README badge `latest release` and roadmap unchanged.

## Upgrade

```bash
composer require padosoft/laravel-patent-box-tracker:^1.0.1
composer update spatie/browsershot
```

No code changes, no migrations.
