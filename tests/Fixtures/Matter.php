<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the narrow thing a conversation is about: a case, a project, a ticket.
 *
 * A second fixture class and not another Owner, because the point being tested is that
 * scopes are addressed by the host app's own morph aliases. With one class everything
 * would share an alias and the test would pass without proving anything.
 */
class Matter extends Model
{
    protected $table = 'matters';

    protected $guarded = [];
}
