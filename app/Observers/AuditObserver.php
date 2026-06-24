<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit-trail rows for models using the {@see Auditable}
 * trait. The trait supplies the redacted snapshots/diffs; this observer adds the
 * request-scoped actor (authenticated user) and IP.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->record('created', $model, before: null, after: $model->auditSnapshot());
    }

    public function updated(Model $model): void
    {
        $diff = $model->auditDiff();

        // Nothing auditable changed (e.g. only ignored columns like updated_at).
        if ($diff['before'] === [] && $diff['after'] === []) {
            return;
        }

        $this->record('updated', $model, before: $diff['before'], after: $diff['after']);
    }

    public function deleted(Model $model): void
    {
        $this->record('deleted', $model, before: $model->auditSnapshot(), after: null);
    }

    public function restored(Model $model): void
    {
        $this->record('restored', $model, before: null, after: $model->auditSnapshot());
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, Model $model, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => $model::class,
            'entity_id' => $model->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => request()->ip(),
        ]);
    }
}
