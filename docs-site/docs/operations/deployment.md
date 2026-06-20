---
title: Deployment
description: Operational setup for queues, storage, renderers, and the HTTP API.
---

# Deployment

The package runs inside a host Laravel application. Production deployment focuses on the host app.

## Checklist

::: steps
1. Run migrations on the audit database connection.
2. Configure `PATENT_BOX_DRIVER`, `PATENT_BOX_MODEL`, and provider credentials.
3. Configure queue workers if using the HTTP queue endpoints.
4. Choose Browsershot or DomPDF and install required system dependencies.
5. Set storage retention policy for generated dossiers.
6. Enable the HTTP API only when an admin panel or external automation needs it.
:::

## API Gate

```dotenv
PATENT_BOX_API_ENABLED=true
PATENT_BOX_API_PREFIX=api/patent-box
PATENT_BOX_API_TOKEN=change-me
PATENT_BOX_API_RATE_LIMITER=api
```

::: callout warning
Do not expose the API without host-level authentication, rate limits, or the package token gate. The endpoints reveal fiscal and repository metadata.
:::
