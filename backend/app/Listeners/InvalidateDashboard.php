<?php

namespace App\Listeners;

use App\Events\ImportCompleted;
use App\Models\User;

class InvalidateDashboard
{
    public function handle(ImportCompleted $event): void
    {
        User::whereKey($event->userId)->increment('dashboard_version');
    }
}
