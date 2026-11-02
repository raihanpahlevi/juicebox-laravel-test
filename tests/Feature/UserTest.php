<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_a_user_with_post_count(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $user = User::factory()->has(Post::factory(3))->create();

        $this->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.posts_count', 3);
    }

    public function test_email_is_only_visible_to_the_user_themselves(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($me);

        $this->getJson("/api/users/{$other->id}")->assertJsonMissingPath('data.email');
        $this->getJson("/api/users/{$me->id}")->assertJsonPath('data.email', $me->email);
    }

    public function test_users_are_listed_with_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create());
        User::factory(11)->create();

        $this->getJson('/api/users?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_missing_user_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users/999')->assertNotFound();
    }
}
