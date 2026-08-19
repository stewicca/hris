import { api } from '@hris/shared';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import React, { useState, useEffect, useCallback } from 'react';

interface AttendanceRecord {
  id: number;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: 'present' | 'late' | 'absent';
}

interface LeaveRecord {
  id: number;
  start_date: string;
  end_date: string;
  status: 'pending' | 'approved' | 'rejected';
  type: string;
}

const DAYS = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

const MOCK_ATTENDANCE: AttendanceRecord[] = Array.from({ length: 20 }, (_, i) => {
  const d = new Date(2026, 5, i + 1);
  if (d.getDay() === 0 || d.getDay() === 6) return null;
  const status = i % 7 === 3 ? 'late' : 'present';
  return { id: i + 1, date: `2026-06-${String(i + 1).padStart(2, '0')}`, check_in: '08:00:00', check_out: '17:00:00', status };
}).filter(Boolean) as AttendanceRecord[];

const MOCK_LEAVES: LeaveRecord[] = [
  { id: 1, start_date: '2026-06-10', end_date: '2026-06-10', status: 'pending', type: 'permit' },
  { id: 2, start_date: '2026-05-20', end_date: '2026-05-22', status: 'approved', type: 'annual' },
];

interface CalendarPageProps {
  isMock: boolean;
}

