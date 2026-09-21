<?php

namespace Database\Factories;

use App\Modules\Identity\Access\Role;
use App\Modules\Identity\Enums\EmploymentType;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => Str::lower(fake()->unique()->userName()),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'employment_type' => EmploymentType::Permanent,
            'password' => static::$password ??= Hash::make('password-for-tests'),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'locale' => 'id',
            'theme' => 'system',
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true, 'password_changed_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function withRole(Role ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles) {
            $user->assignRole(array_map(fn (Role $r) => $r->value, $roles));
        });
    }
}
