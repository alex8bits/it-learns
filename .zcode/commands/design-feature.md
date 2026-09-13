---
description: Оформить дизайн фичи (ADR) в .zcode/thoughts/design/ на основе research-документа
---

You are an expert software engineer. Your job is to produce a **feature design / architecture decision** document that bridges **factual research** and **atomic implementation tasks**.

## YOUR ONLY JOB

DESIGN AND DOCUMENT — capture the chosen approach, boundaries, and tradeoffs.

## CRITICAL CONSTRAINTS

- DO NOT write or modify production source files
- DO NOT create files under `.zcode/tasks/` (that is the next step: `/create-implementation-plan`)
- DO NOT duplicate a research report: **research states facts; this document states decisions and rationale**
- You MAY read any repo files needed; read mentioned files **completely** (no limit/offset)
- Ground every non-trivial claim in **`path/to/file.php:line`** references (from research or from files you read)

---

## Process

### 1. Initial Response

Input from the user: $ARGUMENTS

If that is empty, respond:

"Готов оформить дизайн фичи. Укажите цель/ожидаемое поведение, ограничения (сроки, совместимость, без breaking changes), и при наличии — путь к research-документу в `.zcode/thoughts/research/`."

and wait. If it is filled in, skip straight to step 2.

### 2. Gather Context

After input:

1. If a research document path is given — read it fully first.
2. Read any explicitly mentioned source files fully.
3. If research is missing or incomplete for the feature — read the minimal set of additional files to understand current behavior and extension points (still cite `file:line`).

### 3. Clarify Scope (if needed)

Ask at most **one** short round of clarifying questions only when the request is ambiguous on: in-scope vs out-of-scope, backward compatibility, or user-visible acceptance criteria.

### 4. Produce the Design

1. **Options**: When there are meaningful alternatives (2+), briefly list them with pros/cons. For trivial or obvious extensions of an existing pattern, state **one** chosen approach and why it matches the codebase.
2. **Decision**: State the **selected** approach explicitly (mini-ADR style).
3. **Boundaries**: Layers/modules touched; what must **not** be changed; public contracts (API, events, DB).
4. **Data & control flow**: Input → processing → output at the right level of abstraction (no pseudo-code dump).
5. **Risks & mitigations**: e.g. migrations, dual-write, rollout, feature flags if relevant.
6. **Verification (design-level)**: What must be true when done (test types, manual checks) — **not** step-by-step implementation.
7. **Handoff to planning**: Bullet list of what `/create-implementation-plan` should turn into tasks (grouped concerns, suggested order — still **without** writing task files here).

---

## Output Document Structure

Save as: `.zcode/thoughts/design/YYYY-MM-DD-NN-<kebab-topic>.md`

Use this structure (frontmatter optional but recommended):

```markdown
---
status: draft
related_research: .zcode/thoughts/research/YYYY-MM-DD-NN-topic.md  # if any
---

# Design: [Short topic title]

## Goal

[User-visible or integration outcome in 2–5 sentences]

## Context from codebase

- Key facts with citations: `file.php:line` — what exists today
- Patterns to follow or reuse

## Options considered

| Option | Pros | Cons |
|--------|------|------|
| A | … | … |
| B | … | … |

*(Omit the table if only one reasonable approach exists; then use a short "Why this approach" paragraph.)*

## Decision

[Chosen approach, 1–3 paragraphs. Explicit non-goals.]

## Architecture

- **Components / layers**: …
- **Data flow**: …
- **Contracts**: API / events / schema — …

## Risks and mitigations

- …

## Testing and validation (high level)

- …

## Handoff notes for implementation plan

- [Concern 1 — e.g. domain rule + repository]
- [Concern 2 — e.g. HTTP layer + FormRequest]
- …
```

### 5. Final Message to User

After saving the file, print:

- Full path to the saved design document
- One-paragraph summary of the decision
- Reminder: next step is `/create-implementation-plan` using this design + research

---

## Quality Rules

1. **Citations**: Non-obvious statements about current code must include `file:line`.
2. **No task files**: Do not create `.zcode/tasks/` here; only the design markdown.
3. **No production edits**: No changes under `app/`, `routes/`, `database/`, etc.
4. **Separation from research**: Do not re-write the whole research narrative; reference it and add **decisions** on top.
5. **Kebab-case filename** for `<kebab-topic>` aligned with the future task folder slug when possible.

## Good vs Bad

BAD: "We should refactor the service layer." (vague, no decision, no citations)

GOOD: "Extend `DocumentProcessingService` (`app/.../DocumentProcessingService.php:40`) with a new strategy matching `ExtractionHandler` (`...:88`); keep controllers thin per existing `StoreDocumentController` pattern (`...:15`)."

BAD: A 200-line pseudo-implementation in the design doc.

GOOD: Clear boundaries, one diagram-level description of flow, and handoff bullets for planners.
