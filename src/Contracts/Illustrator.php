<?php

namespace EduLazaro\Laragents\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Makes images from a description.
 *
 * Its own contract, like Speaker and Embedder: not every provider does it.
 *
 * Returns the bytes of each image and writes nothing, for the same reason: storage is
 * the app's decision.
 */
interface Illustrator
{
    /**
     * Draw something.
     *
     * @param string $prompt
     * @param int $count How many, when the provider allows more than one.
     * @param string|null $size Provider's size string, e.g. '1024x1024'. Null takes its default.
     * @param Model|null $owner Who pays. Null is platform cost and is not metered.
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @return array<int, string> Raw image bytes, one per image.
     */
    public function draw(
        string $prompt,
        int $count = 1,
        ?string $size = null,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'image',
        ?Model $subject = null,
    ): array;
}
