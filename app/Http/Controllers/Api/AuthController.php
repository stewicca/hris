<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginInput = $request->input('email');
        $password = $request->input('password');

        $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $credentials = [
            $fieldType => $loginInput,
            'password' => $password,
        ];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'employee' => $this->employeePayload($user),
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'employee' => $this->employeePayload($user),
        ]);
    }

    /**
     * Update the authenticated employee's own contact details.
     */
    public function updateProfile(Request $request)
    {
        $employee = $request->user()?->employee;

        if (! $employee instanceof Employee) {
            return response()->json(['message' => 'No employee record'], 404);
        }

        $employee->update($request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
        ]));

        return response()->json(['employee' => $this->employeePayload($request->user())]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => Hash::make($request->string('password'))]);

        return response()->json(['message' => 'Password updated']);
    }

    /**
     * Shape the employee for the portal with department/position flattened to names.
     *
     * @return array<string, mixed>|null
     */
    private function employeePayload(User $user): ?array
    {
        $employee = $user->employee?->load(['department', 'position']);

        if (! $employee instanceof Employee) {
            return null;
        }

        return [
            ...$employee->toArray(),
            'department' => $employee->department?->name,
            'position' => $employee->position?->name,
        ];
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