export default function CalendarPage({ isMock }: CalendarPageProps) {
  const [viewDate, setViewDate] = useState(new Date());
  const [attendance, setAttendance] = useState<AttendanceRecord[]>([]);
  const [leaves, setLeaves] = useState<LeaveRecord[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [selected, setSelected] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    try {
      if (isMock) {
        await new Promise((r) => setTimeout(r, 400));
        setAttendance(MOCK_ATTENDANCE);
        setLeaves(MOCK_LEAVES);
      } else {
        const [attData, leaveData] = await Promise.all([
          api.get<{ history: AttendanceRecord[] }>('/attendance/history'),
          api.get<{ leaves: LeaveRecord[] }>('/leaves'),
        ]);
        setAttendance(attData.history);
        setLeaves(leaveData.leaves);
      }
    } catch {
      if (isMock) { setAttendance(MOCK_ATTENDANCE); setLeaves(MOCK_LEAVES); }
    } finally {
      setIsLoading(false);
    }
  }, [isMock]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const year = viewDate.getFullYear();
  const month = viewDate.getMonth();

  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  const todayStr = new Date().toISOString().slice(0, 10);

  const attendanceMap = new Map(attendance.map((a) => [a.date, a]));

  const isOnLeave = (dateStr: string) =>
    leaves.some(
      (l) => l.status === 'approved' && dateStr >= l.start_date && dateStr <= l.end_date
    );

  const isPendingLeave = (dateStr: string) =>
    leaves.some(
      (l) => l.status === 'pending' && dateStr >= l.start_date && dateStr <= l.end_date
    );

  const cells: (number | null)[] = [
    ...Array(firstDay).fill(null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];

  const prevMonth = () => setViewDate((d) => new Date(d.getFullYear(), d.getMonth() - 1, 1));
  const nextMonth = () => setViewDate((d) => new Date(d.getFullYear(), d.getMonth() + 1, 1));

  const monthName = viewDate.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });

  const selectedDateStr = selected
    ? `${year}-${String(month + 1).padStart(2, '0')}-${String(parseInt(selected)).padStart(2, '0')}`
    : null;
  const selectedAttendance = selectedDateStr ? attendanceMap.get(selectedDateStr) : undefined;
  const selectedLeave = selectedDateStr
    ? leaves.find((l) => selectedDateStr! >= l.start_date && selectedDateStr! <= l.end_date)
    : undefined;

  const stats = {
    present: attendance.filter((a) => a.status === 'present' && a.date.startsWith(`${year}-${String(month + 1).padStart(2, '0')}`)).length,
    late: attendance.filter((a) => a.status === 'late' && a.date.startsWith(`${year}-${String(month + 1).padStart(2, '0')}`)).length,
    leave: leaves.filter((l) => l.status === 'approved' && (l.start_date.startsWith(`${year}-${String(month + 1).padStart(2, '0')}`) || l.end_date.startsWith(`${year}-${String(month + 1).padStart(2, '0')}`))).length,
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-300">
      {/* Header */}
      <div className="flex items-center justify-between">
        <button
          onClick={prevMonth}
          className="w-8 h-8 rounded-xl bg-secondary border border-border flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
        >
          <ChevronLeft size={15} />
        </button>
        <h2 className="text-base font-bold tracking-tight text-foreground capitalize">{monthName}</h2>
        <button
          onClick={nextMonth}
          className="w-8 h-8 rounded-xl bg-secondary border border-border flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
        >
          <ChevronRight size={15} />
        </button>
      </div>

      {/* Month Stats */}
      <div className="grid grid-cols-3 gap-2">
        {[
          { label: 'Hadir', value: stats.present, color: 'text-emerald-400' },
          { label: 'Terlambat', value: stats.late, color: 'text-amber-400' },
          { label: 'Cuti', value: stats.leave, color: 'text-blue-400' },
        ].map((s) => (
          <div key={s.label} className="bg-card border border-border rounded-xl p-3 text-center">
            <p className={`text-lg font-bold ${s.color}`}>{isLoading ? '—' : s.value}</p>
            <p className="text-[10px] text-muted-foreground mt-0.5">{s.label}</p>
          </div>
        ))}
      </div>

      {/* Calendar Grid */}
      <div className="bg-card border border-border rounded-2xl p-4 shadow-sm">
        {/* Day headers */}
        <div className="grid grid-cols-7 mb-2">
          {DAYS.map((d) => (
            <div key={d} className="text-center text-[10px] font-bold text-muted-foreground uppercase tracking-wider py-1">
              {d}
            </div>
          ))}
        </div>

        {/* Date cells */}
        <div className="grid grid-cols-7 gap-y-1">
          {cells.map((day, idx) => {
            if (!day) return <div key={`empty-${idx}`} />;

            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const att = attendanceMap.get(dateStr);
            const onLeave = isOnLeave(dateStr);
            const pending = isPendingLeave(dateStr);
            const isToday = dateStr === todayStr;
            const isSelected = selected === String(day);
            const isFuture = dateStr > todayStr;
            const d = new Date(year, month, day);
            const isWeekend = d.getDay() === 0 || d.getDay() === 6;

            let dotColor = '';
            if (onLeave) dotColor = 'bg-blue-400';
            else if (pending) dotColor = 'bg-blue-400/50';
            else if (att?.status === 'present') dotColor = 'bg-emerald-400';
            else if (att?.status === 'late') dotColor = 'bg-amber-400';
            else if (!isFuture && !isWeekend) dotColor = 'bg-muted-foreground/30';

            return (
              <button
                key={dateStr}
                onClick={() => setSelected(isSelected ? null : String(day))}
                className={`flex flex-col items-center py-1.5 rounded-xl transition-colors ${
                  isSelected
                    ? 'bg-primary text-primary-foreground'
                    : isToday
                    ? 'bg-primary/10 text-primary'
                    : isWeekend
                    ? 'text-muted-foreground/50'
                    : 'text-foreground hover:bg-muted'
                }`}
              >
                <span className={`text-[11px] font-semibold leading-none ${isToday && !isSelected ? 'font-extrabold' : ''}`}>
                  {day}
                </span>
                {dotColor && !isLoading && (
                  <span className={`w-1.5 h-1.5 rounded-full mt-1 ${isSelected ? 'bg-primary-foreground/70' : dotColor}`} />
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Legend */}
      <div className="flex items-center gap-4 px-1 flex-wrap">
        {[
          { color: 'bg-emerald-400', label: 'Hadir' },
          { color: 'bg-amber-400', label: 'Terlambat' },
          { color: 'bg-blue-400', label: 'Cuti' },
          { color: 'bg-muted-foreground/30', label: 'Tidak hadir' },
        ].map((l) => (
          <div key={l.label} className="flex items-center gap-1.5">
            <span className={`w-2 h-2 rounded-full ${l.color}`} />
            <span className="text-[10px] text-muted-foreground">{l.label}</span>
          </div>
        ))}
      </div>

      {/* Selected date detail */}
      {selectedDateStr && (
        <div className="bg-card border border-border rounded-2xl p-4 shadow-sm animate-in fade-in slide-in-from-bottom-2 duration-200">
          <p className="text-[10px] font-bold text-muted-foreground uppercase tracking-widest mb-3">
            {new Date(selectedDateStr).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
          </p>
          {selectedLeave ? (
            <div className="space-y-1">
              <p className="text-sm font-bold text-foreground">
                {selectedLeave.type === 'annual' ? 'Cuti Tahunan' : selectedLeave.type === 'sick' ? 'Cuti Sakit' : 'Izin'}
              </p>
              <p className={`text-[11px] font-semibold ${selectedLeave.status === 'approved' ? 'text-emerald-400' : 'text-amber-400'}`}>
                {selectedLeave.status === 'approved' ? 'Disetujui' : 'Menunggu Persetujuan'}
              </p>
            </div>
          ) : selectedAttendance ? (
            <div className="flex gap-6">
              <div>
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider">Masuk</p>
                <p className="font-mono text-sm font-bold">{selectedAttendance.check_in?.slice(0, 5) ?? '--:--'}</p>
              </div>
              <div>
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider">Pulang</p>
                <p className="font-mono text-sm font-bold">{selectedAttendance.check_out?.slice(0, 5) ?? '--:--'}</p>
              </div>
              <div>
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider">Status</p>
                <p className={`text-sm font-bold ${selectedAttendance.status === 'present' ? 'text-emerald-400' : 'text-amber-400'}`}>
                  {selectedAttendance.status === 'present' ? 'Hadir' : 'Terlambat'}
                </p>
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">Tidak ada data kehadiran</p>
          )}
        </div>
      )}
    </div>
  );
}
