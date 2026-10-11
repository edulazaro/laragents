<?php

namespace EduLazaro\Laragents\Support;

use InvalidArgumentException;

/**
 * What OpenAI's strict mode requires of a schema, checked before the call.
 *
 * The two providers do not fail the same way on the same schema. OpenAI sends
 * `strict` and rejects with a 400 anything that leaves a property out of `required`
 * or omits `additionalProperties: false`. Anthropic has no strict mode: it declares
 * the schema as a tool's input and takes it as it comes. So a schema written against
 * Claude works until the day somebody switches provider, and then it breaks in
 * production.
 *
 * Checking it here makes the two behave alike: the same schema fails on both, in
 * development, naming the property, and without spending a call to find out.
 */
class StrictSchema
{
    /**
     * @throws InvalidArgumentException naming the exact path of the offending property.
     */
    public static function assert(array $schema, string $path = 'schema'): void
    {
        $type = $schema['type'] ?? null;

        if ($type === 'object' || isset($schema['properties'])) {
            $properties = array_keys($schema['properties'] ?? []);

            if (($schema['additionalProperties'] ?? null) !== false) {
                throw new InvalidArgumentException(
                    "Strict schema at `{$path}` needs `additionalProperties: false`. "
                    . 'Add it, or pass `strict: false` in the schema to call without strict mode.'
                );
            }

            $required = $schema['required'] ?? [];
            $missing = array_diff($properties, $required);

            if ($missing) {
                $names = implode(', ', $missing);
                throw new InvalidArgumentException(
                    "Strict schema at `{$path}` leaves out of `required`: {$names}. "
                    . 'Strict mode has no optional properties: list every one and make the '
                    . 'ones that may be absent nullable, or pass `strict: false`.'
                );
            }

            foreach ($schema['properties'] ?? [] as $name => $child) {
                if (is_array($child)) {
                    self::assert($child, "{$path}.properties.{$name}");
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            self::assert($schema['items'], "{$path}.items");
        }

        // A union still has to hold up in every branch it can take.
        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            foreach ($schema[$key] ?? [] as $i => $branch) {
                if (is_array($branch)) {
                    self::assert($branch, "{$path}.{$key}[{$i}]");
                }
            }
        }
    }
}
