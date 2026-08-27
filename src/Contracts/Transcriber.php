<?php

namespace EduLazaro\Laragents\Contracts;

use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns recorded speech into text.
 *
 * Its own contract for the same reason Embedder is: Anthropic does not transcribe. A
 * ChatClient forced to carry this would have one implementation that only ever throws,
 * and callers would have to know which is which.
 *
 * Optional. Nothing in this package calls it; it is here so an app that records
 * meetings or voice notes does not have to write the provider plumbing again.
 */
interface Transcriber
{
    /**
     * Transcribe an audio file.
     *
     * The response carries the text in `content`. Input tokens are an estimate from the
     * duration, because providers bill audio by the minute and the caller still needs a
     * number to meter.
     *
     * @param  string  $filePath  A local path, read by the client.
     * @param  Model|null  $owner  Who pays. Null is platform cost and is not metered.
     * @param  string|null  $language  ISO code. Null lets the provider detect it.
     * @return Response
     */
    public function transcribe(
        string $filePath,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'transcription',
        ?Model $subject = null,
        ?string $language = null,
    ): Response;
}
