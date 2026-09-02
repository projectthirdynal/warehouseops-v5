<?php

declare(strict_types=1);

use Modules\Shop\Models\FacebookAccount;
use Modules\Shop\Models\FacebookPage;

it('hides access_token from array serialization', function () {
    $account = FacebookAccount::factory()->create([
        'access_token' => 'super-secret-token',
    ]);

    $array = $account->toArray();

    expect($array)->not->toHaveKey('access_token');
});

it('hides page_access_token from array serialization', function () {
    $page = FacebookPage::factory()->create([
        'page_access_token' => 'page-secret-token',
    ]);

    $array = $page->toArray();

    expect($array)->not->toHaveKey('page_access_token');
});

it('stores access_token encrypted in database', function () {
    $account = FacebookAccount::factory()->create([
        'access_token' => 'my-secret-token',
    ]);

    $raw = DB::table('facebook_accounts')->where('id', $account->id)->value('access_token');

    expect($raw)->not->toBe('my-secret-token');
    expect($account->access_token)->toBe('my-secret-token');
});

it('stores page_access_token encrypted in database', function () {
    $page = FacebookPage::factory()->create([
        'page_access_token' => 'page-secret-token',
    ]);

    $raw = DB::table('facebook_pages')->where('id', $page->id)->value('page_access_token');

    expect($raw)->not->toBe('page-secret-token');
    expect($page->page_access_token)->toBe('page-secret-token');
});
