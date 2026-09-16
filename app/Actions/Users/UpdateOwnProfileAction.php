<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;

/**
 * A user's own edit of their profile.
 *
 * The name and nothing else. The email address is the sign-in identity rather than a contact
 * detail, and a role is not self-assignable, so neither moves here.
 */
final readonly class UpdateOwnProfileAction
{
    public function execute(User $user, string $name): User
    {
        $user->name = trim($name);
        $user->save();

        return $user->refresh();
    }
}
