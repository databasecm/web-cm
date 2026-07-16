<?php

namespace App\Services;

use App\Contracts\HasMedia;
use App\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single mechanism behind every media file (ADR-0015): validate → store →
 * serve → delete, on the private media disk. Validation is SERVER-SIDE from
 * config (never the client). Serving is only ever via {@see temporaryUrl()} — a
 * short-lived signed route that MediaController re-authorizes against the owning
 * module's policy. There is no public URL to a media file.
 */
class MediaService
{
    /**
     * Validate and store an uploaded file for a media-bearing model, returning
     * the storage key (to be saved into the model's media column). Does not touch
     * the model — the caller assigns the key. Keys are UUID-based, so no saved id
     * is required.
     */
    public function store(HasMedia $model, UploadedFile $file): string
    {
        $descriptor = $model->mediaDescriptor();
        $this->validate($file, $model);

        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $key = $descriptor->prefix.'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk($this->disk())->putFileAs(
            dirname($key),
            $file,
            basename($key),
        );

        return $key;
    }

    /**
     * Server-side validation against the model's descriptor profiles: MIME must
     * be allowed, and size must be within that MIME's profile ceiling.
     */
    public function validate(UploadedFile $file, HasMedia $model): void
    {
        if (! $file->isValid()) {
            throw MediaException::empty();
        }

        $descriptor = $model->mediaDescriptor();
        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mime, $descriptor->allowedMimes(), true)) {
            throw MediaException::unsupportedType($mime);
        }

        $kb = (int) ceil($file->getSize() / 1024);
        $maxKb = $descriptor->maxKbForMime($mime);
        if ($maxKb > 0 && $kb > $maxKb) {
            throw MediaException::tooLarge($kb, $maxKb);
        }
    }

    /**
     * Stream the model's file with its stored content type. 404 when the model
     * has no file or the object is missing. Authorization is the caller's
     * (MediaController) — this only serves bytes.
     */
    public function response(HasMedia $model): StreamedResponse
    {
        $key = $this->key($model);
        $disk = Storage::disk($this->disk());

        if ($key === null || ! $disk->exists($key)) {
            abort(404);
        }

        return $disk->response($key);
    }

    /** Remove the model's file from the disk (no-op if unset/missing). */
    public function delete(HasMedia $model): void
    {
        $key = $this->key($model);
        if ($key !== null) {
            Storage::disk($this->disk())->delete($key);
        }
    }

    /**
     * A short-lived SIGNED URL to the media route for this model. The route also
     * enforces the module policy, so the signature only proves the link is fresh.
     */
    public function temporaryUrl(HasMedia $model): string
    {
        return URL::temporarySignedRoute(
            'media.show',
            now()->addSeconds((int) config('media.url_ttl', 300)),
            ['type' => $this->alias($model), 'id' => $model->getKey()],
        );
    }

    private function key(HasMedia $model): ?string
    {
        $column = $model->mediaDescriptor()->column;

        return $model->{$column};
    }

    private function disk(): string
    {
        return (string) config('media.disk', 'media');
    }

    /** Reverse-lookup the URL alias for a model from config('media.models'). */
    private function alias(HasMedia $model): string
    {
        $alias = array_search($model::class, (array) config('media.models', []), true);

        if ($alias === false) {
            throw new MediaException($model::class.' tidak terdaftar di config media.models.');
        }

        return (string) $alias;
    }
}
