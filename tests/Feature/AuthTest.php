<?php

beforeEach(function () {
    useHtgroup("admin: richard\nftp: richard bob\nstandard: alice bob richard\nempty:\n");
});

function menuKeys($response): array
{
    return collect($response->json('menu'))->pluck('key')->all();
}

it('rejects requests Apache did not authenticate', function () {
    $this->getJson('/me')->assertStatus(401);
});

it('ignores a client-supplied PHP_AUTH_USER', function () {
    $this->withServerVariables(['PHP_AUTH_USER' => 'richard', 'PHP_AUTH_PW' => 'x'])
        ->getJson('/me')
        ->assertStatus(401);
});

it('gives an admin the full menu and the tool links', function () {
    config()->set('media.links.transmission', 'https://transmission.example');

    $response = $this->withServerVariables(['REMOTE_USER' => 'richard'])->getJson('/me');

    $response->assertOk()
        ->assertJsonPath('user', 'richard')
        ->assertJsonPath('roles', ['admin', 'ftp', 'standard'])
        ->assertJsonPath('links.transmission', 'https://transmission.example');

    expect(menuKeys($response))->toBe([
        'home', 'library', 'search', 'help', 'upload', 'iplayer', 'youtube', 'live-tv', 'logs', 'tools',
    ]);
});

it('gives an ftp user the browse menu plus upload, and no links', function () {
    $response = $this->withServerVariables(['REMOTE_USER' => 'bob'])->getJson('/me');

    $response->assertOk()->assertJsonPath('links', []);
    expect(menuKeys($response))->toBe(['home', 'library', 'search', 'help', 'upload']);
});

it('gives a standard user the browse menu only', function () {
    $response = $this->withServerVariables(['REMOTE_USER' => 'alice'])->getJson('/me');

    expect(menuKeys($response))->toBe(['home', 'library', 'search', 'help']);
});

it('accepts the user Apache passes after an internal redirect', function () {
    $this->withServerVariables(['REDIRECT_REMOTE_USER' => 'alice'])
        ->getJson('/me')
        ->assertOk()
        ->assertJsonPath('user', 'alice');
});

it('refuses an authenticated user who is in no group', function () {
    $this->withServerVariables(['REMOTE_USER' => 'mallory'])
        ->getJson('/me')
        ->assertStatus(403);
});

describe('local development login', function () {
    beforeEach(function () {
        config()->set('media.auth.dev', ['enabled' => true, 'user' => 'dev', 'roles' => ['admin']]);
    });

    it('works on localhost in the local environment', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/me')
            ->assertOk()
            ->assertJsonPath('user', 'dev');
    });

    it('is ignored outside the local environment', function () {
        app()->detectEnvironment(fn () => 'production');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/me')
            ->assertStatus(401);
    });

    it('is ignored for requests from other machines', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->getJson('/me')
            ->assertStatus(401);
    });
});

it('answers at /api/me when Apache serves the app under "Alias /api"', function () {
    // What mod_php passes for https://host/api/me with "Alias /api .../public"
    $this->withServerVariables([
        'REMOTE_USER' => 'alice',
        'SCRIPT_NAME' => '/api/index.php',
        'SCRIPT_FILENAME' => base_path('public/index.php'),
        'PHP_SELF' => '/api/index.php',
    ])->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user', 'alice');
});
