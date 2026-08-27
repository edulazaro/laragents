<?php

namespace EduLazaro\Laragents\Skills;

use EduLazaro\Laragents\Contracts\Skill;
use InvalidArgumentException;

/**
 * The skill catalogue.
 *
 * Holds class names and resolves them through the container lazily: booting does not
 * instantiate every skill nor its dependencies, and a skill is free to ask for what it
 * needs through its constructor.
 *
 * The mirror of ToolRegistry, but holding classes rather than instances. A tool is
 * cheap to keep around; a skill is a procedure with dependencies behind it.
 */
class SkillRegistry
{
    /** @var array<int, class-string<Skill>> */
    protected array $classes = [];

    /** @var array<string, Skill>|null Name to instance, memoised after first use. */
    protected ?array $resolved = null;

    /**
     * Add one or several skills, by class name.
     *
     * @param class-string<Skill>|array<int, class-string<Skill>> $skill
     * @return void
     */
    public function register(string|array $skill): void
    {
        foreach ((array) $skill as $class) {
            $this->classes[] = $class;
        }

        $this->resolved = null;
    }

    /**
     * Every registered skill, keyed by name.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $map = [];

        foreach (array_unique($this->classes) as $class) {
            $skill = app($class);
            $map[$skill->name()] = $skill;
        }

        return $this->resolved = $map;
    }

    /**
     * One skill by name, or null.
     *
     * @param string $name
     * @return Skill|null
     */
    public function get(string $name): ?Skill
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Whether a skill is registered.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * The catalogue as rows, for a menu or for a prompt.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function catalogue(): array
    {
        return array_map(
            fn (Skill $skill) => ['name' => $skill->name(), 'description' => $skill->description()],
            array_values($this->all()),
        );
    }

    /**
     * Resolve a skill by name and run it.
     *
     * @param string $name
     * @param SkillContext $context
     * @return SkillResult
     * @throws InvalidArgumentException When no such skill is registered.
     */
    public function run(string $name, SkillContext $context): SkillResult
    {
        $skill = $this->get($name);

        if (! $skill) {
            $known = implode(', ', array_keys($this->all())) ?: 'none';

            throw new InvalidArgumentException("laragents: no skill named [{$name}]. There is: {$known}.");
        }

        return $skill->run($context);
    }
}
