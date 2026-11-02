<?php

namespace Tests\Feature;

use App\Jobs\SendWelcomeEmail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_the_welcome_email(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        (new SendWelcomeEmail($user))->handle();

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_welcome_email_renders_the_users_name(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);

        (new WelcomeMail($user))->assertSeeInHtml('Welcome, Jane Doe!');
    }

    public function test_command_queues_the_job_by_id_or_email(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->artisan('users:send-welcome-email', ['user' => $user->id])->assertSuccessful();
        $this->artisan('users:send-welcome-email', ['user' => $user->email])->assertSuccessful();

        Queue::assertPushed(SendWelcomeEmail::class, 2);
    }

    public function test_command_fails_for_unknown_user(): void
    {
        Queue::fake();

        $this->artisan('users:send-welcome-email', ['user' => 999])
            ->expectsOutputToContain('No user found')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
