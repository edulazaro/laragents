<?php

namespace EduLazaro\Laragents\Attributes;

use Attribute;

/**
 * Which runs a tool belongs to, declared at the top of the class.
 *
 *     #[Tags('matter', 'legal')]
 *     class ListMembers extends Tool
 *
 * Not every tool suits every conversation: some belong to one product, some only make
 * sense with a case open, some are for one agent. The registry filters on these, and a
 * tool that does not pass is one the model never learns exists.
 *
 * Here rather than in the registration call so the tool says what it is where you read
 * it, and registering stays one line. No tags means every run, which is the right
 * default: a tool nobody restricted is not restricted.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Tags
{
    /** @var array<int, string> */
    public readonly array $tags;

    /**
     * @param string ...$tags
     * @return void
     */
    public function __construct(string ...$tags)
    {
        $this->tags = $tags;
    }
}
