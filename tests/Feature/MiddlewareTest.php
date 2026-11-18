<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    useHtgroup("admin: richard\nftp: bob\nstandard: alice\n");

    Route::middleware(['media.user', 'same-origin', 'role:admin'])
        ->post('/_test/admin', fn () => ['ok' => true]);
    Route::middleware(['media.user', 'same-origin', 'role:admin,ftp'])
        ->get('/_test/upload', fn () => ['ok' => true]);
    Route::middleware(['media.user', 'same-origin'])
        ->post('/_test/write', fn () => ['ok' => true]);
});

it('rejects unauthenticated POSTs before anything else', function () {
    $this->postJson('/_test/write', ['a' => 1])->assertStatus(401);
});

describe('role middleware', function () {
    it('lets admins through', function () {
        $this->withServerVariables(['REMOTE_USER' => 'richard'])
            ->postJson('/_test/admin')->assertOk();
    });

    it('refuses other roles with 403', function () {
        $this->withServerVariables(['REMOTE_USER' => 'alice'])
            ->postJson('/_test/admin')->assertForbidden();
        $this->withServerVariables(['REMOTE_USER' => 'bob'])
            ->postJson('/_test/admin')->assertForbidden();
    });

    it('accepts any of several roles', function () {
        $this->withServerVariables(['REMOTE_USER' => 'bob'])
            ->getJson('/_test/upload')->assertOk();
        $this->withServerVariables(['REMOTE_USER' => 'alice'])
            ->getJson('/_test/upload')->assertForbidden();
    });
});

describe('same-origin middleware', function () {
    beforeEach(function () {
        $this->withServerVariables(['REMOTE_USER' => 'alice']);
    });

    it('allows a same-origin JSON request', function () {
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])
            ->postJson('/_test/write', ['a' => 1])->assertOk();
    });

    it('allows a multipart upload that sends X-Requested-With', function () {
        $this->withHeaders(['Sec-Fetch-Site' => 'same-origin', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/_test/write', ['a' => 1], ['Accept' => 'application/json'])->assertOk();
    });

    it('refuses a plain form post (what a hostile page can send)', function () {
        $this->post('/_test/write', ['a' => 1], ['Accept' => 'application/json'])
            ->assertForbidden();
    });

    it('refuses a cross-site request even with a JSON body', function () {
        $this->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->postJson('/_test/write', ['a' => 1])->assertForbidden();
    });

    it('refuses a request from another Origin', function () {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/_test/write', ['a' => 1])->assertForbidden();
    });

    it('allows a request whose Origin matches this host', function () {
        $this->withHeaders(['Origin' => rtrim(config('app.url'), '/')])
            ->postJson('/_test/write', ['a' => 1])->assertOk();
    });

    it('does not affect GET requests', function () {
        $this->withServerVariables(['REMOTE_USER' => 'richard'])
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->get('/_test/upload', ['Accept' => 'application/json'])->assertOk();
    });
});
