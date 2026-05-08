---
name: create-admin-interface
description: Orchestrator skill for creating or refactoring a complex Laravel admin page (filters, KPIs, tables, exports, charts, modals). Drives the full sequence — component audit, contract definition, backend, frontend, hardening — and chains the related admin-interface-* skills. Trigger on requests like "create admin page", "build dashboard for X", or "refactor admin index for Y".
---

# Create Admin Interface

Orchestratore per creare o rifattorizzare una pagina admin Laravel complessa.

Target: Laravel 13.x, PHP 8.3+.

## Fasi

1. audit componenti e servizi esistenti
2. definizione del contratto backend/frontend
3. implementazione backend
4. implementazione frontend
5. test, hardening e review prestazionale

## Quando usarla

- pagine con filtri, KPI, tabelle, export, grafici, modal
- refactor di interfacce admin disomogenee
