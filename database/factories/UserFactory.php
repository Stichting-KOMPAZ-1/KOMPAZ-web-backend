<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $email = $this->faker->unique()->safeEmail();

        return [
            'organization_id' => Organization::factory(),
            'email' => $email,
            'normalized_email' => User::normalize($email),
            'name' => $this->faker->name(),
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
            'activated_at' => Carbon::now(),
            'invited_at' => Carbon::now()->subDay(),
        ];
    }

    public function role(UserRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role]);
    }

    public function administrator(): self
    {
        return $this->role(UserRole::Administrator);
    }

    public function platformAdministrator(): self
    {
        return $this->role(UserRole::PlatformAdministrator);
    }

    /** Invited but never signed in, so no account exists behind the address yet. */
    public function invited(): self
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Invited,
            'activated_at' => null,
            'last_login_at' => null,
            'invited_at' => Carbon::now(),
        ]);
    }

    public function deleted(): self
    {
        return $this->state(fn (): array => ['deleted_at' => Carbon::now()]);
    }
}
