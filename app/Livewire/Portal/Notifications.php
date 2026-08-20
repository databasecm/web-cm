<?php

namespace App\Livewire\Portal;

use App\Models\Bast;
use App\Models\DailyReport;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Installment;
use App\Models\Rab;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Consumer notification inbox (Fase portal P-7).
 *
 * A display surface over the SAME mechanism the Sanctum inbox uses (Fase 7-2):
 * `$user->notifications()` / `unreadNotifications()`, `markAsRead()`, and the
 * NotificationPolicy owner rule — nothing new. Every action is scoped to the
 * authenticated consumer's OWN notifications; one it does not own is
 * indistinguishable from one that does not exist (404, never a 403 that would
 * confirm existence), exactly as the API decides it.
 *
 * The stored body is already a neutral knock (no money, no sensitive value —
 * guaranteed at the 7-1 source); the portal only shows it. The detail lives
 * behind a portal `action_url` that the destination page re-authorizes on open
 * (the "ketukan pintu"), so the inbox is never a policy bypass.
 */
#[Layout('components.layouts.portal')]
class Notifications extends Component
{
    use WithPagination;

    /** When true, the list narrows to unread (mirrors the API `?unread=1`). */
    public bool $unreadOnly = false;

    /** Flip the unread filter and return to the first page. */
    public function toggleUnread(): void
    {
        $this->unreadOnly = ! $this->unreadOnly;
        $this->resetPage();
    }

    /**
     * Mark one notification read. Idempotent — an already-read row keeps its
     * original read_at (Laravel only stamps a null one). A notification this
     * consumer does not own resolves to 404 so its existence never leaks.
     */
    public function markRead(string $id): void
    {
        $this->ownedOrFail($id)->markAsRead();
    }

    /** Mark every unread notification read. Idempotent — no unread means no-op. */
    public function markAllRead(): void
    {
        // Query the relation fresh (method, not the cached property) so state is
        // current, matching NotificationController::markAllAsRead.
        auth()->user()->unreadNotifications()->get()->markAsRead();
    }

    /**
     * Resolve a notification this consumer owns, or 404. Ownership is the same
     * NotificationPolicy `update` rule the API gates on; a denial is mapped to
     * 404, not 403, so a stranger's notification never reveals itself.
     */
    private function ownedOrFail(string $id): DatabaseNotification
    {
        $notification = DatabaseNotification::find($id);

        abort_if(
            $notification === null || auth()->user()->cannot('update', $notification),
            404,
            'Notifikasi tidak ditemukan.'
        );

        return $notification;
    }

    /**
     * Map a notification's neutral entity pointer to the matching PORTAL page.
     * The stored `action_url` is a client-neutral path (Fase 7); the portal
     * rewrites it to its own route so a click lands inside /portal. The
     * destination re-checks ownership on mount, so an unresolved or foreign
     * target is harmless — worst case there is simply no link.
     *
     * @param  array<string, mixed>  $data
     */
    private function portalUrl(array $data): ?string
    {
        $id = $data['entity_id'] ?? null;

        if ($id === null) {
            return null;
        }

        // Resolve the owning project id for entities that hang off one, then
        // route to the page that shows that entity in the portal.
        return match ($data['entity_type'] ?? null) {
            'project' => route('portal.projects.show', $id),
            'bast' => $this->projectPage(Bast::whereKey($id)->value('project_id'), 'portal.projects.show'),
            'rab' => $this->projectPage(Rab::whereKey($id)->value('project_id'), 'portal.projects.show'),
            'daily_report' => $this->projectPage(DailyReport::whereKey($id)->value('project_id'), 'portal.projects.show'),
            'installment' => $this->projectPage(Installment::whereKey($id)->value('project_id'), 'portal.projects.payments'),
            'financing' => $this->projectPage(Financing::whereKey($id)->value('project_id'), 'portal.projects.financing'),
            'financing_document' => $this->projectPage(
                Financing::whereKey(FinancingDocument::whereKey($id)->value('financing_id'))->value('project_id'),
                'portal.projects.financing'
            ),
            // Staff-only subjects (payroll, purchase_order) a consumer never
            // receives — no portal destination, so no link.
            default => null,
        };
    }

    /** Build a portal route for a resolved project id, or null when it is missing. */
    private function projectPage(mixed $projectId, string $route): ?string
    {
        return $projectId === null ? null : route($route, $projectId);
    }

    public function render()
    {
        $notifications = ($this->unreadOnly
            ? auth()->user()->unreadNotifications()
            : auth()->user()->notifications()
        )->paginate(15);

        $actionUrls = [];
        foreach ($notifications as $notification) {
            $actionUrls[$notification->id] = $this->portalUrl($notification->data ?? []);
        }

        return view('livewire.portal.notifications', [
            'notifications' => $notifications,
            'actionUrls' => $actionUrls,
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
