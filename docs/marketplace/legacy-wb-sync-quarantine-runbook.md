# Legacy WB sync quarantine runbook

## Alert signal

Legacy WB sync fail-fast paths log ERROR events in Monolog channel `legacy_wb_sync`.
The production Monolog `sentry` handler accepts this channel and forwards ERROR-level
events to Sentry/GlitchTip.

Use the stable log/Sentry marker:

```text
legacy_event=legacy_wb_sync_fail_fast
```

Each event includes:

- `company_id` when available;
- `connection_id` when available;
- `command_class` and/or `message_class`;
- `recommended_replacement`.

## Quarantine period

Keep the legacy WB entrypoints disabled and monitored for 2–4 weeks. The quarantine
window can be closed only after there are no `legacy_wb_sync_fail_fast` events for
the entire selected period.

Recommended checks:

1. Daily: check Sentry/GlitchTip for `legacy_event=legacy_wb_sync_fail_fast`.
2. Weekly: check container logs for the same marker if Sentry ingestion was degraded.
3. If any event appears, record its `company_id`, `connection_id`, `command_class` or
   `message_class`, migrate the caller to `recommended_replacement`, and restart the
   quarantine window.
