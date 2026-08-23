<?php

namespace EduLazaro\Laragents\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns text into a vector.
 *
 * Separate from ChatClient on purpose. Anthropic does not do embeddings at all, so a
 * chat client forced to carry embed() would have one implementation that only ever
 * throws, and callers would have to know which is which. Two contracts, and a provider
 * implements the ones it can actually honour.
 *
 * Optional. With nothing bound, memories are recalled by recency, which is what this
 * package did before embeddings existed and what it falls back to when the provider is
 * unreachable.
 */
interface Embedder
{
    /**
     * @param  Model|null  $owner  Who pays. Null is platform cost and is not metered.
     * @return array<int, float>
     */
    public function embed(
        string $text,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'embedding',
        ?Model $subject = null,
    ): array;
}
