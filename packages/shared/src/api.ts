/**
 * Sleek, lightweight, and type-safe Fetch-based API client for communicating with the Laravel backend.
 * Supports base URLs, request/response interceptors, cookie-based CSRF for Sanctum, and token headers.
 */

export interface ApiClientConfig {
  baseUrl?: string;
  headers?: Record<string, string>;
  withCredentials?: boolean;
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public statusText: string,
    message: string,
    public data: any = null
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export class ApiClient {
  private baseUrl: string;
  private defaultHeaders: Record<string, string>;
  private withCredentials: boolean;

  constructor(config: ApiClientConfig = {}) {
    this.baseUrl = config.baseUrl || '/api';
    this.defaultHeaders = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(config.headers || {}),
    };
    this.withCredentials = config.withCredentials ?? true;
  }

  /**
   * For Laravel Sanctum cookie authentication, we must fetch the CSRF token first.
   */
  async ensureCsrf(): Promise<void> {
    try {
      // In this setup, csrf-cookie is served at /api/csrf-cookie (proxied) or relative to baseUrl
      const url = this.baseUrl.endsWith('/api') ? '/api/csrf-cookie' : `${this.baseUrl}/csrf-cookie`;
      await fetch(url, {
        method: 'GET',
        credentials: this.withCredentials ? 'include' : 'same-origin',
      });
    } catch (error) {
      console.warn('Failed to pre-fetch CSRF cookie. If using token-based auth, this can be ignored.', error);
    }
  }

  private getCsrfTokenFromCookie(): string | null {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(new RegExp('(^|;\\s*)XSRF-TOKEN=([^;]*)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  private async request<T = any>(
    path: string,
    options: RequestInit = {}
  ): Promise<T> {
    const url = path.startsWith('http') ? path : `${this.baseUrl}${path}`;
    const headers = { ...this.defaultHeaders, ...(options.headers as Record<string, string>) };

    // FormData bodies need the browser to set Content-Type itself (it carries
    // the multipart boundary). Our default application/json must be removed,
    // otherwise Laravel parses the request as JSON and sees an empty body.
    if (options.body instanceof FormData) {
      delete headers['Content-Type'];
    }

    // Inject CSRF token if running in a browser context and using stateful cookies
    if (typeof document !== 'undefined') {
      const csrfToken = this.getCsrfTokenFromCookie();
      if (csrfToken && !['GET', 'HEAD', 'OPTIONS'].includes(options.method || 'GET')) {
        headers['X-XSRF-TOKEN'] = csrfToken;
      }
    }

    const response = await fetch(url, {
      ...options,
      headers,
      credentials: this.withCredentials ? 'include' : 'same-origin',
    });

    if (!response.ok) {
      let errorData;
      try {
        errorData = await response.json();
      } catch {
        errorData = await response.text();
      }
      throw new ApiError(
        response.status,
        response.statusText,
        errorData?.message || `Request failed with status ${response.status}`,
        errorData
      );
    }

    if (response.status === 204) {
      return {} as T;
    }

    return response.json();
  }

  async get<T = any>(path: string, options?: Omit<RequestInit, 'method'>): Promise<T> {
    return this.request<T>(path, { ...options, method: 'GET' });
  }

  async post<T = any>(path: string, data?: any, options?: Omit<RequestInit, 'method' | 'body'>): Promise<T> {
    return this.request<T>(path, {
      ...options,
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  /**
   * Submit a multipart/form-data body (file uploads, mixed fields).
   *
   * Content-Type is intentionally left unset so the browser attaches the
   * multipart boundary itself — the actual removal of our default
   * `application/json` header happens in request() (which detects FormData
   * bodies).
   */
  async postForm<T = any>(path: string, formData: FormData, options?: Omit<RequestInit, 'method' | 'body'>): Promise<T> {
    return this.request<T>(path, {
      ...options,
      method: 'POST',
      body: formData,
    });
  }

  async put<T = any>(path: string, data?: any, options?: Omit<RequestInit, 'method' | 'body'>): Promise<T> {
    return this.request<T>(path, {
      ...options,
      method: 'PUT',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  async delete<T = any>(path: string, options?: Omit<RequestInit, 'method'>): Promise<T> {
    return this.request<T>(path, { ...options, method: 'DELETE' });
  }
}

// Instantiate default API client pointing to relative /api (or fallback to port 8080)
export const api = new ApiClient({
  baseUrl: typeof window !== 'undefined' 
    ? '/api' 
    : 'http://localhost:8080/api',
});
