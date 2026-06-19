---
title: Teoria
description: Conceptual model for phases, qualification, evidence, and reproducibility.
---

# Teoria

The package models a fiscal dossier as a bounded, reproducible evidence set.

## Core Concepts

| Concept | Meaning |
| --- | --- |
| Session | one fiscal run over a period |
| Evidence | raw facts from git, design docs, branches, and AI attribution |
| Classification | deterministic phase and qualification judgment per commit |
| Dossier | rendered PDF or JSON artifact tied to a session |

## Phase Taxonomy

The classifier separates activities such as research, design, implementation, validation, documentation, and non-qualified maintenance. The goal is not to inflate qualified work; it is to produce a defensible split.

::: collapsible Determinism
Classifier output is made reproducible by `temperature=0`, a fixed seed, recorded prompt text, and persisted provider/model metadata.
:::

::: collapsible Auditability
Every dossier should answer who ran it, which repositories were included, what period was covered, which evidence was used, and where the hash chain ends.
:::
