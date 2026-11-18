<?php

namespace App\Auth;

/**
 * The user Apache authenticated, with their roles from .htgroup.
 */
final readonly class MediaUser
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $username,
        public array $roles,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * @param  list<string>|null  $roles  null means "any authenticated user"
     */
    public function hasAnyRole(?array $roles): bool
    {
        if ($roles === null) {
            return true;
        }

        return array_intersect($roles, $this->roles) !== [];
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
