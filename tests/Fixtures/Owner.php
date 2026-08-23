<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Concerns\SharesMemories;
use Illuminate\Database\Eloquent\Model;

/** Stands in for whatever the host app calls its tenant, and for whoever talks. */
class Owner extends Model
{
    use SharesMemories;

    protected $table = 'owners';

    protected $guarded = [];
}
