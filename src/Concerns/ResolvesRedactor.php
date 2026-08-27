<?php

namespace EduLazaro\Laragents\Concerns;

use EduLazaro\Laragents\Contracts\Redactor;
use EduLazaro\Laragents\Models\ChatSession;

/**
 * Whether a conversation gets redacted, in one place.
 *
 * It lived inside AgentLoop, private to it, which meant anything else that called a
 * model had nowhere to get the answer from and simply did not ask. That is how the
 * history compressor came to send transcripts in the clear out of a conversation the
 * user had marked private: not an oversight so much as a decision that belonged to one
 * class and was needed by three.
 */
trait ResolvesRedactor
{
    /**
     * The redactor for a session, or null when it is not to be redacted.
     *
     * Two switches, and both have to say yes: the app-wide option, and the per-session
     * flag on top of it. The global one is off by default because turning it on changes
     * what the model sees; the per-session one is on by default, so a product that
     * redacts does it unless this user asked otherwise in this conversation.
     *
     * @param ChatSession $session
     * @return Redactor|null
     */
    protected function redactorFor(ChatSession $session): ?Redactor
    {
        if (! config('laragents.privacy.enabled', false)) {
            return null;
        }

        if ($session->anonymize_pii === false) {
            return null;
        }

        // From the container, so an app with its own idea of what personal data is binds
        // its own and this never reaches laranon. The shipped one throws when laranon is
        // missing, on purpose: an app that believes it anonymises and does not is worse
        // than one that never claimed to.
        return app(Redactor::class);
    }
}
