<?php

namespace EduLazaro\Laragents\Capabilities;

/**
 * Turns capabilities into what each provider calls them.
 *
 * A capability names a thing to do; every provider spells it differently, versions it
 * differently, or does not have it. Asking for one it cannot do drops it rather than
 * inventing a payload: a request rejected by the provider takes every other tool in the
 * call down with it, and pretending it worked is worse than doing without.
 */
class Translator
{
    /**
     * @param array<int, Capability> $capabilities
     * @return array<int, array<string, mixed>>
     */
    public static function forOpenAi(array $capabilities): array
    {
        $out = [];

        foreach ($capabilities as $capability) {
            $options = $capability->options();

            $out[] = match ($capability->name()) {
                'web_search' => ['type' => 'web_search'],
                'file_search' => [
                    'type' => 'file_search',
                    'vector_store_ids' => $options['store_ids'] ?? [],
                ],
                default => null,
            };
        }

        return array_values(array_filter($out));
    }

    /**
     * @param array<int, Capability> $capabilities
     * @return array<int, array<string, mixed>>
     */
    public static function forAnthropic(array $capabilities): array
    {
        $out = [];

        foreach ($capabilities as $capability) {
            $options = $capability->options();

            $out[] = match ($capability->name()) {
                // Versioned by date on their side, which is the whole reason this
                // translation exists rather than passing the name through.
                'web_search' => array_filter([
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                    'max_uses' => $options['max_uses'] ?? null,
                    'allowed_domains' => $options['allowed_domains'] ?? null,
                ]),

                // No equivalent: their files are the Files API plus a tool of yours.
                default => null,
            };
        }

        return array_values(array_filter($out));
    }
}
