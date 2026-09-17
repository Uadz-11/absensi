<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    public function test_peserta_riwayat_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create([
            'role' => 'peserta',
        ]);

        $response = $this->actingAs($user)->get('/riwayat');

        $response->assertOk();
    }
}
