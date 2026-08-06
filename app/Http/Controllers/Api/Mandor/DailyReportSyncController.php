<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Exceptions\DailyReportException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Idempotent batch daily-report sync for the Mandor field app (Fase 5-4). Thin:
 * every item is settled through the tested DailyReportService (one report per
 * project/day, and progress is never advanced). Dedup by client_id; partial
 * batch — invalid items rejected with a reason.
 *
 * TEXT ONLY: photos/videos are binary and no longer travel in this JSON batch —
 * they upload through POST /mandor/daily-reports/{report}/media (ADR-0015,
 * Fase media-3), attaching to a report that is already synced.
 */
class DailyReportSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Text-only report sync (idempotent by client_id). Media is NOT part of
        // the batch — photos/videos are binary and upload through the dedicated
        // multipart endpoint POST /mandor/daily-reports/{report}/media (ADR-0015).
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.client_id' => ['required', 'uuid'],
            'items.*.project_id' => ['required', 'integer'],
            'items.*.date' => ['required', 'date'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.progress_note' => ['nullable', 'string', 'max:2000'],
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

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function process(User $mandor, array $item): array
    {
        $clientId = $item['client_id'];
        $project = Project::find($item['project_id']);

        if ($project === null) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => 'Proyek tidak ditemukan.'];
        }

        if (! $mandor->can('createDailyReport', $project)) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => 'Di luar bidang Anda.'];
        }

        try {
            $service = app(DailyReportService::class);
            $report = $service->create(
                $project,
                $mandor,
                $item['date'],
                $item['description'],
                $item['progress_note'] ?? null,
                $clientId,
            );

            return [
                'client_id' => $clientId,
                'status' => $report->wasRecentlyCreated ? 'created' : 'duplicate',
                'id' => $report->id,
            ];
        } catch (DailyReportException $e) {
            return ['client_id' => $clientId, 'status' => 'rejected', 'reason' => $e->getMessage()];
        }
    }
}
