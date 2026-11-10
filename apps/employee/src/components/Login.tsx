import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api } from '@hris/shared';
import { ShieldAlert, Sparkles } from 'lucide-react';
import React, { useState } from 'react';

interface LoginProps {
  onLoginSuccess: (user: any, employee: any, isMock: boolean) => void;
  apiStatus: 'checking' | 'connected' | 'mock';
}

export default function Login({ onLoginSuccess, apiStatus }: LoginProps) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});

  const validateForm = () => {
    const errors: { email?: string; password?: string } = {};
    if (!email) {
      errors.email = 'Email atau username wajib diisi';
    } else if (email.length < 3) {
      errors.email = 'Email atau username minimal 3 karakter';
    }
    if (!password) {
      errors.password = 'Password wajib diisi';
    } else if (password.length < 6) {
      errors.password = 'Password minimal 6 karakter';
    }
    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});

    if (!validateForm()) return;

    setIsLoading(true);

    try {
      if (apiStatus === 'mock' || apiStatus === 'checking') {
        // Simulated Mock Authentication
        await new Promise((resolve) => setTimeout(resolve, 1000));

        const loginInput = email.toLowerCase();
        if ((loginInput === 'budi@hris.local' || loginInput === 'budi') && password === 'password') {
          onLoginSuccess(
            { id: 2, name: 'Budi Setiawan', username: 'budi', email: 'budi@hris.local' },
            {
              employee_number: 'EMP0001',
              name: 'Budi Setiawan',
              email: 'budi@hris.local',
              phone: '+628123456789',
              department: 'Tech Division',
              position: 'Senior Software Engineer',
              status: 'active',
            },
            true
          );
        } else {
          setError('Email/username atau password salah. (Gunakan: budi atau budi@hris.local & password: password)');
        }
      } else {
        // Real API Authentication
        try {
          await api.ensureCsrf();
          const data = await api.post('/login', {
            email,
            password,
            remember: rememberMe,
          });
          onLoginSuccess(data.user, data.employee, false);
        } catch (err: any) {
          if (err.status === 422 && err.data?.errors) {
            const errors: any = {};
            if (err.data.errors.email) errors.email = err.data.errors.email[0];
            if (err.data.errors.password) errors.password = err.data.errors.password[0];
            setFieldErrors(errors);
            setError(null);
          } else {
            setError(err.message || 'Login gagal, silakan coba lagi');
          }
        }
      }
    } catch (err: any) {
      if (!fieldErrors.email && !fieldErrors.password) {
        setError(err.message || 'Terjadi kesalahan sistem. Silakan coba kembali.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-background text-foreground flex flex-col justify-center max-w-md mx-auto relative border-x border-border shadow-2xl overflow-hidden px-6 py-12">
      {/* Visual background lights aligned with dashboard theme */}
      <div className="absolute -left-24 top-20 w-72 h-72 rounded-full bg-chart-1/5 blur-3xl"></div>
      <div className="absolute -right-24 bottom-20 w-72 h-72 rounded-full bg-chart-2/5 blur-3xl"></div>

      <div className="w-full space-y-8 relative z-10">
        {/* Brand Header */}
        <div className="text-center space-y-2">
          <div className="inline-flex w-12 h-12 rounded-xl bg-primary text-primary-foreground items-center justify-center font-bold text-xl shadow-md mb-2">
            H
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">
            HRIS Modern Suite
          </h1>
          <p className="text-xs text-muted-foreground font-semibold uppercase tracking-wider">
            Portal Karyawan
          </p>
        </div>

        {/* Status Indicator */}
        <div className="flex justify-center">
          <div className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-bold tracking-wider uppercase transition-all ${
            apiStatus === 'connected'
              ? 'bg-green-500/10 text-green-600 border-green-500/20 dark:text-green-400'
              : 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400'
          }`}>
            <Sparkles size={10} className={apiStatus === 'connected' ? 'animate-bounce' : 'animate-spin'} />
            {apiStatus === 'connected' ? 'Mode API Aktif' : 'Mode Demo / Offline'}
          </div>
        </div>

        {/* Error Alert */}
        {error && (
          <div className="bg-destructive/10 border border-destructive/20 text-destructive rounded-xl p-4 flex gap-3 text-xs leading-relaxed animate-in fade-in slide-in-from-top-2 duration-300">
            <ShieldAlert size={16} className="shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {/* Login Form Container (Using Card theme styles) */}
        <div className="bg-card border border-border rounded-2xl p-6 shadow-sm">
          <form onSubmit={handleSubmit} className="space-y-5">
            {/* Email Field */}
            <div className="grid gap-2">
              <Label htmlFor="email">Email atau Username</Label>
              <Input
                id="email"
                type="text"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Masukkan email atau username"
                disabled={isLoading}
                aria-invalid={!!fieldErrors.email}
              />
              <InputError message={fieldErrors.email} />
            </div>

            {/* Password Field */}
            <div className="grid gap-2">
              <div className="flex justify-between items-center">
                <Label htmlFor="password">Password</Label>
                <a href="#" className="text-xs text-muted-foreground hover:text-foreground transition-colors">
                  Lupa?
                </a>
              </div>
              <PasswordInput
                id="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Masukkan password"
                disabled={isLoading}
                aria-invalid={!!fieldErrors.password}
              />
              <InputError message={fieldErrors.password} />
            </div>

            {/* Remember Me checkbox using base-ui/shadcn */}
            <div className="flex items-center space-x-2">
              <Checkbox
                id="remember"
                checked={rememberMe}
                onCheckedChange={(checked) => setRememberMe(!!checked)}
                disabled={isLoading}
              />
              <Label htmlFor="remember" className="text-xs text-muted-foreground cursor-pointer font-normal uppercase-none">
                Ingat saya di perangkat ini
              </Label>
            </div>

            {/* Submit Button */}
            <Button
              type="submit"
              disabled={isLoading}
              className="w-full h-10 mt-2"
            >
              {isLoading && <Spinner className="mr-2" />}
              Masuk Sekarang
            </Button>
          </form>
        </div>

        {/* Demo Hint Box */}
        {(apiStatus === 'mock' || apiStatus === 'checking') && (
          <div className="bg-amber-500/5 border border-amber-500/10 rounded-xl p-4 space-y-1">
            <h4 className="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-widest flex items-center gap-1">
              💡 Petunjuk Demo:
            </h4>
            <p className="text-[10px] text-muted-foreground leading-relaxed">
              Karena backend sedang offline atau dalam mode demo, silakan masuk menggunakan akun uji coba:
            </p>
            <div className="text-[10px] font-mono text-primary bg-secondary/80 p-2 rounded-lg mt-1 border border-border">
              Email/Username: budi@hris.local atau budi <br />
              Sandi: password
            </div>
          </div>
        )}
      </div>
      
      {/* Brand Footer */}
      <footer className="mt-auto pt-8 text-center relative z-10">
        <p className="text-[9px] text-muted-foreground font-semibold tracking-widest uppercase">
          © {new Date().getFullYear()} HRIS Modern Suite | Built with Google DeepMind
        </p>
      </footer>
    </div>
  );
}
