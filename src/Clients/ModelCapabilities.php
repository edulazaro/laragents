<?php

namespace EduLazaro\Laragents\Clients;

/**
 * Which parameters each model actually accepts.
 *
 * This exists because the obvious assumption is wrong: what a model takes is a property
 * of the model, not something you can infer from the provider or the family name.
 *
 * Reasoning models differ in two ways. They accept only the default `temperature`, and
 * anything else is a 400. And their output cap counts the reasoning tokens you never
 * see, so a cap sized for the visible answer can be spent entirely on thinking and
 * return empty content with a truncation reason.
 *
 * So neither is sent to a reasoning model even when the caller asks: the number they
 * asked for was calculated for something else.
 *
 * Anthropic cannot be generalised at all, MEASURED against the live API on 22 Aug 2026:
 *
 *     claude-opus-4-8   + temperature -> 400 "`temperature` is deprecated for this model."
 *     claude-sonnet-4-6 + temperature -> 200 OK
 *
 * Same provider, same generation, opposite answers. Hence an explicit list rather than a
 * rule. When you add a model here, measure it first; do not reason about it.
 *
 * An unknown model is treated as permissive ON PURPOSE. Get it wrong that way and the
 * API returns a 400 you see the same day. Get it wrong the other way and you silently
 * stop sending something, which is the failure that ran for five months unnoticed in the
 * app this came from.
 */
final class ModelCapabilities
{
    /** OpenAI reasoning families. */
    private const REASONING = ['gpt-5', 'o1', 'o3', 'o4'];

    /** gpt-5-chat-latest is NOT a reasoning model despite the prefix. */
    private const REASONING_EXCEPTIONS = ['gpt-5-chat'];

    /** Models that reject `temperature` without being an OpenAI reasoning model. */
    private const NO_TEMPERATURE = ['claude-opus-4-8'];

    public static function isReasoning(string $model): bool
    {
        foreach (self::REASONING_EXCEPTIONS as $exception) {
            if (str_starts_with($model, $exception)) {
                return false;
            }
        }

        foreach (self::REASONING as $family) {
            if (str_starts_with($model, $family)) {
                return true;
            }
        }

        return false;
    }

    public static function supportsTemperature(string $model): bool
    {
        foreach (self::NO_TEMPERATURE as $blocked) {
            if (str_starts_with($model, $blocked)) {
                return false;
            }
        }

        return ! self::isReasoning($model);
    }

    /** An output cap only means "length of the answer" outside the reasoning models. */
    public static function supportsOutputCap(string $model): bool
    {
        return ! self::isReasoning($model);
    }
}
