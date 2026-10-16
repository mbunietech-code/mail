<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id')
                ?? Organization::query()->create(['name' => 'MbunieTech', 'allowed_domains' => ['mt.co.tz']])->id,
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@mt.co.tz',
            'password' => static::$password ??= Hash::make('Correct-Horse-Battery-9'),
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(Role::query()->where('name', $role)->valueOrFail('id')));
    }
}
