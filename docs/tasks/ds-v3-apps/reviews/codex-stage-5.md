# Stage5 — reviews

Branch feat/ds-v3-stage-5, PR3039, stage base03a34a2db.

## Independent internal

Fresh session, original task/source/checklist +complete owned diff, no
implementation history. Read-only, no independent test execution.
B/I/M0/1/1: simultaneous nested dialog child-first opening (IMPORTANT),
Tags Escape→typing failed to resume suggestions (MINOR). Both browser RED
reproduced, fixed, full47PASS; focused INTERNAL_REVIEW_GREEN/open0.
Opening queue batches one microtask and opens ancestors before children,
capturing native focus/scroll ownership at actual open; cancellation/StrictMode/
unmount inspected. Later required/form fix also independently reviewed:
INTERNAL_REVIEW_GREEN,48browserPASS, no new findings.

Author IMPORTANT3fixed with RED→GREEN: picker reopen state reset race; invalid
ISO calendar rollover; Tag Escape swallowing containing modal dismissal.
Internal totals B/I0/4fixed, MINOR1fixed, open0.

## External Codex — round1

Claude CLI absent. Automatic fallback per AGENTS §7.2 /external-review.md:
separate fresh `codex exec -s read-only --ephemeral`, effortmedium, timeout900.
Static stdin-only packet517576B: original task/plan/checklist/8source sheets/
ds-kit.css/current tokens/styles/base support and complete owned diff156819B.
Tools/writes/Git mutation/network/nested reviews forbidden. No environment,
credentials or production data. bwrap shell unavailable was established earlier;
no sandbox/permission expansion.

Result: no BLOCKER, one IMPORTANT — TagsInput required checked the draft
editor instead of committed array. No raw REVIEW_GREEN marker claimed.
Confirmed with browser RED: unfinished text passed validation while selected
tags left cleared required editor invalid. Fixed by logical required/custom
validity tied to committed tags, aria-required, hidden repeated-name fields
for FormData; editor draft unnamed, form/disabled/ref retained.
Regression empty→draft→add→draft→remove-last +FormData and SSR contract PASS;
full48browserPASS and independent focused INTERNAL_REVIEW_GREEN.

**External result: fixed without re-run**, per §7.2 (only IMPORTANT fixed;
no BLOCKER). Rounds1, B/I0/1fixed, open0. Artifacts local only:
/tmp/vf-ds-v3-stage5/external and required-tags-{red,green}.log.
