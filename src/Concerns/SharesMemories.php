<?php

namespace EduLazaro\Laragents\Concerns;

/**
 * Put this on whoever talks to the model, and they get a say in whether what they say
 * ends up in everyone else's memory.
 *
 * Three states, not two, and the third is the point. NULL means this person never chose,
 * so the configured default applies and you can change your mind about the default later
 * without overwriting anybody. A stored true or false is a decision they made, and it
 * wins over the config for ever.
 *
 * It gates only what gets WRITTEN from here on. Nothing already stored is hidden or
 * removed: turning it off is not a retraction.
 */
trait SharesMemories
{
    public function sharesMemories(): bool
    {
        $chosen = $this->getAttribute('share_memories');

        if ($chosen === null) {
            return (bool) config('laragents.memory.share_memories', true);
        }

        return (bool) $chosen;
    }

    /** Record a decision, which from now on beats the configured default. */
    public function setMemorySharing(?bool $shares): void
    {
        $this->forceFill(['share_memories' => $shares])->save();
    }
}
