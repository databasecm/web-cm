<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app inbox for token clients — the consumer app and the Mandor PWA
 * (Fase 7-2). Internal staff read the same notifications through the Filament
 * bell instead. Every action is scoped to the authenticated account's own
 * notifications: one account can never see, count, or mark another's, and a
 * notification it does not own is indistinguishable from one that does not
 * exist (404, never a 403 that would confirm existence).
 *
 * No business notifications are produced yet — this is the read surface only;
 * the triggers land in 7-3..7-6.
 */
class NotificationController extends Controller
{
    /**
     * The account's notifications, newest first. `?unread=1` narrows to unread.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        return NotificationResource::collection($query->paginate(20));
    }

    /**
     * How many of the account's notifications are still unread.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one notification read. Idempotent — already-read keeps its original
     * read_at. A notification the account does not own resolves to 404 so its
     * existence is never revealed.
     */
    public function markAsRead(Request $request, string $id): NotificationResource
    {
        $notification = $this->ownedOrFail($request, $id);

        $notification->markAsRead();

        return (new NotificationResource($notification->fresh()))
            ->additional(['meta' => ['message' => 'Notifikasi ditandai dibaca.']]);
    }

    /**
     * Mark every unread notification read. Idempotent — no unread means no-op.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        // Query the relation fresh (method, not the cached property) so a repeat
        // call sees the current read state rather than a stale in-memory set.
        $unread = $request->user()->unreadNotifications()->get();
        $marked = $unread->count();
        $unread->markAsRead();

        return response()->json([
            'data' => ['marked' => $marked],
            'meta' => ['message' => 'Semua notifikasi ditandai dibaca.'],
        ]);
    }

    /**
     * Resolve a notification the account owns, or 404. Ownership is decided by
     * NotificationPolicy (7-1); a denial is mapped to 404, not 403, so a
     * stranger's notification never leaks its existence.
     */
    private function ownedOrFail(Request $request, string $id): DatabaseNotification
    {
        $notification = DatabaseNotification::find($id);

        abort_if(
            $notification === null || $request->user()->cannot('update', $notification),
            404,
            'Notifikasi tidak ditemukan.'
        );

        return $notification;
    }
}
