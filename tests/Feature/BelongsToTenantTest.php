<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_scoped_widgets', function (Blueprint $table) {
            $table->id();
            $table->ulid('tenant_id');
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_scoped_widgets');

        parent::tearDown();
    }

    public function test_query_is_automatically_scoped_to_the_bound_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        TenantScopedWidget::query()->create(['tenant_id' => $tenantA->id, 'name' => 'A widget']);
        TenantScopedWidget::query()->create(['tenant_id' => $tenantB->id, 'name' => 'B widget']);

        app()->instance(Tenant::class, $tenantA);

        $this->assertCount(1, TenantScopedWidget::all());
        $this->assertSame('A widget', TenantScopedWidget::first()->name);
    }

    public function test_tenant_id_is_auto_filled_from_bound_tenant_on_create(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance(Tenant::class, $tenant);

        $widget = TenantScopedWidget::query()->create(['name' => 'Auto-filled widget']);

        $this->assertSame($tenant->id, $widget->tenant_id);
    }

    public function test_no_scoping_is_applied_when_no_tenant_is_bound(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        TenantScopedWidget::query()->create(['tenant_id' => $tenantA->id, 'name' => 'A widget']);
        TenantScopedWidget::query()->create(['tenant_id' => $tenantB->id, 'name' => 'B widget']);

        $this->assertCount(2, TenantScopedWidget::all());
    }
}

class TenantScopedWidget extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_widgets';

    protected $fillable = ['tenant_id', 'name'];
}
