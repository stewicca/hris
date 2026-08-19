import { api, formatCurrency } from '@hris/shared';
import { Banknote, ChevronDown, ChevronUp, Printer } from 'lucide-react';
import React, { useState, useEffect, useCallback } from 'react';

interface Payslip {
  id: string;
  period: string;
  status: 'paid' | 'pending';
  gross: number;
  deductions: number;
  net: number;
  components: { label: string; amount: number; type: 'income' | 'deduction' }[];
}

const MOCK_PAYSLIPS: Payslip[] = [
  {
    id: 'p-2026-06',
    period: 'Juni 2026',
    status: 'pending',
    gross: 6500000,
    deductions: 780000,
    net: 5720000,
    components: [
      { label: 'Gaji Pokok', amount: 5000000, type: 'income' },
      { label: 'Tunjangan Transport', amount: 500000, type: 'income' },
      { label: 'Tunjangan Makan', amount: 750000, type: 'income' },
      { label: 'Tunjangan Kinerja', amount: 250000, type: 'income' },
      { label: 'BPJS Kesehatan', amount: 130000, type: 'deduction' },
      { label: 'BPJS Ketenagakerjaan', amount: 200000, type: 'deduction' },
      { label: 'PPh 21', amount: 450000, type: 'deduction' },
    ],
  },
  {
    id: 'p-2026-05',
    period: 'Mei 2026',
    status: 'paid',
    gross: 6500000,
    deductions: 780000,
    net: 5720000,
    components: [
      { label: 'Gaji Pokok', amount: 5000000, type: 'income' },
      { label: 'Tunjangan Transport', amount: 500000, type: 'income' },
      { label: 'Tunjangan Makan', amount: 750000, type: 'income' },
      { label: 'Tunjangan Kinerja', amount: 250000, type: 'income' },
      { label: 'BPJS Kesehatan', amount: 130000, type: 'deduction' },
      { label: 'BPJS Ketenagakerjaan', amount: 200000, type: 'deduction' },
      { label: 'PPh 21', amount: 450000, type: 'deduction' },
    ],
  },
];

interface SalaryPageProps {
  isMock: boolean;
}

