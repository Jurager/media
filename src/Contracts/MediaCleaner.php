<?php

namespace Jurager\Media\Contracts;

use Illuminate\Support\Collection;

interface MediaCleaner
{
    /** Identify application-specific orphans among $candidates, which already passed the built-in checks (parent exists, collection registered) — return only the subset to delete. */
    public function orphaned(Collection $candidates, string $type, string $modelClass): Collection;
}
