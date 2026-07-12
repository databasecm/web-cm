<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Lists the financing banks a consumer can apply to (Fase 4-5). Only the id and
 * public name are exposed — no internal account data leaks.
 */
class BankListController extends Controller
{
    public function index(): JsonResponse
    {
        $banks = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', Role::NAME_MITRA_PEMBIAYAAN))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $bank): array => ['id' => $bank->id, 'name' => $bank->name]);

        return response()->json([
            'data' => $banks,
            'meta' => ['count' => $banks->count()],
        ]);
    }
}
