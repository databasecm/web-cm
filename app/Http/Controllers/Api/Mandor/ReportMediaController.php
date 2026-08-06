<?php

namespace App\Http\Controllers\Api\Mandor;

use App\Exceptions\MediaException;
use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Binary photo/video upload for a daily report (Fase media-3, ADR-0015). A
 * multipart endpoint separate from the JSON report sync (5-4): the report text
 * syncs first (idempotent), then its media attaches here.
 *
 * Authorization: the Mandor must be able to write the report (own bidang) — the
 * `update` ability. A Mandor from another bidang is refused. The media `type`
 * (photo|video) is derived server-side from the upload's MIME, and size/type are
 * validated server-side by MediaService.
 */
class ReportMediaController extends Controller
{
    public function store(Request $request, DailyReport $report): JsonResponse
    {
        $mandor = $request->user();

        if ($mandor->cannot('update', $report)) {
            return response()->json(['message' => 'Di luar bidang Anda.'], 403);
        }

        $request->validate([
            'file' => ['required', 'file'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $media = app(DailyReportService::class)->attachMedia(
                $report,
                $request->file('file'),
                $request->input('caption'),
            );
        } catch (MediaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $media->id,
                'type' => $media->type->value,
                'caption' => $media->caption,
            ],
        ], 201);
    }
}
