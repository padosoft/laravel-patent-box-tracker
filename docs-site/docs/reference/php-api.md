---
title: PHP API
description: Fluent builder and model entry points for programmatic tracking.
---

# PHP API Reference

The public fluent entrypoint is `Padosoft\PatentBoxTracker\PatentBoxTracker`.

```php
use Padosoft\PatentBoxTracker\PatentBoxTracker;

$session = PatentBoxTracker::for(['C:/work/my-ip'])
    ->coveringPeriod('2026-01-01', '2026-12-31')
    ->classifiedBy('regolo', 'claude-sonnet-4-6')
    ->withTaxIdentity([
        'denomination' => 'Padosoft',
        'p_iva' => 'IT00000000000',
    ])
    ->withCostModel([])
    ->withRole('primary_ip')
    ->run();
```

## Builder Methods

| Method | Purpose |
| --- | --- |
| `for` | start a builder for one or more repositories |
| `coveringPeriod` | set date window |
| `classifiedBy` | override provider and model |
| `withTaxIdentity` | set required fiscal identity |
| `withCostModel` | attach cost model metadata |
| `withRole` | set repository role for builder runs |
| `run` | collect, classify, persist, and return a session |

The returned session can be rendered through the model renderer helpers exposed by the package.
