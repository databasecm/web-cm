<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only context for the Mandor field app (Fase 5-4): the projects and active
 * workers in the Mandor's OWN bidang (§6.4), to populate the offline picker.
 */
class FieldContextController extends Controller
{
    public function projects(Request $request): JsonResponse
    {
        $bidang = $request->user()->bidang;

        $projects = Project::query()
            ->where('bidang', $bidang?->value)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status->value,
            ]);

        return response()->json(['data' => $projects, 'meta' => ['count' => $projects->count()]]);
    }

    public function employees(Request $request): JsonResponse
    {
        $bidang = $request->user()->bidang;

        $employees = Employee::query()
            ->where('bidang', $bidang?->value)
            ->where('status', EmployeeStatus::Aktif->value)
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type->value,
                'daily_wage' => $e->daily_wage,
                'position' => $e->position,
            ]);

        return response()->json(['data' => $employees, 'meta' => ['count' => $employees->count()]]);
    }
}
