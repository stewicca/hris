<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 860px;
            margin: 0 auto;
        }

        header {
            border-bottom: 1px solid #1e293b;
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }

        header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f8fafc;
        }

        header p {
            margin-top: 0.4rem;
            color: #94a3b8;
            font-size: 0.95rem;
        }

        .badge {
            display: inline-block;
            background: #1e3a5f;
            color: #93c5fd;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            margin-left: 0.5rem;
            vertical-align: middle;
        }

        .section-title {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .endpoint {
            background: #1e293b;
            border: 1px solid #2d3f55;
            border-radius: 0.75rem;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }

        .endpoint-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            cursor: pointer;
            user-select: none;
        }

        .method {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.6rem;
            border-radius: 0.375rem;
            text-transform: uppercase;
            min-width: 56px;
            text-align: center;
        }

        .method-any  { background: #4c1d95; color: #c4b5fd; }
        .method-get  { background: #14532d; color: #86efac; }
        .method-post { background: #1e3a5f; color: #93c5fd; }
        .method-put  { background: #78350f; color: #fcd34d; }
        .method-delete { background: #7f1d1d; color: #fca5a5; }

        .endpoint-path {
            font-family: 'Cascadia Code', 'Fira Code', monospace;
            font-size: 0.95rem;
            color: #f1f5f9;
            flex: 1;
        }

        .endpoint-desc {
            color: #94a3b8;
            font-size: 0.875rem;
        }

        .endpoint-body {
            border-top: 1px solid #2d3f55;
            padding: 1.25rem;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
            margin-top: 1rem;
        }

        .detail-label:first-child { margin-top: 0; }

        .detail-text {
            font-size: 0.875rem;
            color: #cbd5e1;
            line-height: 1.6;
        }

        .code-block {
            background: #0f172a;
            border: 1px solid #2d3f55;
            border-radius: 0.5rem;
            padding: 0.875rem 1rem;
            font-family: 'Cascadia Code', 'Fira Code', monospace;
            font-size: 0.825rem;
            color: #7dd3fc;
            overflow-x: auto;
            white-space: pre;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        th {
            text-align: left;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #2d3f55;
        }

        td {
            padding: 0.6rem 0.75rem;
            color: #cbd5e1;
            border-bottom: 1px solid #1e293b;
            vertical-align: top;
        }

        td:last-child { color: #94a3b8; }

        .tag-required {
            display: inline-block;
            background: #7f1d1d;
            color: #fca5a5;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.1rem 0.4rem;
            border-radius: 0.25rem;
            margin-left: 0.4rem;
        }

        .tag-optional {
            display: inline-block;
            background: #1e293b;
            color: #64748b;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.1rem 0.4rem;
            border-radius: 0.25rem;
            margin-left: 0.4rem;
        }

        footer {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid #1e293b;
            text-align: center;
            color: #475569;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>
                {{ config('app.name') }} API
                <span class="badge">{{ $version }}</span>
            </h1>
            <p>REST API documentation for {{ config('app.name') }}.</p>
        </header>

        <p class="section-title">Endpoints</p>

        @foreach ($endpoints as $endpoint)
            <div class="endpoint">
                <div class="endpoint-header">
                    <span class="method method-{{ strtolower($endpoint['method']) }}">
                        {{ $endpoint['method'] }}
                    </span>
                    <span class="endpoint-path">{{ $endpoint['path'] }}</span>
                    <span class="endpoint-desc">{{ $endpoint['description'] }}</span>
                </div>

                <div class="endpoint-body">
                    @if (!empty($endpoint['headers']))
                        <p class="detail-label">Headers</p>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($endpoint['headers'] as $header)
                                    <tr>
                                        <td>
                                            <code>{{ $header['name'] }}</code>
                                            @if ($header['required'])
                                                <span class="tag-required">required</span>
                                            @else
                                                <span class="tag-optional">optional</span>
                                            @endif
                                        </td>
                                        <td>{{ $header['required'] ? 'Yes' : 'No' }}</td>
                                        <td>{{ $header['description'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if (!empty($endpoint['body']))
                        <p class="detail-label">Request Body</p>
                        <p class="detail-text">{{ $endpoint['body'] }}</p>
                    @endif

                    @if (!empty($endpoint['response']))
                        <p class="detail-label">Response</p>
                        <div class="code-block">{{ json_encode($endpoint['response'], JSON_PRETTY_PRINT) }}</div>
                    @endif
                </div>
            </div>
        @endforeach

        <footer>
            {{ config('app.name') }} &mdash; {{ now()->year }}
        </footer>
    </div>
</body>
</html>
