<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * A hard directive. The sibling of {@see Memory}, and the difference is not the shape but
 * who wrote it and how the model is meant to treat it.
 *
 * A memory is soft context, distilled from conversation, and the model weighs it. A rule
 * is written by a person, never distilled, and rendered at the top of the system prompt in
 * imperative language so the model treats it as non-negotiable. "The firm never quotes
 * fees over chat" is a rule. "This client tends to reply late" is a memory.
 *
 * Same two axes as Memory, for the same reasons:
 *
 *   memorable - what it is attached to: an organisation, a case, a project.
 *   context   - who it applies to. NULL means everyone in that scope, which is how an
 *               admin writes a rule for the whole firm; set, it is one person's own
 *               preference and applies to nobody else.
 *
 * That second axis is also the precedence: shared rules are rendered before personal ones
 * and worded as mandatory, so a user preference can never quietly override a firm policy.
 */
class Rule extends Model
{
    protected $table = 'laragents_rules';

    protected $fillable = [
        'memorable_type',
        'memorable_id',
        'context_type',
        'context_id',
        'created_by_type',
        'created_by_id',
        'content',
        'priority',
        'enabled',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function memorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    /** Audit only. Never decides what is visible, never reaches the model. */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /**
     * @param  array<Model>  $memorables  The scopes in play. Nulls are ignored, and the
     *                                    ORDER is kept: it becomes the order the blocks
     *                                    appear in the prompt.
     * @param  Model|null    $context     Who is asking. Null (an autonomous run with
     *                                    nobody behind it) sees only the shared rules,
     *                                    which is the safe reading: with no person to
     *                                    scope to, no personal preference applies.
     */
    public static function scopedTo(array $memorables, ?Model $context = null): Builder
    {
        $memorables = array_values(array_filter($memorables));

        if (empty($memorables)) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->where('enabled', true)
            ->where(function (Builder $query) use ($memorables) {
                foreach ($memorables as $memorable) {
                    $query->orWhere(function (Builder $scope) use ($memorable) {
                        $scope->where('memorable_type', $memorable->getMorphClass())
                            ->where('memorable_id', $memorable->getKey());
                    });
                }
            })
            ->where(function (Builder $query) use ($context) {
                $query->whereNull('context_id');

                if ($context) {
                    $query->orWhere(function (Builder $mine) use ($context) {
                        $mine->where('context_type', $context->getMorphClass())
                            ->where('context_id', $context->getKey());
                    });
                }
            });
    }

    public static function forSession(ChatSession $session): Builder
    {
        return static::scopedTo([$session->owner, $session->subject], $session->actor);
    }

    /**
     * The block to paste at the TOP of a system prompt. Empty string when there is
     * nothing, so callers can concatenate without checking.
     *
     * Shared rules come first and personal ones after, never interleaved. Flatten the two
     * together and the model loses the only signal that tells a firm policy from one
     * person's preference, and starts trading one off against the other.
     */
    public static function promptBlock(ChatSession $session, int $limit = 100): string
    {
        $rules = static::forSession($session)
            ->orderBy('priority')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($rules->isEmpty()) {
            return '';
        }

        $order = array_values(array_filter([$session->owner, $session->subject]));
        $blocks = [];

        // Shared before personal, and within each, the scopes in the order they were
        // given: the wider one first, so the narrower reads as a refinement of it.
        foreach (['shared', 'personal'] as $level) {
            foreach ($order as $memorable) {
                $lines = static::linesFor($rules, $memorable->getMorphClass(), $level);

                if ($lines->isEmpty()) {
                    continue;
                }

                $blocks[] = static::heading($level, $memorable->getMorphClass())
                    . "\n- " . $lines->implode("\n- ");
            }
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
    }

    protected static function linesFor(Collection $rules, string $morphClass, string $level): Collection
    {
        return $rules
            ->filter(fn (self $rule) => $rule->memorable_type === $morphClass
                && ($level === 'shared') === ($rule->context_id === null))
            ->map(fn (self $rule) => $rule->content)
            ->values();
    }

    /**
     * The wording is yours. The defaults are deliberately blunt, because a rule the model
     * treats as a suggestion is not a rule, but they are also in English and generic, and
     * yours are neither. Override laragents.rules.headings.
     */
    protected static function heading(string $level, string $scope): string
    {
        $headings = config('laragents.rules.headings') ?? [];

        $template = $headings[$level] ?? match ($level) {
            'shared' => 'MANDATORY INSTRUCTIONS FOR THIS :scope (do NOT skip them under any circumstance):',
            default => 'PREFERENCES OF THE CURRENT USER IN THIS :scope (respect them unless they contradict the mandatory instructions):',
        };

        // Per-scope override, for when "this case" and "this organisation" need different
        // wording rather than one template with a noun swapped in.
        if (isset($headings["{$level}.{$scope}"])) {
            $template = $headings["{$level}.{$scope}"];
        }

        return str_replace(':scope', $scope, $template);
    }
}
