<?php

namespace EduLazaro\Laragents\Capabilities;

/**
 * Let the model search the open web itself.
 *
 * Worth weighing before switching on: it is the same job a scraping tool of yours does,
 * done by someone else, billed inside the reply and pointed at whatever the provider
 * decides to read. For "what happened this week" that is the right trade. For an answer
 * that has to cite a source you trust, it is not: you cannot aim it at your own corpus,
 * and a model that searched will cite what it found.
 */
class WebSearch implements Capability
{
    /**
     * @param int|null $maxUses Cap on searches per reply, where the provider allows one.
     * @param array<int, string> $allowedDomains Restrict it, where the provider allows it.
     * @return void
     */
    public function __construct(
        protected ?int $maxUses = null,
        protected array $allowedDomains = [],
    ) {
    }

    /**
     * Its name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'web_search';
    }

    /**
     * Its settings, if any.
     *
     * @return array
     */
    public function options(): array
    {
        return array_filter([
            'max_uses' => $this->maxUses,
            'allowed_domains' => $this->allowedDomains ?: null,
        ]);
    }
}
