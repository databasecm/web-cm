<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Enums\ReportMediaType;
use App\Exceptions\DailyReportException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Idempotent batch daily-report sync for the Mandor field app (Fase 5-4). Thin:
 * every item is settled through the tested DailyReportService (one report per
 * project/day, and progress is never advanced). Dedup by client_id; partial
 * batch — invalid items rejected with a reason.
 */
class DailyReportSyncController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.client_id' => ['required', 'uuid'],
            'items.*.project_id' => ['required', 'integer'],
            'items.*.date' => ['required', 'date'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.progress_note' => ['nullable', 'string', 'max:2000'],
            'items.*.media' => ['nullable', 'array'],
            'items.*.media.*.type' => ['required', new Enum(ReportMediaType::class)],
            'items.*.media.*.file' => ['nullable', 'string', 'max:255'],
            'items.*.media.*.caption' => ['nullable', 'string', 'max:255'],
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

            // Attach media only when the report was just created (not on a retry).
            if ($report->wasRecentlyCreated) {
                foreach ($item['media'] ?? [] as $media) {
                    $service->addMedia($report, ReportMediaType::from($media['type']), $media['file'] ?? null, $media['caption'] ?? null);
                }
            }

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
