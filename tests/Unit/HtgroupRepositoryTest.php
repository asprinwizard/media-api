<?php

use App\Auth\HtgroupRepository;

it('returns every group a user belongs to', function () {
    $file = useHtgroup("admin: richard\nftp: richard bob\nstandard: alice bob richard\n");

    $repo = new HtgroupRepository($file);

    expect($repo->rolesFor('richard'))->toBe(['admin', 'ftp', 'standard'])
        ->and($repo->rolesFor('bob'))->toBe(['ftp', 'standard'])
        ->and($repo->rolesFor('alice'))->toBe(['standard']);
});

it('matches whole usernames only', function () {
    $repo = new HtgroupRepository(useHtgroup("admin: richard\n"));

    expect($repo->rolesFor('rich'))->toBe([])
        ->and($repo->rolesFor('richard2'))->toBe([]);
});

it('ignores blank lines, comments and extra whitespace', function () {
    $repo = new HtgroupRepository(useHtgroup("\n# comment\nadmin:   richard    bob  \n\n"));

    expect($repo->groups())->toBe(['admin' => ['richard', 'bob']]);
});

it('treats a missing file as no groups', function () {
    $repo = new HtgroupRepository('/nonexistent/.htgroup');

    expect($repo->rolesFor('richard'))->toBe([]);
});
