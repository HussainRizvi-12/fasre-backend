<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminPortalPageTest extends TestCase
{
    public function test_root_redirects_to_admin_portal(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_admin_portal_spa_is_served_publicly(): void
    {
        $response = $this->get('/admin');

        $response->assertOk();
        $this->assertStringContainsString('id="root"', file_get_contents(public_path('admin/index.html')));
    }

    public function test_admin_portal_deep_paths_serve_the_spa(): void
    {
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/review-windows')->assertOk();
        $this->get('/admin/audit-assignments')->assertOk();
    }
}
