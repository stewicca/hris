import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api, formatDate } from '@hris/shared';
import { LogOut, Pencil, Check, X, KeyRound, Loader2 } from 'lucide-react';
import React, { useState } from 'react';

interface ProfileProps {
  user: any;
  employee: any;
  isMock?: boolean;
  onLogout: () => void;
  onProfileUpdate?: (employee: any) => void;
}

export default function Profile({ user, employee, isMock, onLogout, onProfileUpdate }: ProfileProps) {
  const [editingPhone, setEditingPhone] = useState(false);
  const [phone, setPhone] = useState(employee?.phone ?? '');
  const [phoneSaving, setPhoneSaving] = useState(false);
  const [phoneError, setPhoneError] = useState<string>();

  const [pwOpen, setPwOpen] = useState(false);
  const [pwForm, setPwForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [pwSaving, setPwSaving] = useState(false);
  const [pwErrors, setPwErrors] = useState<Record<string, string>>({});
  const [pwSuccess, setPwSuccess] = useState(false);

  const savePhone = async () => {
    setPhoneError(undefined);
    if (isMock) {
      onProfileUpdate?.({ ...employee, phone });
      setEditingPhone(false);
      return;
    }
    setPhoneSaving(true);
    try {
      const data = await api.put('/profile', { phone });
      onProfileUpdate?.(data.employee);
      setEditingPhone(false);
    } catch (err: any) {
      setPhoneError(err?.data?.errors?.phone?.[0] ?? 'Gagal menyimpan nomor telepon.');
    } finally {
      setPhoneSaving(false);
    }
  };

  const changePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setPwErrors({});
    setPwSuccess(false);
    if (isMock) {
      setPwErrors({ current_password: 'Tidak tersedia dalam mode demo.' });
      return;
    }
    setPwSaving(true);
    try {
      await api.put('/password', pwForm);
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
      setPwSuccess(true);
      setPwOpen(false);
    } catch (err: any) {
      const errors = err?.data?.errors ?? {};
      setPwErrors(Object.fromEntries(Object.entries(errors).map(([k, v]: any) => [k, v[0]])));
    } finally {
      setPwSaving(false);
    }
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Header */}
      <div className="text-center space-y-2 py-4">
        <div className="w-16 h-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center font-bold text-primary text-2xl shadow-inner mx-auto uppercase">
          {user?.name ? user.name.split(' ').slice(0, 2).map((n: string) => n[0]).join('') : 'U'}
        </div>
        <h2 className="text-xl font-bold text-foreground mt-2">{user?.name}</h2>
        <p className="text-xs text-primary font-semibold">
          {employee?.position || 'Karyawan'} • {employee?.department || 'Divisi'}
        </p>
      </div>

      {/* Profile Info Card */}
      <div className="bg-card border border-border rounded-2xl p-5 space-y-4 shadow-sm">
        <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-widest pl-0.5">Informasi Karyawan</h3>

        <div className="space-y-3">
          <InfoRow label="Nomor Karyawan" value={employee?.employee_number || '-'} />
          <InfoRow label="Email" value={user?.email || '-'} />
          <InfoRow label="No. Rekening" value={employee?.bank_account_number || '-'} />

          {/* Phone — editable */}
          <div className="py-2 border-b border-border/40">
            <div className="flex justify-between items-center">
              <span className="text-xs text-muted-foreground">No. Telepon</span>
              {editingPhone ? (
                <div className="flex items-center gap-1.5">
                  <button
                    onClick={savePhone}
                    disabled={phoneSaving}
                    className="text-green-600 dark:text-green-400 disabled:opacity-50"
                    aria-label="Simpan"
                  >
                    {phoneSaving ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
                  </button>
                  <button
                    onClick={() => { setEditingPhone(false); setPhone(employee?.phone ?? ''); setPhoneError(undefined); }}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Batal"
                  >
                    <X size={15} />
                  </button>
                </div>
              ) : (
                <button
                  onClick={() => setEditingPhone(true)}
                  className="flex items-center gap-1.5 text-xs font-semibold text-foreground hover:text-primary transition-colors"
                >
                  {employee?.phone || '-'}
                  <Pencil size={12} className="text-muted-foreground" />
                </button>
              )}
            </div>
            {editingPhone && (
              <div className="mt-2">
                <Input
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="08xxxxxxxxxx"
                  className="h-9 text-sm"
                  autoFocus
                />
                <InputError message={phoneError} />
              </div>
            )}
          </div>

          <InfoRow label="Tanggal Bergabung" value={employee?.hire_date ? formatDate(employee.hire_date) : '-'} />
          <div className="flex justify-between items-center py-2 last:border-0">
            <span className="text-xs text-muted-foreground">Status Kepegawaian</span>
            <span className="px-2.5 py-0.5 rounded-full border text-[9px] font-bold bg-green-500/10 text-green-600 border-green-500/20 dark:text-green-400">
              {employee?.status === 'active' ? 'Aktif' : 'Non-Aktif'}
            </span>
          </div>
        </div>
      </div>

      {/* Change Password Card */}
      <div className="bg-card border border-border rounded-2xl p-5 shadow-sm">
        <button
          onClick={() => { setPwOpen((o) => !o); setPwSuccess(false); }}
          className="w-full flex items-center justify-between"
        >
          <span className="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase tracking-widest">
            <KeyRound size={13} /> Ubah Password
          </span>
          <span className="text-xs text-primary font-semibold">{pwOpen ? 'Tutup' : 'Buka'}</span>
        </button>

        {pwSuccess && (
          <p className="mt-3 text-xs font-semibold text-green-600 dark:text-green-400">Password berhasil diperbarui.</p>
        )}

        {pwOpen && (
          <form onSubmit={changePassword} className="mt-4 space-y-3">
            <div>
              <Label htmlFor="current_password" className="text-xs">Password Saat Ini</Label>
              <PasswordInput
                id="current_password"
                value={pwForm.current_password}
                onChange={(e) => setPwForm({ ...pwForm, current_password: e.target.value })}
                className="h-9 text-sm mt-1"
              />
              <InputError message={pwErrors.current_password} />
            </div>
            <div>
              <Label htmlFor="password" className="text-xs">Password Baru</Label>
              <PasswordInput
                id="password"
                value={pwForm.password}
                onChange={(e) => setPwForm({ ...pwForm, password: e.target.value })}
                className="h-9 text-sm mt-1"
              />
              <InputError message={pwErrors.password} />
            </div>
            <div>
              <Label htmlFor="password_confirmation" className="text-xs">Konfirmasi Password Baru</Label>
              <PasswordInput
                id="password_confirmation"
                value={pwForm.password_confirmation}
                onChange={(e) => setPwForm({ ...pwForm, password_confirmation: e.target.value })}
                className="h-9 text-sm mt-1"
              />
            </div>
            <Button type="submit" disabled={pwSaving} className="w-full h-10 rounded-xl font-bold text-sm">
              {pwSaving ? <Loader2 size={15} className="animate-spin mr-2" /> : null}
              Simpan Password
            </Button>
          </form>
        )}
      </div>

      {/* Logout */}
      <Button
        onClick={onLogout}
        variant="destructive"
        className="w-full h-11 rounded-xl font-bold text-sm tracking-wide shadow-sm active:scale-[0.98] transition-all"
      >
        <LogOut size={16} className="mr-2" />
        Log Out dari Akun
      </Button>
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between items-center py-2 border-b border-border/40 last:border-0">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-xs font-semibold text-foreground">{value}</span>
    </div>
  );
}
