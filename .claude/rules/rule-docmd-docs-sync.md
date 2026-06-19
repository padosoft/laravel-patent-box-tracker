# Keep docmd docs synchronized

When code changes affect CLI options, configuration keys, HTTP routes, database tables, renderers, classifiers, collectors, or dossier output, update `docs-site/content` in the same change.

Required checks:

- `npm run check`
- `npm run build`

Do not introduce raw HTML or MDX into docs pages. Add new pages to `docs-site/docmd.config.json` navigation.
