<?php

use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['password' => bcrypt('old-password')]);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'phone' => '0811']);
});

it('lets an employee update their own phone', function () {
    $this->actingAs($this->user)
        ->putJson('/api/profile', ['phone' => '08991234567'])
        ->assertOk()
        ->assertJsonPath('employee.phone', '08991234567');

    expect($this->employee->fresh()->phone)->toBe('08991234567');
});

it('exposes the bank account number read-only on the employee payload', function () {
    $this->employee->update(['bank_account_number' => '1234567890']);

    $this->actingAs($this->user)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('employee.bank_account_number', '1234567890');
});

it('ignores bank_account_number submitted via the profile endpoint', function () {
    $this->employee->update(['bank_account_number' => 'original']);

    $this->actingAs($this->user)
        ->putJson('/api/profile', ['phone' => '0812', 'bank_account_number' => 'hijacked'])
        ->assertOk();

    expect($this->employee->fresh()->bank_account_number)->toBe('original');
});

it('rejects a phone that is too long', function () {
    $this->actingAs($this->user)
        ->putJson('/api/profile', ['phone' => str_repeat('9', 31)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('lets an employee change their password with the correct current password', function () {
    $this->actingAs($this->user)
        ->putJson('/api/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertOk();

    expect(Hash::check('new-password-123', $this->user->fresh()->password))->toBeTrue();
});

it('rejects a password change with a wrong current password', function () {
    $this->actingAs($this->user)
        ->putJson('/api/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');
});

it('requires authentication to update the profile', function () {
    $this->putJson('/api/profile', ['phone' => '0812'])->assertUnauthorized();
});
