<?php

namespace App\Console\Commands;

use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:send-welcome-email {user : The user ID or email address} {--sync : Send immediately instead of queueing}')]
#[Description('Dispatch the welcome email job for a user (useful for testing the queue)')]
class SendWelcomeEmailCommand extends Command
{
    public function handle(): int
    {
        $identifier = $this->argument('user');

        $user = User::query()
            ->when(
                filter_var($identifier, FILTER_VALIDATE_EMAIL),
                fn ($q) => $q->where('email', $identifier),
                fn ($q) => $q->whereKey($identifier),
            )
            ->first();

        if (! $user) {
            $this->error("No user found for [{$identifier}].");

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            SendWelcomeEmail::dispatchSync($user);
            $this->info("Welcome email sent to {$user->email}.");
        } else {
            SendWelcomeEmail::dispatch($user);
            $this->info("Welcome email job queued for {$user->email}. Run `php artisan queue:work` to process it.");
        }

        return self::SUCCESS;
    }
}
