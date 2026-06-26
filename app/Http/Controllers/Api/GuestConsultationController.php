<?php

namespace App\Http\Controllers\Api;

use App\Enums\Bidang;
use App\Http\Controllers\Controller;
use App\Services\GuestConsultationStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Stateless guest (no-login) consultation API (ADR-0003). No authentication —
 * the opaque token is the only handle. Every action is backed solely by Redis;
 * nothing here writes to the database.
 */
class GuestConsultationController extends Controller
{
    public function __construct(private GuestConsultationStore $store) {}

    /**
     * Start a session with the guest's first message. Routed by bidang only.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bidang' => ['required', new Enum(Bidang::class)],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $result = $this->store->start(Bidang::from($data['bidang']), $data['message']);

        return response()->json($result, 201);
    }

    /**
     * Append a guest message to a live session.
     */
    public function append(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        return response()->json($this->store->append($token, $data['message']), 201);
    }

    /**
     * Poll for messages after a cursor. Doubles as keepalive (refreshes TTL).
     */
    public function fetch(Request $request, string $token): JsonResponse
    {
        $after = (int) $request->integer('after', 0);

        return response()->json($this->store->fetch($token, $after));
    }
}
