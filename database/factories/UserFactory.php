<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function configure(): static
    {
        // Test/demo fixtures explicitly model legacy central administrators;
        // production account creation does not infer grants from null scope.
        return $this->afterMaking(function (User $user) {
            if (! array_key_exists('is_central_qa', $user->getAttributes())) {
                $user->is_central_qa = $user->isAdmin() && $user->department_id === null;
            }
        });
    }

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'student',
            'is_active' => true,
        ];
    }
}
