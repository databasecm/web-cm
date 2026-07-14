<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Enums\AttendanceStatus;
use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Idempotent batch attendance sync for the Mandor field app (Fase 5-4). Thin:
 * every item is settled through the tested AttendanceService (so anti-double,
 * bidang and active-worker guards hold from the API too).
 *
 * Each item carries a unique client_id; the server dedups by it, so a retried
 * batch (lost signal → resend) never double-records a wage. The batch is
 * PARTIAL: valid items are processed, invalid ones are rejected with a reason —
 * one bad item never fails the whole batch.
 */
class AttendanceSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.client_id' => ['required', 'uuid'],
            'items.*.employee_id' => ['required', 'integer'],
            'items.*.project_id' => ['required', 'integer'],
            'items.*.date' => ['required', 'date'],
            'items.*.status' => ['required', new Enum(AttendanceStatus::class)],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $mandor = $request->user();
        $results = [];
        $counts = ['created' => 0, 'duplicate' => 0, 'rejected' => 0];

        foreach ($data['items'] as $item) {
            $result = $this->process($mandor, $item);
            $results[] = $result;
            $counts[$result['status']]++;
        }

        return response()->json(['data' => $results, 'meta' => $counts]);
    }

    /** Recap of a day's attendance in the Mandor's bidang (client verification). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        $bidang = $request->user()->bidang;

        $rows = Attendance::query()
            ->whereDate('date', $data['date'])
            ->whereHas('employee', fn ($q) => $q->where('bidang', $bidang?->value))
            ->get()
            ->map(fn (Attendance $a): array => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'employee_id' => $a->employee_id,
                'project_id' => $a->project_id,
                'date' => $a->date->toDateString(),
                'status' => $a->status->value,
            ]);

        return response()->json(['data' => $rows, 'meta' => ['count' => $rows->count()]]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function process(User $mandor, array $item): array
    {
        $clientId = $item['client_id'];
        $employee = Employee::find($item['employee_id']);
        $project = Project::find($item['project_id']);

        if ($employee === null || $project === null) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => 'Karyawan atau proyek tidak ditemukan.'];
        }

        // Bidang scope: a Mandor may only record its own bidang's workers.
        if (! $mandor->can('recordAttendance', $employee)) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => 'Di luar bidang Anda.'];
        }

        try {
            $attendance = app(AttendanceService::class)->record(
                $employee,
                $project,
                $item['date'],
                AttendanceStatus::from($item['status']),
                $mandor,
                $item['note'] ?? null,
                $clientId,
            );

            return [
                'client_id' => $clientId,
                'status' => $attendance->wasRecentlyCreated ? 'created' : 'duplicate',
                'id' => $attendance->id,
            ];
        } catch (AttendanceException $e) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => $e->getMessage()];
        }
    }
}
