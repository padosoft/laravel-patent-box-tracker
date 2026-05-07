# Patent Box Web Admin Panel Implementation Plan

## 1. Intent

Build a separate Laravel package or standalone Laravel app, for example `padosoft/laravel-patent-box-admin`, that provides a polished web admin panel for `padosoft/laravel-patent-box-tracker`.

The admin panel consumes only the tracker package HTTP API documented in `docs/API_IMPLEMENTATION_PLAN.md`. It must not read tracker tables directly and must not call tracker internals.

Target stack:

- Laravel 13.x
- Vite
- React
- TypeScript
- Tailwind CSS
- React Query or SWR for server state
- shadcn/ui-style component primitives or an equivalent local component system
- lucide-react icons

The UI should feel premium, dense, and operational: not a marketing landing page, not a generic CRUD scaffold, and not a card-only dashboard.

## 2. Product Scope

### Primary Users

- Software founders and maintainers preparing Italian Patent Box documentation.
- Fiscal/compliance operators reviewing R&D qualification evidence.
- Technical leads validating commit classifications, evidence links, AI attribution, and dossier integrity.

### Core Jobs To Be Done

- Configure a Patent Box run across one or more repositories.
- Estimate cost before classifier calls.
- Launch single-repo or cross-repo tracking.
- Monitor progress and failure states.
- Inspect classified commits and evidence links.
- Review phase breakdown, R&D qualification, cost estimate, and AI attribution.
- Render and download PDF/JSON dossiers.
- Verify the tamper-evident hash chain.

### Out Of Scope For v1

- Editing classifications after the package has classified them.
- Direct tax filing submission.
- Direct UIBM, SIAE, EPO, or Agenzia delle Entrate integrations.
- Multi-tenant billing.
- Direct database access to the tracker package.

## 3. App Architecture

### Repository Shape

Recommended package/app structure:

```text
laravel-patent-box-admin/
  app/
    Http/Controllers/
  resources/
    js/
      app.tsx
      api/
      components/
      features/
      layouts/
      routes/
      styles/
      types/
    views/
      app.blade.php
  routes/
    web.php
  config/
    patent-box-admin.php
  tests/
```

The Laravel side serves the React app shell and optionally proxies API requests to a configured tracker host.

### Runtime Modes

Support two deployment modes:

- same-host mode: admin package is installed in the same Laravel app as tracker API and calls relative `/api/patent-box/v1`;
- remote-host mode: admin runs in a separate Laravel app and calls `PATENT_BOX_TRACKER_API_BASE_URL`.

Config:

```php
'tracker_api' => [
    'base_url' => env('PATENT_BOX_TRACKER_API_BASE_URL', '/api/patent-box/v1'),
    'auth_mode' => env('PATENT_BOX_TRACKER_AUTH_MODE', 'sanctum'),
    'timeout_seconds' => env('PATENT_BOX_TRACKER_TIMEOUT', 30),
],
```

### Frontend State

Use React Query as the default server-state layer.

Required query keys:

- `['capabilities']`
- `['sessions', filters]`
- `['session', sessionId]`
- `['commits', sessionId, filters]`
- `['evidence', sessionId, filters]`
- `['dossiers', sessionId]`
- `['integrity', sessionId]`

Mutations:

- validate repository;
- dry-run session;
- create tracking session;
- render dossier;
- refresh session status;
- download dossier.

Use optimistic UI only for local form state. Do not fake completed backend states.

## 4. UX Direction

### Visual Direction

The interface should feel like a high-end compliance operations cockpit:

- quiet light theme by default;
- strong use of white, graphite, and restrained accent colors;
- semantic colors for status and phase;
- crisp tables, timelines, rails, and split panes;
- minimal nested cards;
- 8px radius maximum unless a component requires otherwise;
- no decorative blobs, generic gradients, or marketing hero sections.

The first screen after loading is the operational dashboard, not a landing page.

### Design Tokens

Define tokens in Tailwind and CSS variables:

- background: near-white or true white;
- surface: white;
- surface-muted: cool gray;
- text: graphite;
- text-muted: slate gray;
- border: neutral gray;
- accent: deep cyan or blue for primary actions;
- success: green;
- warning: amber;
- danger: red;
- research/design/implementation/validation/documentation/non-qualified phase colors.

Use a compact type scale:

- page title: 28-32px;
- section title: 18-22px;
- table/header labels: 12-13px;
- body/table text: 13-15px;
- numeric metric text: 22-28px depending on panel density.

No viewport-width font scaling.

### App Shell

Desktop layout:

