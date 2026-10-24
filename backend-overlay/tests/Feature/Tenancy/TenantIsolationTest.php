<?php

namespace Tests\Feature\Tenancy;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_listing_only_returns_own_tenant_resources(): void
    {
        [$a, $ownerA] = $this->tenantWithOwner();
        [$b, $ownerB] = $this->tenantWithOwner();

        $this->asTenant($a, fn () => Site::factory()->count(2)->create());
        $this->asTenant($b, fn () => Site::factory()->count(3)->create());

        Sanctum::actingAs($ownerA);
        $this->getJson('/api/v1/sites')->assertOk()->assertJsonPath('meta.total', 2);

        Sanctum::actingAs($ownerB);
        $this->getJson('/api/v1/sites')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_cross_tenant_access_returns_404_for_show_update_delete(): void
    {
        [$a] = $this->tenantWithOwner();
        [, $ownerB] = $this->tenantWithOwner();
        $siteA = $this->asTenant($a, fn () => Site::factory()->create());

        Sanctum::actingAs($ownerB);

        $this->getJson("/api/v1/sites/{$siteA->id}")->assertStatus(404);
        $this->putJson("/api/v1/sites/{$siteA->id}", ['name' => 'Piraté'])->assertStatus(404);
        $this->deleteJson("/api/v1/sites/{$siteA->id}")->assertStatus(404);

        $this->assertDatabaseHas('sites', ['id' => $siteA->id, 'name' => $siteA->name]);
    }

    public function test_tenant_id_in_request_body_is_ignored(): void
    {
        [$a, $ownerA] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();

        Sanctum::actingAs($ownerA);
        $id = $this->postJson('/api/v1/sites', ['name' => 'Mon site', 'tenant_id' => $b->getKey()])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseHas('sites', ['id' => $id, 'tenant_id' => $a->getKey()]);
    }

    public function test_tenant_header_cannot_switch_tenant(): void
    {
        [, $ownerA] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();
        $this->asTenant($b, fn () => Site::factory()->create(['name' => 'Site B']));

        Sanctum::actingAs($ownerA);
        $this->withHeaders(['X-Tenant-Id' => $b->getKey()])->getJson('/api/v1/sites')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_queries_without_tenant_context_return_nothing(): void
    {
        [$a] = $this->tenantWithOwner();
        $this->asTenant($a, fn () => Site::factory()->create());

        $this->assertSame(0, Site::count());
        $this->assertSame(1, $this->asTenant($a, fn () => Site::count()));
    }

    public function test_creating_without_tenant_context_is_refused(): void
    {
        $this->expectException(LogicException::class);
        Site::factory()->create();
    }

    public function test_creating_for_another_tenant_inside_a_context_is_refused(): void
    {
        [$a] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();

        $this->expectException(LogicException::class);
        $this->asTenant($a, function () use ($b) {
            $site = new Site(['name' => 'X']);
            $site->tenant_id = $b->getKey();
            $site->save();
        });
    }

    public function test_tenant_id_is_immutable(): void
    {
        [$a] = $this->tenantWithOwner();
        [$b] = $this->tenantWithOwner();
        $site = $this->asTenant($a, fn () => Site::factory()->create());

        $this->expectException(LogicException::class);
        $this->asTenant($a, function () use ($site, $b) {
            $site->tenant_id = $b->getKey();
            $site->save();
        });
    }

    public function test_context_does_not_leak_after_a_request(): void
    {
        [, $ownerA] = $this->tenantWithOwner();
        Sanctum::actingAs($ownerA);
        $this->getJson('/api/v1/sites')->assertOk();

        $this->assertSame(0, Site::count());
    }
}
