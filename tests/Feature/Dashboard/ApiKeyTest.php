<?php

use App\Models\User;

test('api keys page loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard/api-keys')
        ->assertOk();
});

test('can create api key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/dashboard/api-keys', [
            'name' => 'Test Key',
            'environment' => 'live',
        ])
        ->assertRedirect('/dashboard/api-keys');

    $this->assertDatabaseHas('api_keys', [
        'tenant_id' => $user->tenant->id,
        'name' => 'Test Key',
        'environment' => 'live',
    ]);
});

test('can revoke api key', function () {
    $user = User::factory()->create();
    $result = $user->tenant->generateApiKey('To Revoke', 'live');

    $this->actingAs($user)
        ->delete('/dashboard/api-keys/' . $result['apiKey']->id)
        ->assertRedirect('/dashboard/api-keys');

    $result['apiKey']->refresh();
    expect($result['apiKey']->revoked_at)->not->toBeNull();
});

test('cannot revoke another tenant api key', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $result = $user1->tenant->generateApiKey('Not Yours', 'live');

    $this->actingAs($user2)
        ->delete('/dashboard/api-keys/' . $result['apiKey']->id)
        ->assertForbidden();
});