- left sidebar with product mark, primary nav, active fiscal year, connection status;
- top command bar with search, API health, user/account menu;
- main workspace with responsive split panes;
- optional right inspector drawer for selected commit/session/evidence.

Mobile layout:

- top bar with menu trigger;
- drawer navigation;
- stacked workflow screens;
- tables become dense list rows with expandable details, not random card grids.

Primary navigation:

- Dashboard
- Sessions
- New Run
- Commits
- Evidence
- Dossiers
- Settings

## 5. Screens And Workflows

### Dashboard

Route: `/`

Purpose: show the state of Patent Box operations at a glance.

Required sections:

- session status strip: pending/running/classified/rendered/failed counts;
- fiscal year selector;
- latest sessions table;
- aggregate metrics: total commits, qualified commits, projected cost, actual cost, latest hash-chain head;
- phase breakdown visualization;
- AI attribution visualization;
- render queue/dossier status rail.

Primary actions:

- New run
- Open latest session
- Render missing dossiers

### New Run Wizard

Route: `/runs/new`

Use a multi-step workflow with persistent summary rail:

1. Identity
   - denomination;
   - P.IVA;
   - fiscal year;
   - regime.
2. Period
   - from;
   - to.
3. Repositories
   - add repository path;
   - select role;
   - validate repository;
   - show commit count and warnings.
4. Cost and classifier
   - provider;
   - model;
   - hourly rate;
   - daily hours max;
   - cost cap display from capabilities.
5. Dry-run
   - total commit count;
   - projected cost;
   - per-repo breakdown;
   - clear warning if cost cap is exceeded.
6. Launch
   - final review;
   - create session;
   - redirect to session detail.

The wizard must support both single-repo and cross-repo runs. Single-repo is just a one-repository version of the same flow.

### Sessions

Route: `/sessions`

Use a table-first view with:

- status filter;
- fiscal year filter;
- regime filter;
- search;
- sortable columns;
- row actions: open, render PDF, render JSON, verify integrity.

Columns:

- ID;
- taxpayer;
- fiscal year;
- period;
- status;
- repositories;
- commits;
- qualified;
- projected cost;
- actual cost;
- classifier;
- finished at.

### Session Detail

Route: `/sessions/:id`

The session detail is the main working surface.

Required layout:

- header with status, taxpayer, fiscal year, period, classifier, seed;
- pipeline timeline: pending, running, classified, rendered, failed;
- metric rail: commits, qualified commits, projected cost, actual cost, golden set F1 if present;
- repository summary table;
- phase breakdown chart;
- AI attribution chart;
- hash-chain integrity strip;
- tabs: Commits, Evidence, Dossiers, JSON Payload Preview.

The hash-chain strip should be visually distinctive: show verified/unverified state, head digest, commit count, and first failure if available.

### Commit Explorer

Route: `/sessions/:id/commits`

Also appears as a tab in session detail.

Use a data table with:

- repository filter;
- phase filter;
- R&D qualified filter;
- AI attribution filter;
- author filter;
- confidence range;
- search by SHA/message/rationale/evidence slug.

On row selection, open an inspector drawer:

- SHA and copy button;
- message subject;
- repository role/path;
- author;
- changed files count;
- insertions/deletions;
- phase;
- qualification;
- confidence;
- rationale;
- rejected phase;
- evidence used;
- branch semantics;
- hash-chain prev/self.

### Evidence Explorer

Route: `/sessions/:id/evidence`

Required UI:

- evidence kind filter;
- slug search;
- linked commit count sort;
- table/list of evidence rows;
- inspector showing title, path, slug, first seen, last modified, linked commit count.

When possible, link from evidence slug to filtered commits using that slug.

### Dossier Center

Route: `/sessions/:id/dossiers`

Required UI:

- existing dossier artefacts table;
- render PDF action;
- render JSON action;
- download action;
- SHA-256 copy button;
- byte size;
- generated at;
- render failure state if API returns an error.

Do not expose local filesystem paths as primary UI affordances. Show them only in a technical details disclosure.

### Settings

Route: `/settings`

Required sections:

- tracker API base URL;
- API health/capabilities check;
- auth mode display;
- supported locales;
- renderer driver;
- classifier defaults;
- cost cap;
- package/API version.

Settings should be read-only unless the admin package owns its own local config storage. For v1, prefer read-only diagnostics plus environment variable documentation.

## 6. API Client Contract

Create a typed API client in `resources/js/api`.

Recommended files:

```text
api/
  client.ts
  endpoints.ts
  errors.ts
  types.ts
  queries.ts
```

