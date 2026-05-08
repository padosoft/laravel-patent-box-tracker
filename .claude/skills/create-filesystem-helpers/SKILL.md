---
name: create-filesystem-helpers
description: Use when introducing a new Laravel storage disk or file workflow (uploads, exports, generated artifacts like dossiers/PDFs). Centralizes disk config, path/naming rules, streaming for large files, visibility/retention/cleanup. Trigger on tasks like "add new disk", "store generated file", "manage uploads".
---

# Create Filesystem Helpers

Quando un progetto Laravel introduce un nuovo disco o flusso file:

- definire il disk in `config/filesystems.php`
- centralizzare naming path e regole di storage
- usare stream per file grandi
- evitare path string sparsi nel codice
- chiarire visibilita', retention e cleanup
