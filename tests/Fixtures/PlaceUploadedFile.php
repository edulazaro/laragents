<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

/**
 * The resolver the host app writes: the only thing that knows how to get a tenant out of
 * one of its own events.
 */
class PlaceUploadedFile
{
    public function __invoke(FileWasUploaded $event): ?array
    {
        // An upload that belongs to no tenant. Saying so beats guessing an owner and
        // waking somebody else's agent.
        if (! $event->org) {
            return null;
        }

        return [
            'event' => 'file.extracted',
            'tenant' => $event->org,
            'subject' => $event->case,
            'context' => ['file_name' => $event->fileName],
        ];
    }
}