### Error Handling

Map API errors to UI states:

- `401/403`: auth required or forbidden screen;
- `404`: empty/not found state;
- `409`: domain conflict, cost cap exceeded, invalid repo state;
- `422`: form field errors;
- `500`: failure panel with retry/copy diagnostics.

Never show raw stack traces in the UI.

### Polling

Poll session detail while status is `pending` or `running`.

Default intervals:

- active detail page: every 3 seconds;
- background sessions table: every 15 seconds;
- stop polling when status becomes `classified`, `rendered`, or `failed`.

## 7. Component System

Core components:

- `AppShell`
- `SidebarNav`
- `CommandBar`
- `StatusBadge`
- `PhaseBadge`
- `MetricRail`
- `DataTable`
- `FilterBar`
- `InspectorDrawer`
- `PipelineTimeline`
- `HashChainStrip`
- `PhaseBreakdownChart`
- `AiAttributionChart`
- `RepositoryRoleSelect`
- `RepositoryValidationRow`
- `DossierArtefactTable`
- `CopyDigestButton`
- `ApiErrorState`
- `EmptyState`

Use lucide-react icons for navigation and actions where appropriate:

- dashboard;
- sessions/list;
- play/launch;
- search;
- filter;
- download;
- file JSON/PDF;
- shield/check for integrity;
- alert triangle for failures;
- copy;
- refresh.

All icon-only controls need accessible labels and tooltips.

## 8. Data Visualization

Prefer operational visuals over decorative charts:

- phase breakdown: compact horizontal stacked bar plus table legend;
- AI attribution: segmented bar with percentages;
- pipeline: timeline with current state;
- repository summary: table with role badges and qualified ratio;
- hash-chain: digest strip with monospace head hash and verification state.

Charts must degrade to readable tables for small screens.

## 9. Security And Privacy

- Do not store API tokens in localStorage if same-host Sanctum mode is available.
- If remote token mode is required, document secure deployment through server-side proxy first.
- Treat repository paths, taxpayer data, P.IVA, and dossier hashes as sensitive.
- Mask P.IVA in tables where full value is not needed.
- Require explicit user action before downloading dossiers.
- Avoid logging full payloads with taxpayer identity in browser console.

## 10. Implementation Steps

1. Scaffold Laravel 13.x package/app with Vite, React, TypeScript, and Tailwind.
2. Add config for tracker API base URL and auth mode.
3. Build the typed API client and React Query hooks against API fixtures.
4. Implement app shell, navigation, command bar, and connection/capabilities check.
5. Build dashboard and sessions table.
6. Build new run wizard with repository validation and dry-run.
7. Build session detail with timeline, metrics, charts, hash-chain strip, and tabs.
8. Build commit explorer, evidence explorer, dossier center, and settings.
9. Add mock API fixtures matching the core package contract tests.
10. Add browser verification for desktop and mobile.
11. Package and document installation in a host Laravel application.

## 11. Testing Plan

### Unit Tests

- API client serializes requests correctly.
- error mapper handles 401, 403, 404, 409, 422, and 500.
- utility formatters handle money, dates, percentages, SHA truncation, and masked P.IVA.
- wizard validation blocks invalid local state before API calls.

### Integration Tests

- dashboard renders capabilities and session fixtures.
- sessions table filters and sorts fixture data.
- new run wizard validates repositories, performs dry-run, creates session, and redirects.
- session detail polls while running and stops when classified.
- commit explorer filters by phase, author, qualification, and evidence slug.
- dossier center starts render and displays generated artefact metadata.

### Browser Tests

Use Playwright or the chosen browser verification tool:

- desktop dashboard at 1440px width;
- mobile dashboard at 390px width;
- new run wizard full flow with mocked API;
- session detail with classified fixture;
- commit inspector drawer;
- dossier render/download state;
- API error states.

Visual checks must confirm:

- no text overflow;
- no overlapping controls;
- tables remain scannable;
- mobile layout does not become a loose card grid;
- core actions remain reachable;
- status and phase colors are consistent.

## 12. Acceptance Criteria

- The admin panel can operate entirely through the tracker API.
- A user can run a complete flow: configure repositories, dry-run, launch session, inspect results, render PDF/JSON, and verify integrity.
- UI is production-grade, dense, responsive, and visually distinctive without becoming decorative.
- All API interactions are typed and covered by tests or fixtures.
- The package can be installed independently from the core tracker package.
- No tracker internals are imported into the admin frontend or Laravel backend except the public HTTP API contract.

