<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app calls its tenant. */
class Owner extends Model
{
    protected $table = 'owners';

    protected $guarded = [];
}
