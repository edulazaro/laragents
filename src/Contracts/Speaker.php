<?php

namespace EduLazaro\Laragents\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns text into speech. The other direction from Transcriber.
 *
 * Its own contract for the reason the others are: Anthropic does not do this, so a chat
 * client carrying it would have an implementation that only ever throws.
 *
 * Returns the audio itself, bytes, and writes nothing. Where it is stored, under what
 * name and for how long is the app's decision, and a package that picked a disk for you
 * would be wrong for most of them.
 */
interface Speaker
{
    /**
     * Read a piece of text aloud.
     *
     * @param string $text
     * @param string|null $voice Provider's voice name. Null takes its default.
     * @param Model|null $owner Who pays. Null is platform cost and is not metered.
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @return string Raw audio bytes, in the provider's default format.
     */
    public function speak(
        string $text,
        ?string $voice = null,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'speech',
        ?Model $subject = null,
    ): string;
}
