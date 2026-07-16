<?php

namespace App\Http\Controllers;

use App\Contracts\HasMedia;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves media files (ADR-0015) behind two layers:
 *  1. the route is SIGNED (short TTL) — an expired/forged link is 403 before we
 *     get here (the `signed` middleware);
 *  2. this method re-checks the owning module's POLICY for the current user —
 *     so a fresh link still can't reveal a file the user may not see (sensitive
 *     financing documents can never leak by a guessed/shared URL).
 *
 * There is no other way to reach a media file; the disk is private.
 */
class MediaController extends Controller
{
    public function show(Request $request, string $type, int $id): StreamedResponse
    {
        $class = config("media.models.{$type}");

        if (! is_string($class) || ! is_subclass_of($class, HasMedia::class)) {
            abort(404);
        }

        /** @var HasMedia&Model $model */
        $model = $class::query()->findOrFail($id);

        $ability = $model->mediaDescriptor()->viewAbility;
        if ($request->user() === null || $request->user()->cannot($ability, $model)) {
            abort(403);
        }

        return app(MediaService::class)->response($model);
    }
}
