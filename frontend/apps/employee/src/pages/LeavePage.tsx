import { Button } from '@/components/ui/button';
import { api, ApiError, getStatusColor } from '@hris/shared';
import {
  CalendarDays,
  Plus,
  X,
  Clock,
  CheckCircle2,
  XCircle,
  Loader2,
  AlertCircle,
} from 'lucide-react';
import React, { useState, useEffect, useCallback } from 'react';

interface Leave {
  id: number;
  type: 'annual' | 'sick' | 'permit';
  start_date: string;
  end_date: string;
  days: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  rejection_reason: string | null;
  created_at: string;
}

interface LeaveQuota {
  quota: number;
  used: number;
  pending: number;
  remaining: number;
}

const MOCK_QUOTA: LeaveQuota = { quota: 12, used: 5, pending: 1, remaining: 6 };

const TYPE_LABEL: Record<Leave['type'], string> = {
  annual: 'Cuti Tahunan',
  sick: 'Cuti Sakit',
  permit: 'Izin',
};

const STATUS_ICON: Record<Leave['status'], React.ReactNode> = {
  pending: <Clock size={14} className="text-amber-400" />,
  approved: <CheckCircle2 size={14} className="text-emerald-400" />,
  rejected: <XCircle size={14} className="text-rose-400" />,
};