export default function SalaryPage({ isMock }: SalaryPageProps) {
  const [payslips, setPayslips] = useState<Payslip[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [expanded, setExpanded] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    try {
      let salaries: Payslip[];
      if (isMock) {
        await new Promise((r) => setTimeout(r, 400));
        salaries = MOCK_PAYSLIPS;
      } else {
        const data = await api.get<{ salaries: Payslip[] }>('/salary');
        salaries = data.salaries;
      }
      setPayslips(salaries);
      setExpanded(salaries[0]?.id ?? null);
    } catch {
      setPayslips([]);
    } finally {
      setIsLoading(false);
    }
  }, [isMock]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const latest = payslips[0];

  return (
    <div className="space-y-5 animate-in fade-in duration-300">
      {/* Header */}
      <div className="flex items-center gap-2">
        <Banknote size={18} className="text-primary" />
        <div>
          <h2 className="text-lg font-bold tracking-tight text-foreground">Slip Gaji</h2>
          <p className="text-[11px] text-muted-foreground">Riwayat penggajian Anda</p>
        </div>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          <div className="h-28 rounded-2xl bg-muted animate-pulse border border-border" />
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-16 rounded-2xl bg-muted animate-pulse border border-border" />
          ))}
        </div>
      ) : payslips.length === 0 ? (
        <div className="text-center py-12 text-muted-foreground">
          <Banknote size={32} className="mx-auto mb-3 opacity-30" />
          <p className="text-sm font-semibold">Belum ada slip gaji</p>
          <p className="text-[11px] mt-1">Slip gaji akan muncul setelah diterbitkan oleh HR.</p>
        </div>
      ) : (
        <>
          {/* Net salary highlight */}
          {latest && (
            <div className="bg-card border border-border rounded-2xl p-5 relative overflow-hidden shadow-sm">
              <div className="absolute -right-12 -top-12 w-32 h-32 bg-emerald-500/5 rounded-full blur-2xl" />
              <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest mb-1">
                Gaji Bersih — {latest.period}
              </p>
              <p className="text-3xl font-extrabold text-foreground tracking-tight">
                {formatCurrency(latest.net)}
              </p>
              <div className="flex gap-4 mt-3 text-xs text-muted-foreground">
                <span>Bruto: <span className="font-semibold text-foreground">{formatCurrency(latest.gross)}</span></span>
                <span>Potongan: <span className="font-semibold text-rose-400">-{formatCurrency(latest.deductions)}</span></span>
              </div>
              <div className="mt-3 flex items-center gap-1.5">
                <span className={`w-1.5 h-1.5 rounded-full ${latest.status === 'paid' ? 'bg-emerald-400' : 'bg-amber-400 animate-pulse'}`} />
                <span className={`text-[10px] font-semibold ${latest.status === 'paid' ? 'text-emerald-500' : 'text-amber-500'}`}>
                  {latest.status === 'paid' ? 'Sudah dibayar' : 'Menunggu pembayaran'}
                </span>
              </div>
            </div>
          )}

          {/* Payslip list */}
          <div className="space-y-3">
            <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest px-1">Riwayat Slip Gaji</p>

            {payslips.map((p) => (
              <div key={p.id} className="bg-card border border-border rounded-2xl overflow-hidden shadow-sm">
                <button
                  onClick={() => setExpanded(expanded === p.id ? null : p.id)}
                  className="w-full flex items-center justify-between p-4 hover:bg-muted/30 transition-colors"
                >
                  <div className="flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-secondary border border-border flex items-center justify-center">
                      <Banknote size={15} className="text-muted-foreground" />
                    </div>
                    <div className="text-left">
                      <p className="text-sm font-bold text-foreground">{p.period}</p>
                      <p className="text-xs font-semibold text-emerald-400">{formatCurrency(p.net)}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <span
                      className={`px-2 py-0.5 rounded-full border text-[9px] font-bold ${
                        p.status === 'paid'
                          ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                          : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                      }`}
                    >
                      {p.status === 'paid' ? 'Dibayar' : 'Proses'}
                    </span>
                    {expanded === p.id ? (
                      <ChevronUp size={15} className="text-muted-foreground" />
                    ) : (
                      <ChevronDown size={15} className="text-muted-foreground" />
                    )}
                  </div>
                </button>

                {expanded === p.id && (
                  <div className="px-4 pb-4 space-y-1 border-t border-border pt-3 animate-in fade-in duration-150">
                    {p.components.map((c) => (
                      <div key={c.label} className="flex justify-between items-center py-1.5 border-b border-border/40 last:border-0">
                        <span className="text-xs text-muted-foreground">{c.label}</span>
                        <span
                          className={`text-xs font-semibold ${
                            c.type === 'income' ? 'text-foreground' : 'text-rose-400'
                          }`}
                        >
                          {c.type === 'deduction' ? '-' : '+'}{formatCurrency(c.amount)}
                        </span>
                      </div>
                    ))}
                    <div className="flex justify-between items-center pt-2 mt-1">
                      <span className="text-xs font-bold text-foreground">Total Bersih</span>
                      <span className="text-sm font-extrabold text-emerald-400">{formatCurrency(p.net)}</span>
                    </div>
                    {!isMock && (
                      <button
                        onClick={() => window.open(`/api/salary/${p.id}/print`, '_blank')}
                        className="mt-3 w-full flex items-center justify-center gap-1.5 rounded-xl border border-border bg-secondary py-2.5 text-[11px] font-bold text-foreground hover:bg-muted transition-colors"
                      >
                        <Printer size={13} />
                        Cetak / Unduh PDF
                      </button>
                    )}
                  </div>
                )}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
