import { api } from '@hris/shared';
import { TrendingUp, Download } from 'lucide-react';
import React, { useState, useEffect, useCallback } from 'react';

interface AttendanceRecord {
  id: number;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: 'present' | 'late' | 'absent';
}

interface MonthSummary {
  key: string;
  label: string;
  present: number;
  late: number;
  absent: number;
  total: number;
  rate: number;
}

interface ReportPageProps {
  isMock: boolean;
}

const MOCK_HISTORY: AttendanceRecord[] = [
  ...Array.from({ length: 18 }, (_, i) => {
    const d = new Date(2026, 5, i + 1);
    if (d.getDay() === 0 || d.getDay() === 6) return null;
    return {
      id: 100 + i,
      date: `2026-06-${String(i + 1).padStart(2, '0')}`,
      check_in: '08:00:00',
      check_out: '17:00:00',
      status: (i % 9 === 3 ? 'late' : 'present') as 'present' | 'late',
    };
  }).filter(Boolean) as AttendanceRecord[],
  ...Array.from({ length: 22 }, (_, i) => {
    const d = new Date(2026, 4, i + 1);
    if (d.getDay() === 0 || d.getDay() === 6) return null;
    return {
      id: 200 + i,
      date: `2026-05-${String(i + 1).padStart(2, '0')}`,
      check_in: '08:00:00',
      check_out: '17:00:00',
      status: (i % 11 === 5 ? 'late' : i % 15 === 7 ? 'absent' : 'present') as 'present' | 'late' | 'absent',
    };
  }).filter(Boolean) as AttendanceRecord[],
];

function buildMonthSummaries(records: AttendanceRecord[]): MonthSummary[] {
  const grouped = new Map<string, AttendanceRecord[]>();
  records.forEach((r) => {
    const key = r.date.slice(0, 7);
    if (!grouped.has(key)) grouped.set(key, []);
    grouped.get(key)!.push(r);
  });

  return Array.from(grouped.entries())
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([key, recs]) => {
      const present = recs.filter((r) => r.status === 'present').length;
      const late = recs.filter((r) => r.status === 'late').length;
      const absent = recs.filter((r) => r.status === 'absent').length;
      const total = recs.length;
      const rate = total > 0 ? Math.round(((present + late) / total) * 100) : 0;
      const [y, m] = key.split('-');
      const label = new Date(parseInt(y), parseInt(m) - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
      return { key, label, present, late, absent, total, rate };
    });
}

export default function ReportPage({ isMock }: ReportPageProps) {
  const [summaries, setSummaries] = useState<MonthSummary[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    try {
      let records: AttendanceRecord[];
      if (isMock) {
        await new Promise((r) => setTimeout(r, 500));
        records = MOCK_HISTORY;
      } else {
        const data = await api.get<{ history: AttendanceRecord[] }>('/attendance/history');
        records = data.history;
      }
      setSummaries(buildMonthSummaries(records));
    } catch {
      if (isMock) setSummaries(buildMonthSummaries(MOCK_HISTORY));
    } finally {
      setIsLoading(false);
    }
  }, [isMock]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const currentSummary = summaries[0];

  return (
    <div className="space-y-5 animate-in fade-in duration-300">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <TrendingUp size={18} className="text-primary" />
          <div>
            <h2 className="text-lg font-bold tracking-tight text-foreground">Laporan Kehadiran</h2>
            <p className="text-[11px] text-muted-foreground">Rekap per bulan</p>
          </div>
        </div>
      </div>

      {/* Current month highlight */}
      {currentSummary && !isLoading && (
        <div className="bg-card border border-border rounded-2xl p-5 shadow-sm relative overflow-hidden">
          <div className="absolute -right-10 -top-10 w-28 h-28 bg-primary/5 rounded-full blur-2xl" />
          <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest mb-3">Bulan Ini</p>
          <div className="flex items-end justify-between">
            <div>
              <p className="capitalize text-base font-bold text-foreground">{currentSummary.label}</p>
              <p className="text-xs text-muted-foreground mt-0.5">{currentSummary.total} hari kerja tercatat</p>
            </div>
            <div className="text-right">
              <p
                className={`text-3xl font-extrabold ${
                  currentSummary.rate >= 90
                    ? 'text-emerald-400'
                    : currentSummary.rate >= 75
                    ? 'text-amber-400'
                    : 'text-rose-400'
                }`}
              >
                {currentSummary.rate}%
              </p>
              <p className="text-[10px] text-muted-foreground">tingkat kehadiran</p>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3 mt-4">
            {[
              { label: 'Hadir', value: currentSummary.present, color: 'text-emerald-400' },
              { label: 'Terlambat', value: currentSummary.late, color: 'text-amber-400' },
              { label: 'Tidak Hadir', value: currentSummary.absent, color: 'text-rose-400' },
            ].map((s) => (
              <div key={s.label} className="text-center bg-secondary/50 rounded-xl p-2.5">
                <p className={`text-lg font-bold ${s.color}`}>{s.value}</p>
                <p className="text-[9px] text-muted-foreground">{s.label}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Monthly table */}
      <div className="bg-card border border-border rounded-2xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-border">
          <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest">Riwayat Bulanan</p>
        </div>

        {isLoading ? (
          <div className="p-4 space-y-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-12 rounded-xl bg-muted animate-pulse" />
            ))}
          </div>
        ) : summaries.length === 0 ? (
          <div className="p-8 text-center text-muted-foreground text-xs">Belum ada data kehadiran</div>
        ) : (
          <div className="divide-y divide-border">
            {summaries.map((s) => (
              <div key={s.key} className="flex items-center justify-between px-4 py-3">
                <div>
                  <p className="text-xs font-semibold text-foreground capitalize">{s.label}</p>
                  <p className="text-[10px] text-muted-foreground mt-0.5">
                    {s.present}H · {s.late}T · {s.absent}A · {s.total} hari
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <div className="w-16 h-1.5 bg-secondary rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full ${
                        s.rate >= 90 ? 'bg-emerald-400' : s.rate >= 75 ? 'bg-amber-400' : 'bg-rose-400'
                      }`}
                      style={{ width: `${s.rate}%` }}
                    />
                  </div>
                  <span
                    className={`text-xs font-bold w-8 text-right ${
                      s.rate >= 90 ? 'text-emerald-400' : s.rate >= 75 ? 'text-amber-400' : 'text-rose-400'
                    }`}
                  >
                    {s.rate}%
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Legend */}
      <p className="text-[10px] text-muted-foreground text-center px-4">
        H = Hadir · T = Terlambat · A = Tidak Hadir
      </p>

      {/* Export hint */}
      <div className="flex items-center gap-2 p-3 rounded-xl bg-secondary border border-border text-xs text-muted-foreground">
        <Download size={14} />
        <span>Untuk ekspor CSV lengkap, hubungi admin HR melalui portal admin.</span>
      </div>
    </div>
  );
}