function formatDate(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

interface LeavePageProps {
  isMock: boolean;
}

const MOCK_LEAVES: Leave[] = [
  {
    id: 1,
    type: 'annual',
    start_date: '2026-05-20',
    end_date: '2026-05-22',
    days: 3,
    reason: 'Liburan keluarga',
    status: 'approved',
    rejection_reason: null,
    created_at: '2026-05-15T10:00:00Z',
  },
  {
    id: 2,
    type: 'sick',
    start_date: '2026-06-01',
    end_date: '2026-06-02',
    days: 2,
    reason: 'Demam dan flu',
    status: 'approved',
    rejection_reason: null,
    created_at: '2026-06-01T08:00:00Z',
  },
  {
    id: 3,
    type: 'permit',
    start_date: '2026-06-10',
    end_date: '2026-06-10',
    days: 1,
    reason: 'Urusan administrasi',
    status: 'pending',
    rejection_reason: null,
    created_at: '2026-06-09T09:00:00Z',
  },
];

export default function LeavePage({ isMock }: LeavePageProps) {
  const [leaves, setLeaves] = useState<Leave[]>([]);
  const [quota, setQuota] = useState<LeaveQuota | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ type: 'annual', start_date: '', end_date: '', reason: '' });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const fetchLeaves = useCallback(async () => {
    setIsLoading(true);
    try {
      if (isMock) {
        await new Promise((r) => setTimeout(r, 500));
        setLeaves(MOCK_LEAVES);
        setQuota(MOCK_QUOTA);
      } else {
        const data = await api.get<{ leaves: Leave[]; quota: LeaveQuota }>('/leaves');
        setLeaves(data.leaves);
        setQuota(data.quota);
      }
    } catch {
      if (isMock) {
        setLeaves(MOCK_LEAVES);
        setQuota(MOCK_QUOTA);
      }
    } finally {
      setIsLoading(false);
    }
  }, [isMock]);

  useEffect(() => {
    fetchLeaves();
  }, [fetchLeaves]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});
    setSubmitError(null);

    const errors: Record<string, string> = {};
    if (!form.start_date) errors.start_date = 'Tanggal mulai wajib diisi';
    if (!form.end_date) errors.end_date = 'Tanggal selesai wajib diisi';
    if (form.end_date && form.start_date && form.end_date < form.start_date)
      errors.end_date = 'Tanggal selesai harus setelah tanggal mulai';
    if (!form.reason.trim()) errors.reason = 'Alasan wajib diisi';
    if (Object.keys(errors).length) { setFormErrors(errors); return; }

    setIsSubmitting(true);
    try {
      if (isMock) {
        await new Promise((r) => setTimeout(r, 800));
        const start = new Date(form.start_date);
        const end = new Date(form.end_date);
        const days = Math.round((end.getTime() - start.getTime()) / 86400000) + 1;
        setLeaves((prev) => [{
          id: Date.now(),
          type: form.type as Leave['type'],
          start_date: form.start_date,
          end_date: form.end_date,
          days,
          reason: form.reason,
          status: 'pending',
          rejection_reason: null,
          created_at: new Date().toISOString(),
        }, ...prev]);
      } else {
        await api.post('/leaves', form);
        await fetchLeaves();
      }
      setShowForm(false);
      setForm({ type: 'annual', start_date: '', end_date: '', reason: '' });
    } catch (err) {
      if (err instanceof ApiError && err.data?.errors) {
        const errs: Record<string, string> = {};
        Object.entries(err.data.errors).forEach(([k, v]) => { errs[k] = (v as string[])[0]; });
        setFormErrors(errs);
      } else if (err instanceof Error) {
        setSubmitError(err.message);
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const pendingCount = leaves.filter((l) => l.status === 'pending').length;

  return (
    <div className="space-y-5 animate-in fade-in duration-300">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2">
            <CalendarDays size={18} className="text-primary" />
            <h2 className="text-lg font-bold tracking-tight text-foreground">Cuti & Izin</h2>
          </div>
          <p className="text-[11px] text-muted-foreground mt-0.5">
            {pendingCount > 0 ? `${pendingCount} pengajuan menunggu persetujuan` : 'Semua pengajuan telah diproses'}
          </p>
        </div>
        <Button
          size="sm"
          onClick={() => setShowForm((v) => !v)}
          className="h-8 px-3 rounded-xl text-xs font-bold gap-1"
        >
          {showForm ? <X size={13} /> : <Plus size={13} />}
          {showForm ? 'Batal' : 'Ajukan'}
        </Button>
      </div>

      {/* Quota Summary */}
      {quota && (
        <div className="grid grid-cols-4 gap-2">
          {[
            { label: 'Kuota', value: quota.quota, accent: 'text-foreground' },
            { label: 'Terpakai', value: quota.used, accent: 'text-foreground' },
            { label: 'Menunggu', value: quota.pending, accent: 'text-amber-500' },
            { label: 'Sisa', value: quota.remaining, accent: 'text-primary' },
          ].map((item) => (
            <div key={item.label} className="bg-card border border-border rounded-2xl p-3 text-center shadow-sm">
              <p className={`text-xl font-bold ${item.accent}`}>{item.value}</p>
              <p className="text-[10px] text-muted-foreground mt-0.5">{item.label}</p>
            </div>
          ))}
        </div>
      )}

      {/* Create Form */}
      {showForm && (
        <form
          onSubmit={handleSubmit}
          className="bg-card border border-border rounded-2xl p-5 space-y-4 shadow-sm animate-in fade-in slide-in-from-top-2 duration-200"
        >
          <p className="text-xs font-bold text-muted-foreground uppercase tracking-widest">Pengajuan Baru</p>

          {submitError && (
            <div className="flex items-center gap-2 text-xs text-destructive bg-destructive/10 border border-destructive/20 rounded-xl p-3">
              <AlertCircle size={13} />
              <span>{submitError}</span>
            </div>
          )}

          {/* Type */}
          <div className="grid gap-1.5">
            <label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Jenis</label>
            <select
              value={form.type}
              onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}
              className="h-9 w-full rounded-xl border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="annual">Cuti Tahunan</option>
              <option value="sick">Cuti Sakit</option>
              <option value="permit">Izin</option>
            </select>
          </div>

          {/* Dates */}
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-1.5">
              <label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Mulai</label>
              <input
                type="date"
                value={form.start_date}
                onChange={(e) => setForm((f) => ({ ...f, start_date: e.target.value }))}
                className="h-9 w-full rounded-xl border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              />
              {formErrors.start_date && <p className="text-[10px] text-destructive">{formErrors.start_date}</p>}
            </div>
            <div className="grid gap-1.5">
              <label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Selesai</label>
              <input
                type="date"
                value={form.end_date}
                min={form.start_date}
                onChange={(e) => setForm((f) => ({ ...f, end_date: e.target.value }))}
                className="h-9 w-full rounded-xl border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
              />
              {formErrors.end_date && <p className="text-[10px] text-destructive">{formErrors.end_date}</p>}
            </div>
          </div>

          {/* Reason */}
          <div className="grid gap-1.5">
            <label className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Alasan</label>
            <textarea
              value={form.reason}
              onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}
              rows={3}
              maxLength={500}
              placeholder="Jelaskan alasan pengajuan cuti..."
              className="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring resize-none"
            />
            {formErrors.reason && <p className="text-[10px] text-destructive">{formErrors.reason}</p>}
          </div>

          <Button type="submit" disabled={isSubmitting} className="w-full h-10 rounded-xl font-bold text-sm">
            {isSubmitting && <Loader2 size={14} className="mr-2 animate-spin" />}
            {isSubmitting ? 'Mengirim...' : 'Kirim Pengajuan'}
          </Button>
        </form>
      )}

      {/* Leave List */}
      {isLoading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-20 rounded-2xl bg-muted animate-pulse border border-border" />
          ))}
        </div>
      ) : leaves.length === 0 ? (
        <div className="text-center py-12 text-muted-foreground">
          <CalendarDays size={32} className="mx-auto mb-3 opacity-30" />
          <p className="text-sm font-semibold">Belum ada pengajuan cuti</p>
          <p className="text-xs mt-1">Tekan tombol Ajukan untuk membuat pengajuan baru</p>
        </div>
      ) : (
        <div className="space-y-3">
          {leaves.map((leave) => (
            <div
              key={leave.id}
              className="bg-card border border-border rounded-2xl p-4 shadow-sm"
            >
              <div className="flex items-start justify-between">
                <div className="space-y-1 flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-bold text-foreground">{TYPE_LABEL[leave.type]}</span>
                    <span className="text-[9px] text-muted-foreground">•</span>
                    <span className="text-[10px] text-muted-foreground">{leave.days} hari</span>
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    {formatDate(leave.start_date)}
                    {leave.start_date !== leave.end_date && ` — ${formatDate(leave.end_date)}`}
                  </p>
                  <p className="text-[11px] text-foreground/70 line-clamp-1">{leave.reason}</p>
                  {leave.rejection_reason && (
                    <p className="text-[11px] text-destructive bg-destructive/10 rounded-lg px-2 py-1 mt-1">
                      {leave.rejection_reason}
                    </p>
                  )}
                </div>
                <div className={`flex items-center gap-1 px-2.5 py-1 rounded-full border text-[9px] font-bold ml-3 shrink-0 ${getStatusColor(leave.status)}`}>
                  {STATUS_ICON[leave.status]}
                  <span>{leave.status === 'pending' ? 'Menunggu' : leave.status === 'approved' ? 'Disetujui' : 'Ditolak'}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
