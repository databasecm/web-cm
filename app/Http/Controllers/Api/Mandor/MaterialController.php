<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\MaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Field material catalog input for the Mandor app (Fase 6-5b). A Mandor adds a
 * material to the catalog (source=internal, input_by=mandor); the initial price
 * is journalled by MaterialObserver.
 *
 * SCOPE: catalog only — this never posts a cash-book transaction. A material
 * expense stays exclusively a PO received by Finance/O-D (Fase 6-5).
 */
class MaterialController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $mandor = $request->user();

        // Mandor may add catalog materials (MaterialPolicy::create, Fase 6-5b).
        if (! $mandor->can('create', Material::class)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'price' => ['required', 'numeric', 'min:0'],
            'spec' => ['nullable', 'string', 'max:1000'],
            'is_sni' => ['nullable', 'boolean'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_address' => ['nullable', 'string', 'max:255'],
        ]);

        $material = app(MaterialService::class)->addFromField($mandor, $data);

        return response()->json([
            'data' => [
                'id' => $material->id,
                'name' => $material->name,
                'price' => (string) $material->price,
                'source' => $material->source->value,
                'input_by' => $material->input_by,
            ],
        ], 201);
    }
}
