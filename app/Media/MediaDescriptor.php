<?php

namespace App\Media;

/**
 * Declares how one model's media file is handled (ADR-0015): where it is stored,
 * which validation profiles are allowed, and which policy ability guards viewing
 * it. Each media-bearing model returns one of these from `mediaDescriptor()`.
 */
class MediaDescriptor
{
    /**
     * @param  string  $prefix  storage folder prefix (e.g. "designs")
     * @param  list<string>  $profiles  allowed profile keys from config('media.profiles')
     * @param  string  $viewAbility  policy ability that authorizes serving the file
     * @param  string  $column  the model column holding the storage key
     */
    public function __construct(
        public readonly string $prefix,
        public readonly array $profiles,
        public readonly string $viewAbility = 'view',
        public readonly string $column = 'file',
    ) {}

    /**
     * Flattened allowed MIME types across this descriptor's profiles.
     *
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        $mimes = [];
        foreach ($this->profiles as $profile) {
            foreach ((array) config("media.profiles.{$profile}.mimes", []) as $mime) {
                $mimes[] = $mime;
            }
        }

        return array_values(array_unique($mimes));
    }

    /**
     * The largest max-size (KB) among this descriptor's profiles — the ceiling a
     * single upload may reach (per-mime limits still apply via allowedMimes).
     */
    public function maxKb(): int
    {
        $max = 0;
        foreach ($this->profiles as $profile) {
            $max = max($max, (int) config("media.profiles.{$profile}.max_kb", 0));
        }

        return $max;
    }

    /**
     * The max size (KB) allowed for a specific MIME type — the profile that
     * lists it. Returns 0 if the MIME is not allowed at all.
     */
    public function maxKbForMime(string $mime): int
    {
        foreach ($this->profiles as $profile) {
            $mimes = (array) config("media.profiles.{$profile}.mimes", []);
            if (in_array($mime, $mimes, true)) {
                return (int) config("media.profiles.{$profile}.max_kb", 0);
            }
        }

        return 0;
    }
}
