<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Actions\UploadCarImage;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CarImageTest extends CarTestCase
{
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        $this->car = Car::factory()->create(['branch_id' => $this->branchA->id]);
    }

    /**
     * Real image bytes from fixtures (no GD needed); $padKb grows the file for size-limit tests.
     */
    private function image(string $name, int $padKb = 0): UploadedFile
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION) === 'png' ? 'png' : 'jpg';
        $content = file_get_contents(base_path("tests/Fixtures/images/pixel.{$ext}")).str_repeat("\0", $padKb * 1024);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(Car $car, UploadedFile $file)
    {
        return $this->postJson("/api/v1/cars/{$car->id}/images", ['image' => $file]);
    }

    public function test_manager_uploads_image_stored_under_generated_name(): void
    {
        $response = $this->actingAs($this->managerA())
            ->upload($this->car, $this->image('../../etc/front view.jpg'));

        $response->assertCreated()
            ->assertJsonPath('data.original_name', 'front view.jpg')
            ->assertJsonPath('data.mime_type', 'image/jpeg')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $image = CarImage::first();
        $this->assertMatchesRegularExpression("#^cars/{$this->car->id}/[0-9A-Z]{26}\.jpg$#", $image->path);
        Storage::disk($image->disk)->assertExists($image->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.image_uploaded', 'entity_id' => $this->car->id]);
    }

    public function test_only_real_images_within_limits_are_accepted(): void
    {
        $user = $this->managerA();

        $this->actingAs($user)->upload($this->car, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))
            ->assertJsonValidationErrors('image');
        $this->actingAs($user)->upload($this->car, UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml'))
            ->assertJsonValidationErrors('image');
        $this->actingAs($user)->upload($this->car, $this->image('big.jpg', 6000))
            ->assertJsonValidationErrors('image');
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/images", [])
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('car_images', 0);
    }

    public function test_image_count_per_car_is_limited(): void
    {
        foreach (range(1, UploadCarImage::MAX_IMAGES_PER_CAR) as $i) {
            CarImage::create([
                'car_id' => $this->car->id, 'disk' => 'local', 'path' => "cars/x/{$i}.jpg",
                'original_name' => "{$i}.jpg", 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            ]);
        }

        $this->actingAs($this->managerA())->upload($this->car, $this->image('one-more.jpg'))
            ->assertJsonValidationErrors('image');
    }

    public function test_cross_branch_and_view_only_users_cannot_upload(): void
    {
        $this->upload($this->car, $this->image('a.jpg'))->assertUnauthorized();

        $foreignCar = Car::factory()->create(['branch_id' => $this->branchB->id]);

        $this->actingAs($this->managerA())->upload($foreignCar, $this->image('a.jpg'))->assertForbidden();
        $this->actingAs($this->userWith(['car.view'], [$this->branchA]))
            ->upload($this->car, $this->image('a.jpg'))->assertForbidden();
    }

    public function test_image_file_is_served_only_to_authorized_users(): void
    {
        $id = $this->actingAs($this->managerA())->upload($this->car, $this->image('a.png'))->json('data.id');

        $this->actingAs($this->userWith(['car.view'], [$this->branchA]))
            ->get("/api/v1/cars/{$this->car->id}/images/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->userWith(['car.view'], [$this->branchB]))
            ->getJson("/api/v1/cars/{$this->car->id}/images/{$id}/file")
            ->assertForbidden();
    }

    public function test_image_must_belong_to_the_car_in_the_url(): void
    {
        $id = $this->actingAs($this->managerA())->upload($this->car, $this->image('a.jpg'))->json('data.id');
        $otherCar = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($this->managerA())->getJson("/api/v1/cars/{$otherCar->id}/images/{$id}/file")->assertNotFound();
        $this->actingAs($this->managerA())->deleteJson("/api/v1/cars/{$otherCar->id}/images/{$id}")->assertNotFound();
    }

    public function test_deleting_image_or_car_removes_stored_files(): void
    {
        $user = $this->managerA();
        $first = $this->actingAs($user)->upload($this->car, $this->image('a.jpg'))->json('data.id');
        $this->actingAs($user)->upload($this->car, $this->image('b.jpg'))->assertCreated();
        $paths = CarImage::pluck('path', 'id');

        $this->actingAs($user)->deleteJson("/api/v1/cars/{$this->car->id}/images/{$first}")->assertOk();
        Storage::disk(config('filesystems.default'))->assertMissing($paths[$first]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.image_deleted']);

        $this->actingAs($user)->deleteJson("/api/v1/cars/{$this->car->id}")->assertOk();
        foreach ($paths as $path) {
            Storage::disk(config('filesystems.default'))->assertMissing($path);
        }
        $this->assertDatabaseCount('car_images', 0);
    }

    public function test_car_details_include_images(): void
    {
        $this->actingAs($this->managerA())->upload($this->car, $this->image('a.jpg'))->assertCreated();

        $this->actingAs($this->managerA())->getJson("/api/v1/cars/{$this->car->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.images.0.file_path', "/cars/{$this->car->id}/images/".CarImage::first()->id.'/file');
    }
}
