<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

/** Stands in for an event the host app, or one of its packages, already dispatches. */
class FileWasUploaded
{
    public function __construct(
        public ?Owner $org = null,
        public ?Owner $case = null,
        public string $fileName = 'sentencia.pdf',
    ) {}
}
