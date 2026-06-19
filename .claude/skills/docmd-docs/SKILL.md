# docmd-docs

Use this skill when editing the `docs-site` documentation for `laravel-patent-box-tracker`.

## Rules

- Keep docs as Markdown only. Do not add MDX, JSX, Vue, Astro, or raw HTML.
- Use docmd containers for structured content: `callout`, `tabs`, `steps`, `collapsible`, `grids`, `grid`, and `card`.
- Add every new page to `docs-site/docmd.config.json` navigation.
- Keep semantic search enabled with `Xenova/all-MiniLM-L6-v2`.
- Run `npm run check` and `npm run build` after content or config changes.
- Verify `_site/llms.txt`, `_site/sitemap.xml`, and `_site/.docmd-search/manifest.json` after a production build.

## Content Shape

- Explain motivation before API details.
- For architecture changes, include a Mermaid diagram when it clarifies flow.
- Use KaTeX only for durable formulas such as cost allocation.
- Keep warnings explicit when fiscal, audit, or reproducibility limits matter.
