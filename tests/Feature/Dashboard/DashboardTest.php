<?php

use App\Models\User;

test('dashboard overview loads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('dashboard overview shows stats', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('stats')
            ->has('recentCrawls')
        );
});
