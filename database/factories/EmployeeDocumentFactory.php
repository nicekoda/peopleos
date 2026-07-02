<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $storedFilename = Str::random(40).'.pdf';

        return [
            'tenant_id' => Tenant::factory(),
            'employee_id' => Employee::factory(),
            'title' => fake()->sentence(3),
            'original_filename' => 'document.pdf',
            'stored_filename' => $storedFilename,
            'storage_disk' => 'local',
            'storage_path' => 'employee-documents/factory/'.$storedFilename,
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'file_size' => 12345,
            'checksum' => hash('sha256', $storedFilename),
            'status' => DocumentStatus::Active,
            'is_sensitive' => false,
        ];
    }

    /**
     * Factory-created documents have a matching fake file written to the
     * (faked, in tests) storage disk, so download tests work without
     * extra setup.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (EmployeeDocument $document): void {
            Storage::disk($document->storage_disk)->put($document->storage_path, 'fake file contents for testing');
        });
    }
}
