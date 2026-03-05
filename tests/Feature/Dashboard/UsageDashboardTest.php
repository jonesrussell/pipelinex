<?php

use App\Models\User;

test('usage page loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard/usage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/Usage')
            ->has('plan')
            ->has('crawls')
            ->has('rateLimit')
        );
});
