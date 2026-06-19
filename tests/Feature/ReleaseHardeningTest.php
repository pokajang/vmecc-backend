<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkflowAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class ReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_session_return_csrf_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'csrf@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonStructure(['csrf_token', 'user' => ['id']]);

        $loginToken = (string) $loginResponse->json('csrf_token');
        $this->assertNotSame('', $loginToken);

        $sessionCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($sessionCookie);

        $sessionResponse = $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->getJson('/api/auth/session');

        $sessionResponse
            ->assertOk()
            ->assertJsonStructure(['csrf_token', 'user' => ['id']]);
        $this->assertNotSame('', (string) $sessionResponse->json('csrf_token'));
    }

    public function test_unsafe_cookie_authenticated_request_requires_valid_csrf_token(): void
    {
        $user = User::factory()->create([
            'email' => 'unsafe@example.test',
            'status' => 'Active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $sessionCookie = $this->findSessionCookie($loginResponse->headers->getCookies());
        $this->assertNotNull($sessionCookie);

        $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->putJson('/api/profile', ['name' => 'Blocked Update'])
            ->assertStatus(419)
            ->assertJsonPath('message', 'CSRF token mismatch.');

        $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->withHeader('X-CSRF-Token', 'invalid-token')
            ->putJson('/api/profile', ['name' => 'Invalid Token Update'])
            ->assertStatus(419)
            ->assertJsonPath('message', 'CSRF token mismatch.');

        $this
            ->withCredentials()
            ->withUnencryptedCookie('vmecc_session', $sessionCookie->getValue())
            ->withHeader('X-CSRF-Token', (string) $loginResponse->json('csrf_token'))
            ->putJson('/api/profile', ['name' => 'Allowed Update'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Allowed Update');
    }

    public function test_api_responses_include_release_security_headers(): void
    {
        $this
            ->getJson('/api/settings/system-maintenance')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    }

    public function test_profile_images_use_public_uploads_disk(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);

        $response = $this->postJson('/api/profile/image', [
            'image' => UploadedFile::fake()->image('avatar.jpg', 120, 120),
        ]);

        $response->assertOk();

        $path = (string) $user->fresh()->profile_image_url;
        $this->assertStringStartsWith('profiles/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/profiles/', (string) $response->json('user.profile_image_url'));
    }

    public function test_workflow_attachments_remain_private_local_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);

        $response = $this->postJson('/api/workflow/attachments', [
            'file' => UploadedFile::fake()->create('receipt.pdf', 128, 'application/pdf'),
        ]);

        $response->assertCreated();

        $attachment = WorkflowAttachment::query()->firstOrFail();
        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('url', $payload);
    }

    /**
     * @param array<int, Cookie> $cookies
     */
    private function findSessionCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'vmecc_session') {
                return $cookie;
            }
        }

        return null;
    }
}
