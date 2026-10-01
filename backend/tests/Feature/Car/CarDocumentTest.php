<?php

namespace Tests\Feature\Car;

use App\Modules\Administration\Models\Setting;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;

class CarDocumentTest extends CarTestCase
{
    private const DOC_PERMISSIONS = ['car.view', 'car.document.view', 'car.document.create', 'car.document.update', 'car.document.delete'];

    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed "today" for deterministic expiry status.
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->car = Car::factory()->create(['branch_id' => $this->branchA->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function docUser(array $branches = []): User
    {
        return $this->userWith(self::DOC_PERMISSIONS, $branches ?: [$this->branchA]);
    }

    private function addDocument(array $overrides = [], ?Car $car = null)
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/documents", array_merge([
            'type' => 'fitness',
            'document_number' => 'FIT-123',
            'issue_date' => '2025-10-15',
            'expiry_date' => '2026-10-14',
        ], $overrides));
    }

    private function seedDocument(Car $car, string $type, string $expiry, ?string $customName = null): CarDocument
    {
        return CarDocument::create([
            'car_id' => $car->id, 'type' => $type, 'custom_name' => $customName,
            'expiry_date' => $expiry, 'recorded_by' => User::factory()->create()->id,
        ]);
    }

    public function test_adds_document_with_computed_expiry_status_and_audit(): void
    {
        $this->actingAs($this->docUser())->addDocument()
            ->assertCreated()
            ->assertJsonPath('data.type', 'fitness')
            ->assertJsonPath('data.name', 'Fitness certificate')
            ->assertJsonPath('data.status', 'expiring')
            ->assertJsonPath('data.days_remaining', 13);

        $this->assertDatabaseHas('audit_logs', ['action' => 'car.document_added', 'branch_id' => $this->branchA->id]);
    }

    public function test_status_is_expired_expiring_or_valid(): void
    {
        $user = $this->docUser();

        $this->actingAs($user)->addDocument(['type' => 'tax_token', 'issue_date' => null, 'expiry_date' => '2026-09-30'])
            ->assertJsonPath('data.status', 'expired')->assertJsonPath('data.days_remaining', -1);
        $this->actingAs($user)->addDocument(['type' => 'insurance', 'issue_date' => null, 'expiry_date' => '2026-10-01'])
            ->assertJsonPath('data.status', 'expiring')->assertJsonPath('data.days_remaining', 0);
        $this->actingAs($user)->addDocument(['type' => 'route_permit', 'issue_date' => null, 'expiry_date' => '2026-10-31'])
            ->assertJsonPath('data.status', 'expiring')->assertJsonPath('data.days_remaining', 30);
        $this->actingAs($user)->addDocument(['type' => 'fitness', 'issue_date' => null, 'expiry_date' => '2026-11-01'])
            ->assertJsonPath('data.status', 'valid');
    }

    public function test_alert_window_follows_setting(): void
    {
        Setting::create(['key' => 'car.document_alert_days', 'value' => 60]);

        $this->actingAs($this->docUser())->addDocument(['expiry_date' => '2026-11-20', 'issue_date' => null])
            ->assertJsonPath('data.status', 'expiring');
    }

    public function test_renewal_supersedes_old_document_and_stops_alerting(): void
    {
        $user = $this->docUser();
        $this->actingAs($user)->addDocument(['expiry_date' => '2026-09-20', 'issue_date' => '2025-09-21'])->assertCreated();
        $this->actingAs($user)->addDocument(['expiry_date' => '2027-09-20', 'issue_date' => '2026-09-21', 'document_number' => 'FIT-124'])->assertCreated();

        $items = collect($this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/documents")->assertOk()->json('data.items'));
        $this->assertSame(['valid', 'superseded'], $items->pluck('status')->all());

        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_other_type_requires_a_name_and_names_are_tracked_separately(): void
    {
        $user = $this->docUser();

        $this->actingAs($user)->addDocument(['type' => 'other'])->assertJsonValidationErrors('custom_name');
        $this->actingAs($user)->addDocument(['type' => 'fitness', 'custom_name' => 'X'])->assertJsonValidationErrors('custom_name');

        $this->actingAs($user)->addDocument(['type' => 'other', 'custom_name' => 'Pollution certificate', 'expiry_date' => '2026-10-05'])
            ->assertCreated()->assertJsonPath('data.name', 'Pollution certificate');
        $this->actingAs($user)->addDocument(['type' => 'other', 'custom_name' => 'Gas cylinder test', 'expiry_date' => '2026-10-06'])
            ->assertCreated();

        // Different custom names do not supersede each other.
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring')->assertJsonPath('data.pagination.total', 2);
    }

    public function test_document_input_is_validated(): void
    {
        $user = $this->docUser();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/documents", [])
            ->assertJsonValidationErrors(['type', 'expiry_date']);
        $this->actingAs($user)->addDocument(['type' => 'passport', 'issue_date' => '2026-12-01', 'expiry_date' => '2026-11-01'])
            ->assertJsonValidationErrors(['type', 'issue_date', 'expiry_date']);
        $this->actingAs($user)->addDocument(['issue_date' => '2026-05-01', 'expiry_date' => '2026-04-01'])
            ->assertJsonValidationErrors(['expiry_date' => 'The expiry date must be on or after the issue date.']);

        $this->assertDatabaseCount('car_documents', 0);
    }

    public function test_update_and_delete_are_audited(): void
    {
        $user = $this->docUser();
        $id = $this->actingAs($user)->addDocument()->json('data.id');

        $this->actingAs($user)->putJson("/api/v1/cars/{$this->car->id}/documents/{$id}", ['expiry_date' => '2026-10-20'])
            ->assertOk()->assertJsonPath('data.expiry_date', '2026-10-20')->assertJsonPath('data.type', 'fitness');
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.document_updated', 'entity_id' => $id]);

        // Partial update keeps cross-field rules: expiry cannot move before the stored issue date.
        $this->actingAs($user)->putJson("/api/v1/cars/{$this->car->id}/documents/{$id}", ['expiry_date' => '2025-01-01'])
            ->assertJsonValidationErrors('expiry_date');

        $this->actingAs($user)->deleteJson("/api/v1/cars/{$this->car->id}/documents/{$id}")->assertOk();
        $this->assertDatabaseCount('car_documents', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.document_deleted', 'entity_id' => $id]);
    }

    public function test_permissions_are_enforced(): void
    {
        $this->getJson("/api/v1/cars/{$this->car->id}/documents")->assertUnauthorized();
        $this->getJson('/api/v1/car-documents/expiring')->assertUnauthorized();

        $viewer = $this->userWith(['car.view', 'car.document.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson("/api/v1/cars/{$this->car->id}/documents")->assertOk();
        $this->actingAs($viewer)->addDocument()->assertForbidden();

        $doc = $this->seedDocument($this->car, 'fitness', '2026-10-10');
        $this->actingAs($viewer)->putJson("/api/v1/cars/{$this->car->id}/documents/{$doc->id}", ['notes' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/cars/{$this->car->id}/documents/{$doc->id}")->assertForbidden();

        $carOnly = $this->managerA();
        $this->actingAs($carOnly)->getJson("/api/v1/cars/{$this->car->id}/documents")->assertForbidden();
        $this->actingAs($carOnly)->getJson('/api/v1/car-documents/expiring')->assertForbidden();
    }

    public function test_branch_isolation_for_documents_and_alerts(): void
    {
        $foreign = Car::factory()->create(['branch_id' => $this->branchB->id]);
        $foreignDoc = $this->seedDocument($foreign, 'tax_token', '2026-09-01');
        $this->seedDocument($this->car, 'tax_token', '2026-09-15');
        $user = $this->docUser();

        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/documents")->assertForbidden();
        $this->actingAs($user)->addDocument([], $foreign)->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/cars/{$foreign->id}/documents/{$foreignDoc->id}")->assertForbidden();

        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.car.id', $this->car->id);
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiry-summary')
            ->assertJsonPath('data.expired', 1)->assertJsonPath('data.expiring', 0);

        $global = $this->userWith(['car.document.view', 'branch.access_all']);
        $this->actingAs($global)->getJson('/api/v1/car-documents/expiry-summary')->assertJsonPath('data.expired', 2);
    }

    public function test_document_must_belong_to_car_in_url(): void
    {
        $other = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $doc = $this->seedDocument($other, 'fitness', '2026-10-10');

        $this->actingAs($this->docUser())->deleteJson("/api/v1/cars/{$this->car->id}/documents/{$doc->id}")->assertNotFound();
    }

    public function test_expiring_list_filters_and_orders_by_expiry(): void
    {
        $this->seedDocument($this->car, 'insurance', '2026-10-20');
        $this->seedDocument($this->car, 'tax_token', '2026-09-01');
        $this->seedDocument($this->car, 'fitness', '2027-05-01');
        $user = $this->docUser();

        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.type', 'tax_token')
            ->assertJsonPath('data.items.0.car.branch.code', 'A');
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring?status=expiring')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring?status=valid')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring?type=insurance')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($user)->getJson('/api/v1/car-documents/expiring?status=bogus')->assertJsonValidationErrors('status');
    }

    public function test_car_registration_date_is_stored_and_validated(): void
    {
        $user = $this->managerA();

        $this->actingAs($user)->putJson("/api/v1/cars/{$this->car->id}", ['registration_date' => '2019-03-15'])
            ->assertOk()->assertJsonPath('data.registration_date', '2019-03-15');
        $this->actingAs($user)->putJson("/api/v1/cars/{$this->car->id}", ['registration_date' => '2030-01-01'])
            ->assertJsonValidationErrors('registration_date');
    }

    public function test_deleting_a_car_removes_its_documents(): void
    {
        $this->seedDocument($this->car, 'fitness', '2026-10-10');

        $this->actingAs($this->managerA())->deleteJson("/api/v1/cars/{$this->car->id}")->assertOk();
        $this->assertDatabaseCount('car_documents', 0);
    }
}
