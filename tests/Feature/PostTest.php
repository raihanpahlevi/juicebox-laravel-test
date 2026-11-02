<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/posts')->assertUnauthorized();
        $this->postJson('/api/posts', ['title' => 'x', 'body' => 'y'])->assertUnauthorized();
        $this->getJson('/api/users/1')->assertUnauthorized();
        $this->getJson('/api/weather')->assertUnauthorized();
    }

    public function test_posts_are_listed_with_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Post::factory(20)->create();

        $this->getJson('/api/posts?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonStructure([
                'data' => [['id', 'title', 'body', 'author' => ['id', 'name'], 'created_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/posts?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_user_can_create_a_post(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/posts', ['title' => 'Hello Perth', 'body' => 'First post.'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Hello Perth')
            ->assertJsonPath('data.author.id', $user->id);

        $this->assertDatabaseHas('posts', ['title' => 'Hello Perth', 'user_id' => $user->id]);
    }

    public function test_create_post_validates_input(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/posts', ['title' => str_repeat('a', 300)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_user_can_view_a_single_post(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $post = Post::factory()->create();

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $post->id)
            ->assertJsonPath('data.title', $post->title);
    }

    public function test_missing_post_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/posts/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_author_can_partially_update_their_post(): void
    {
        $post = Post::factory()->create(['title' => 'Old', 'body' => 'Keep me']);
        Sanctum::actingAs($post->user);

        $this->patchJson("/api/posts/{$post->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.body', 'Keep me');
    }

    public function test_user_cannot_update_someone_elses_post(): void
    {
        $post = Post::factory()->create(['title' => 'Original']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/posts/{$post->id}", ['title' => 'Hacked'])->assertForbidden();

        $this->assertSame('Original', $post->fresh()->title);
    }

    public function test_author_can_delete_their_post(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs($post->user);

        $this->deleteJson("/api/posts/{$post->id}")->assertNoContent();

        $this->assertModelMissing($post);
    }

    public function test_user_cannot_delete_someone_elses_post(): void
    {
        $post = Post::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/posts/{$post->id}")->assertForbidden();

        $this->assertModelExists($post);
    }
}
