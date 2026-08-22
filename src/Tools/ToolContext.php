<?php

namespace EduLazaro\Laragents\Tools;

/**
 * Whatever a tool needs from the host app, carried from the loop to the tool without
 * the loop ever opening it.
 *
 * This exists because the alternative does not scale. The app this package came from
 * declared its tools as:
 *
 *     execute(array $args, ?CaseModel $case, Organization $org): array
 *
 * Two app models nailed into an interface implemented by 95 tools. Adding a third piece
 * of context meant editing all 95, and no package could ever host that signature: a
 * package cannot know what a case is. One app's context is a case, another's is a
 * property or nothing at all.
 *
 * Put in whatever your tools need; they take out what they know about.
 */
final class ToolContext
{
    public function __construct(private array $items = []) {}

    public static function make(array $items = []): self
    {
        return new self($items);
    }

    public function with(string $key, mixed $value): self
    {
        return new self([...$this->items, $key => $value]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /** Throws when a tool needs something the caller did not provide. */
    public function require(string $key): mixed
    {
        if (! $this->has($key)) {
            throw new \RuntimeException("Missing '{$key}' in the tool context.");
        }

        return $this->items[$key];
    }

    public function all(): array
    {
        return $this->items;
    }
}
