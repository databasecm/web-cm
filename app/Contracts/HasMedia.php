<?php

namespace App\Contracts;

use App\Media\MediaDescriptor;

/**
 * A model that carries a media file handled by the shared media mechanism
 * (ADR-0015). The `file` column holds a storage key on the media disk; the
 * descriptor declares the folder, validation profiles and the policy ability
 * that guards serving it.
 */
interface HasMedia
{
    public function mediaDescriptor(): MediaDescriptor;
}
