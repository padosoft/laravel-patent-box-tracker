---
title: Worked Example
description: A concrete fiscal-year dossier workflow from dry run to integrity check.
---

# Worked Example

Scenario: Padosoft needs a FY2026 dossier for a Laravel IP repository and one support repository.

::: steps
1. Prepare the cross-repo YAML with fiscal year, date window, tax identity, cost model, classifier, and repository roles.
2. Run `php artisan patent-box:cross-repo patent-box-2026.yml --dry-run`.
3. Review projected cost and commit counts per repository.
4. Run `php artisan patent-box:cross-repo patent-box-2026.yml`.
5. Render both PDF and JSON sidecar.
6. Call the integrity endpoint or use the admin panel to verify the hash-chain head.
:::

## Cost Allocation

A simple cost allocation model can be expressed as:

$$
C_{qualified} = \sum_{phase} hours_{phase} \times rate_{hour} \times q_{phase}
$$

where `q_phase` is 1 for qualified R&D phases and 0 for excluded phases such as generic administration.

::: callout info
The package records classifications and evidence. The final fiscal allocation still needs review by the taxpayer and their advisor.
:::

## Expected Artifacts

| Artifact | Purpose |
| --- | --- |
| PDF dossier | human-readable fiscal filing attachment |
| JSON sidecar | machine-readable archive and admin-panel source |
| hash-chain head | quick integrity anchor |
| classifier metadata | reproducibility record for model, prompt, seed, and provider |
