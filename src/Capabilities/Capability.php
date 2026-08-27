<?php

namespace EduLazaro\Laragents\Capabilities;

/**
 * Something the model can do on the provider's side.
 *
 * Not a Tool. A tool is a class of yours that the loop runs against your database: you
 * see the call, you charge for it, you log it. A capability is switched on and the
 * provider does it itself, so nothing reaches your server and you find out only because
 * the answer already has the information in it.
 *
 * Which is why it has no execute() and no parameters(): there is nothing to run and
 * nothing for you to define. It is a switch, and each provider spells it differently.
 *
 * Three consequences worth knowing before turning one on, all of them following from
 * "you never see the call":
 *
 *   - it does not pass through your redactor, so a redacted conversation searches for
 *     the marker;
 *   - it does not pass through your usage recorder, since the cost arrives inside the
 *     reply's tokens rather than as a call you can price;
 *   - it does not pass through the empty-streak guard, since you never see the result.
 */
interface Capability
{
    /**
     * What it is, in this package's words. Each client translates it.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Settings for it, if any: how many searches, which domains, which stores.
     *
     * @return array<string, mixed>
     */
    public function options(): array;
}
