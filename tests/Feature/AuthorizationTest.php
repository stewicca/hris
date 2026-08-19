<?php

use App\Models\User;

test('non-admins cannot access the back-office', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertForbidden();
    $this->get(route('employees.index'))->assertForbidden();
    $this->get(route('leaves.index'))->assertForbidden();
    $this->get(route('attendance.index'))->assertForbidden();
});

test('admins can access the back-office', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('employees.index'))->assertOk();
});

test('non-admins can still manage their own settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});
