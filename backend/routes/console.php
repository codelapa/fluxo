<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('demo:prune', function () {
    User::where('is_demo', true)->where('created_at', '<', now()->subDays(2))->chunkById(100, function ($users) {
        foreach ($users as $user) {
            Storage::disk('local')->deleteDirectory('imports/'.$user->id);
            $user->delete();
        }
    });
    $this->info('Expired demo workspaces removed.');
})->purpose('Remove isolated demo data older than two days');
Schedule::command('demo:prune')->daily();
Schedule::command('queue:prune-failed --hours=48')->daily();
Artisan::command('user:create {email} {--name=Evaluator}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }$password = $this->secret('Password (at least 12 characters)');
    if (! is_string($password) || strlen($password) < 12) {
        $this->error('Password must contain at least 12 characters.');

        return 1;
    }if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }User::forceCreate(['name' => $this->option('name'), 'email' => $email, 'password' => Hash::make($password), 'is_demo' => false]);
    $this->info('Account created.');

    return 0;
})->purpose('Create a private login account without storing a default password');
