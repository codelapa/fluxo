<?php

namespace App\Events;

class ImportCompleted
{
    public function __construct(public readonly int $userId) {}
}
