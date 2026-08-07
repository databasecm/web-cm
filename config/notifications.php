<?php

/*
|--------------------------------------------------------------------------
| Notifications (Fase 7)
|--------------------------------------------------------------------------
|
| One place configures which channels every notification goes out on. We ship
| with the built-in `database` channel only (an in-app bell/inbox). Adding
| `mail` or a WhatsApp channel at go-live is a config change here — the base
| notification's via() reads this list, so no business TRIGGER in any service
| changes (A3-style, exactly like config/media.php).
|
| Design rules that outlive this file (enforced in code + the Fase 7 DoD gate):
|   - A notification body is a KNOCK, never the detail. It NEVER carries a money
|     amount or a sensitive document — those live behind the policy-guarded
|     `action_url`. A persisted body (DB row today, a third-party email/WA
|     tomorrow) is a permanent leak surface, so it stays neutral.
|   - Recipients are resolved from EXISTING relations/policies (§6). When in
|     doubt, notify no one — silence never leaks across a boundary.
|
*/

return [
    // Channels every notification is delivered on. `database` now; append
    // 'mail' / a WhatsApp channel at go-live (+ credentials) with no trigger
    // change. Comma-separated env override keeps deploys config-only.
    'channels' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NOTIFICATION_CHANNELS', 'database'))
    ))) ?: ['database'],
];
