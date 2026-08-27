<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Skills\SkillContext;
use EduLazaro\Laragents\Skills\SkillRegistry;

/**
 * The door a model uses to run a named procedure from inside a conversation.
 *
 * It is a tool whose job is to start a skill, which is a whole agent turn of its own:
 * its own instructions, its own subset of tools, its own loop. What comes back to the
 * conversation is only the deliverable.
 *
 * It runs headless, writing none of the inner turns. The outer conversation owns its
 * history, and interleaving another agent's plumbing into it would break the pairing
 * between assistant turns and their tool results when the history is replayed.
 *
 * Its description is built at runtime from the catalogue, so registering a skill is
 * enough for the model to learn it exists.
 *
 * One convention, and nothing enforces it: a skill must not list `run_skill` among its
 * own tools.
 */
class RunSkillTool extends Tool
{
    /**
     * @param SkillRegistry $skills
     * @return void
     */
    public function __construct(protected SkillRegistry $skills)
    {
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return 'run_skill';
    }

    /**
     * What it does, with the catalogue rendered into it.
     *
     * @return string
     */
    public function description(): string
    {
        $catalogue = $this->skills->catalogue();

        $lines = $catalogue === []
            ? '(none registered)'
            : implode("\n", array_map(
                fn (array $skill) => "- {$skill['name']}: {$skill['description']}",
                $catalogue,
            ));

        return 'Run a named procedure (a "skill"): a multi-step process carried out against '
            . 'a fixed set of tools, which returns a deliverable. Use it when the user asks '
            . "for one of these, rather than improvising the steps yourself.\n\n"
            . "Available skills:\n" . $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skill' => [
                    'type' => 'string',
                    'enum' => array_column($this->skills->catalogue(), 'name'),
                    'description' => 'Which skill to run, from the catalogue.',
                ],
                'args' => [
                    'type' => 'object',
                    'description' => "The skill's own parameters. Omit when it needs none.",
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['skill'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param ToolContext $context
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $name = (string) ($args['skill'] ?? '');

        if (! $this->skills->has($name)) {
            return ['error' => "No skill named '{$name}'."];
        }

        $session = $context->get('session');

        if (! $session instanceof ChatSession) {
            return ['error' => 'run_skill needs a conversation to run in.'];
        }

        // The model it was called on, so a skill inherits the tier of the turn that
        // asked for it rather than falling back to a default nobody chose.
        $model = (string) $context->get('model', '');

        $result = $this->skills->run($name, new SkillContext(
            session: $session,
            context: $context,
            args: (array) ($args['args'] ?? []),
            model: $model,
            persist: false,
        ));

        return [
            'skill' => $name,
            'content' => $result->content,
            'tokens' => $result->totalTokens(),
        ];
    }
}
