<?php

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_number' => 'EMP-'.fake()->unique()->numerify('#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'work_email' => fake()->unique()->companyEmail(),
            'personal_email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => EmployeeStatus::Active,
            'employment_type' => EmploymentType::FullTime,
        ];
    }
}
