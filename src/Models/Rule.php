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
        'tenant_type',
        'tenant_id',
        'memorable_type',
        'memorable_id',
        'memory_type',
        'memory_id',
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

    /**
     * Tenant.
     *
     * @return MorphTo
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Memorable.
     *
     * @return MorphTo
     */
    public function memorable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Holder.
     *
     * @return MorphTo
     */
    public function holder(): MorphTo
    {
        return $this->morphTo('memory');
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
    public static function scopedTo(array $memorables, ?Model $context = null, ?Model $tenant = null): Builder
    {
        $memorables = array_values(array_filter($memorables));

        if (empty($memorables) && ! $tenant) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->where('enabled', true)
            ->when($tenant, fn (Builder $query) => $query
                ->where('tenant_type', $tenant->getMorphClass())
                ->where('tenant_id', $tenant->getKey()))
            ->where(function (Builder $query) use ($memorables, $tenant) {
                // A rule with no memorable is a rule for the whole tenant, which is what
                // "how this firm writes" is.
                if ($tenant) {
                    $query->whereNull('memorable_id');
                }

                foreach ($memorables as $memorable) {
                    $query->orWhere(function (Builder $scope) use ($memorable) {
                        $scope->where('memorable_type', $memorable->getMorphClass())
                            ->where('memorable_id', $memorable->getKey());
                    });
                }
            })
            ->where(function (Builder $query) use ($context) {
                $query->whereNull('memory_id');

                if ($context) {
                    $query->orWhere(function (Builder $mine) use ($context) {
                        $mine->where('memory_type', $context->getMorphClass())
                            ->where('memory_id', $context->getKey());
                    });
                }
            });
    }

    /**
     * For session.
     *
     * @param ChatSession $session
     * @return Builder
     */
    public static function forSession(ChatSession $session): Builder
    {
        return static::scopedTo([$session->sessionable], $session->actor, $session->tenant);
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

        // The tenant first (its rules have no memorable: they are not about any one
        // subject), then the subject of this session. Grouping by morph alias alone would
        // merge the two whenever they happen to share a class, and each rule would come
        // out twice under the wrong heading.
        $order = [];

        if ($session->tenant) {
            $order[] = [null, $session->tenant->getMorphClass()];
        }

        if ($session->sessionable) {
            $order[] = [$session->sessionable, $session->sessionable->getMorphClass()];
        }

        $blocks = [];

        // Shared before personal, and within each, the scopes in the order they were
        // given: the wider one first, so the narrower reads as a refinement of it.
        foreach (['everyone', 'actor'] as $level) {
            foreach ($order as [$memorable, $label]) {
                $lines = static::linesFor($rules, $memorable, $level);

                if ($lines->isEmpty()) {
                    continue;
                }

                $blocks[] = static::heading($level, $label)
                    . "\n- " . $lines->implode("\n- ");
            }
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
    }

    /**
     * Lines for.
     *
     * @param  Collection   $rules
     * @param  Model|null   $memorable  The subject to pick out, or null for the rules that
     *                                  belong to the tenant as a whole.
     * @param  string       $level
     * @return Collection
     */
    protected static function linesFor(Collection $rules, ?Model $memorable, string $level): Collection
    {
        $matches = $memorable
            ? fn (self $rule) => $rule->memorable_type === $memorable->getMorphClass()
                && (string) $rule->memorable_id === (string) $memorable->getKey()
            : fn (self $rule) => $rule->memorable_id === null;

        return $rules
            ->filter(fn (self $rule) => $matches($rule)
                && ($level === 'everyone') === ($rule->memory_id === null))
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
            'everyone' => 'MANDATORY INSTRUCTIONS FOR THIS :scope (do NOT skip them under any circumstance):',
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
