<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DocsController extends Controller
{
    public function __invoke(): View
    {
        $endpoints = [
            [
                'method' => 'GET',
                'path' => '/api/status',
                'description' => 'Check the connection status and version of the API.',
                'response' => [
                    'status' => 'connected',
                    'version' => '1.0.0',
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/csrf-cookie',
                'description' => 'Fetch and seed the CSRF cookie for subsequent stateful POST requests.',
                'response' => [
                    'message' => 'CSRF cookie initialized successfully',
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/login',
                'description' => 'Authenticate the employee with their email and password.',
                'headers' => [
                    [
                        'name' => 'Accept',
                        'required' => true,
                        'description' => 'Must be application/json',
                    ],
                    [
                        'name' => 'Content-Type',
                        'required' => true,
                        'description' => 'Must be application/json',
                    ],
                ],
                'body' => 'JSON payload containing "email" (string, required, valid email format), "password" (string, required), and optional "remember" (boolean).',
                'response' => [
                    'message' => 'Login successful',
                    'user' => [
                        'id' => 2,
                        'name' => 'Budi Setiawan',
                        'username' => 'budi',
                        'email' => 'budi@hris.local',
                    ],
                    'employee' => [
                        'id' => 1,
                        'user_id' => 2,
                        'employee_number' => 'EMP0001',
                        'name' => 'Budi Setiawan',
                        'email' => 'budi@hris.local',
                        'phone' => '+628123456789',
                        'department' => 'Tech Division',
                        'position' => 'Senior Software Engineer',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/me',
                'description' => 'Retrieve the authenticated user and their linked employee profile (requires active session).',
                'headers' => [
                    [
                        'name' => 'Accept',
                        'required' => true,
                        'description' => 'Must be application/json',
                    ],
                ],
                'response' => [
                    'user' => [
                        'id' => 2,
                        'name' => 'Budi Setiawan',
                        'username' => 'budi',
                        'email' => 'budi@hris.local',
                    ],
                    'employee' => [
                        'id' => 1,
                        'user_id' => 2,
                        'employee_number' => 'EMP0001',
                        'name' => 'Budi Setiawan',
                        'email' => 'budi@hris.local',
                        'phone' => '+628123456789',
                        'department' => 'Tech Division',
                        'position' => 'Senior Software Engineer',
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/logout',
                'description' => 'Invalidate the user session and clear active authentication cookies.',
                'headers' => [
                    [
                        'name' => 'Accept',
                        'required' => true,
                        'description' => 'Must be application/json',
                    ],
                    [
                        'name' => 'X-XSRF-TOKEN',
                        'required' => true,
                        'description' => 'Valid CSRF token fetched during session init',
                    ],
                ],
                'response' => [
                    'message' => 'Logged out successfully',
                ],
            ],
        ];

        return view('api.docs', [
            'title' => config('app.name').' — API Documentation',
            'version' => 'v1',
            'endpoints' => $endpoints,
        ]);
    }
}
