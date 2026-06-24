<?php

namespace App\Models\Concerns;

use App\Observers\AuditObserver;
use Illuminate\Support\Arr;

/**
 * Records create/update/delete/restore mutations to the `audit_logs` table
 * via {@see AuditObserver}. Reusable across modules that require an audit
 * trail (accounts now; transactions & financing later).
 *
 * Sensitive columns are never written: their values are replaced with a
 * redaction placeholder so the event is still recorded without leaking the
 * secret. By default the model's hidden attributes plus password/remember_token
 * are redacted; extend per-model with a `$auditExclude` array property.
 */
trait Auditable
{
    public const AUDIT_REDACTED = '[redacted]';

    /**
     * Register the audit observer. Laravel calls boot{TraitName} automatically.
     */
    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }

    /**
     * Attribute names whose values must never reach the audit trail.
     *
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return array_values(array_unique(array_merge(
            ['password', 'remember_token'],
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        )));
    }

    /**
     * Attribute names that are pure noise in an update diff. `deleted_at`
     * transitions are captured by the dedicated deleted/restored actions, so a
     * restore does not also emit a spurious "updated" row.
     *
     * @return list<string>
     */
    protected function auditIgnored(): array
    {
        return ['updated_at', 'deleted_at'];
    }

    /**
     * A redacted snapshot of the current attributes (for create/delete/restore).
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return $this->redactForAudit($this->getAttributes());
    }

    /**
     * The redacted before/after diff of the dirty attributes (for update).
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function auditDiff(): array
    {
        $changes = Arr::except($this->getChanges(), $this->auditIgnored());
        $keys = array_keys($changes);

        return [
            'before' => $this->redactForAudit(Arr::only($this->getOriginal(), $keys)),
            'after' => $this->redactForAudit($changes),
        ];
    }

    /**
     * Replace the value of every excluded attribute with the redaction
     * placeholder, preserving the key so the change is still visible.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function redactForAudit(array $attributes): array
    {
        foreach ($this->auditExcluded() as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = self::AUDIT_REDACTED;
            }
        }

        return $attributes;
    }
}
