<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticateApiUser;
use App\Actions\Auth\UpdateEmployeeProfile;
use App\Actions\Auth\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request, AuthenticateApiUser $authenticate)
    {
        $user = $authenticate->handle($request);

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

    public function updateProfile(Request $request, UpdateEmployeeProfile $updateProfile)
    {
        $employee = $request->user()?->employee;

        if (! $employee instanceof Employee) {
            return response()->json(['message' => 'No employee record'], 404);
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $updateProfile->handle($employee, $validated);

        return response()->json(['employee' => $this->employeePayload($request->user())]);
    }

    public function updatePassword(Request $request, UpdateUserPassword $updatePassword)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $updatePassword->handle($request->user(), $request->string('password')->value());

        return response()->json(['message' => 'Password updated']);
    }

    /**
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
