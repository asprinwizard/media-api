<?php

namespace App\Auth;

use Illuminate\Support\Facades\Log;

/**
 * Reads Apache's AuthGroupFile ("group: user1 user2 ...").
 * Read-only port of media-web's modules/security.php.
 */
class HtgroupRepository
{
    /** @var array<string, list<string>>|null group => users */
    private ?array $groups = null;

    public function __construct(private readonly string $file) {}

    /**
     * Roles (group names) the user belongs to, in file order.
     *
     * @return list<string>
     */
    public function rolesFor(string $username): array
    {
        $roles = [];
        foreach ($this->groups() as $group => $users) {
            if (in_array($username, $users, true)) {
                $roles[] = $group;
            }
        }

        return $roles;
    }

    /**
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        if (! is_readable($this->file)) {
            Log::warning('htgroup file is missing or unreadable', ['file' => $this->file]);

            return $this->groups = [];
        }

        $groups = [];
        foreach (file($this->file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                continue;
            }

            [$group, $users] = explode(':', $line, 2);
            $group = trim($group);
            $members = preg_split('/\s+/', trim($users), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $groups[$group] = array_values(array_unique(array_merge($groups[$group] ?? [], $members)));
        }

        return $this->groups = $groups;
    }
}
