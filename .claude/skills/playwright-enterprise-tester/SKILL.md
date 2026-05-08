---
name: playwright-enterprise-tester
description: Use for enterprise-oriented Playwright E2E work — authoring user journeys, extending existing suites, targeted runs by file/folder/tag, visual regression, perf-budget checks, and diagnosing flaky tests. Drives discovery → authoring → execution → diagnose → report. Trigger on tasks like "write Playwright spec", "run E2E", "investigate flaky test", "set up visual regression".
---

# Playwright Enterprise Tester

Skill riusabile per test E2E enterprise-oriented.

## Casi d'uso

- nuova spec per user journey
- estensione di suite esistente
- run mirato per file, folder o tag
- visual regression
- perf budget checks
- diagnosi di test intermittenti

## Workflow obbligatorio

### 1. Discovery
- leggi `playwright.config.*` se esiste
- rileva package manager, reporter, projects e baseURL
- determina se il runner e' locale o CI
- chiarisci lo scope

### 2. Authoring
- preferisci locator semantici
- tagga gli scenari in modo selezionabile
- mantieni i test indipendenti
- testa outcome di business, non dettagli interni

### 3. Execution
- esegui prima il target minimo
- conserva trace, screenshot e report JSON

### 4. Diagnose
- classifica ogni failure in:
  - test bug
  - app bug
  - environment bug
  - flaky
- non modificare il codice applicativo senza consenso esplicito

### 5. Report
- comandi usati
- scope risolto
- summary pass/fail/flaky
- artifact path
- suggerimenti di follow-up
