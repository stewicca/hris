import { Button } from '@/components/ui/button';
import { getGreeting, getStatusColor, formatDate } from '@hris/shared';
import { api, ApiError } from '@hris/shared';
import {
  Clock,
  Calendar as CalendarIcon,
  User,
  MapPin,
  ChevronRight,
  FileText,
  Coffee,
  ShieldCheck,
  TrendingUp,
  CheckCircle2,
  Loader2,
  AlertCircle,
  Navigation,
  ScanFace,
} from 'lucide-react';
import React, { useState, useEffect, useCallback, useRef } from 'react';
import { FaceCapture } from '@/components/face-capture';

interface Attendance {
  id: number;
  date: string;
  check_in: string | null;
  check_out: string | null;
  break_start: string | null;
  break_end: string | null;
  status: 'present' | 'late' | 'absent';
}

interface OfficeHours {
  check_in: string;
  check_out: string;
  late_threshold: string;
}

interface Shift {
  id: number;
  name: string;
  check_in: string;
  check_out: string;
  late_threshold: string;
  break_enabled: boolean;
  break_start: string | null;
  break_end: string | null;
}

interface AttendanceSettings {
  office_hours: OfficeHours;
  geofence_enabled: boolean;
  radius_meters: number;
  shift?: Shift | null;
}

interface DashboardProps {
  user: any;
  employee: any;
  leaveEnabled?: boolean;
  breakEnabled?: boolean;
  shiftEnabled?: boolean;
  payrollEnabled?: boolean;
  onNavigate: (tab: string) => void;
}

type GeoState = 'idle' | 'requesting' | 'denied' | 'unavailable';

interface GpsPosition {
  coords: GeolocationCoordinates;
  timestamp: number;
}

// Next attendance action, mirrors the server state machine.
type NextAction = 'check_in' | 'break_start' | 'break_end' | 'check_out' | 'done';

function useGeolocation() {
  const [geoState, setGeoState] = useState<GeoState>('idle');

  const getPosition = useCallback((): Promise<GpsPosition> => {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        setGeoState('unavailable');
        reject(new Error('Perangkat tidak mendukung GPS.'));
        return;
      }
      setGeoState('requesting');
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          setGeoState('idle');
          resolve({ coords: pos.coords, timestamp: pos.timestamp });
        },
        (err) => {
          if (err.code === err.PERMISSION_DENIED) {
            setGeoState('denied');
            reject(new Error('Izin lokasi ditolak. Aktifkan GPS di pengaturan browser.'));
          } else {
            setGeoState('unavailable');
            reject(new Error('Gagal mendapatkan lokasi. Coba lagi.'));
          }
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }, []);

  return { geoState, getPosition };
}

export default function Dashboard({
  user,
  employee,
  leaveEnabled = true,
  breakEnabled = false,
  shiftEnabled = false,
  payrollEnabled = true,
  onNavigate,
}: DashboardProps) {
  const [time, setTime] = useState(new Date());
  const [todayAttendance, setTodayAttendance] = useState<Attendance | null>(null);
  const [history, setHistory] = useState<Attendance[]>([]);
  const [officeHours, setOfficeHours] = useState<OfficeHours | null>(null);
  const [activeShift, setActiveShift] = useState<Shift | null>(null);
  const [isLoadingAttendance, setIsLoadingAttendance] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { geoState, getPosition } = useGeolocation();

  useEffect(() => {
    const timer = setInterval(() => setTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  const fetchAttendanceData = useCallback(async () => {
    setIsLoadingAttendance(true);
    try {
      const [todayRes, historyRes] = await Promise.all([
        api.get<{ attendance: Attendance | null }>('/attendance/today'),
        api.get<{ history: Attendance[] }>('/attendance/history'),
      ]);
      setTodayAttendance(todayRes.attendance);
      setHistory(historyRes.history);
    } catch {
      // non-critical, keep UI usable
    } finally {
      setIsLoadingAttendance(false);
    }
  }, []);

  useEffect(() => {
    fetchAttendanceData();
    // Office hours / shift are configured by admin; fetch once to display the label.
    api
      .get<AttendanceSettings>('/attendance/settings')
      .then((s) => {
        setOfficeHours(s.office_hours);
        setActiveShift(s.shift ?? null);
      })
      .catch(() => { /* keep default label */ });
  }, [fetchAttendanceData]);

  // Derive the next action from the current timeline state.
  // Mirrors Attendance::nextExpectedAction() on the server.
  const nextAction: NextAction = (() => {
    const a = todayAttendance;
    if (!a) return 'check_in';
    if (a.check_out) return 'done';
    if (!a.check_in) return 'check_in';
    // Break only applies when the feature (and, if shift-mode, the shift) enables it.
    const breakActive = shiftEnabled ? activeShift?.break_enabled === true : breakEnabled;
    if (breakActive) {
      if (!a.break_start) return 'break_start';
      if (!a.break_end) return 'break_end';
    }
    return 'check_out';
  })();

  const [faceCaptureOpen, setFaceCaptureOpen] = useState(false);
  // GPS payload + the action being submitted, gathered before opening the face overlay.
  const pendingPositionRef = useRef<GpsPosition | null>(null);
  const pendingActionRef = useRef<NextAction>('check_in');

  const handleAttendance = async () => {
    if (nextAction === 'done') return;
    setError(null);
    setIsSubmitting(true);
    try {
      const position = await getPosition();
      pendingPositionRef.current = position;
      pendingActionRef.current = nextAction;
      setFaceCaptureOpen(true);
    } catch (err) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setIsSubmitting(false);
    }
  };

  // Called by the FaceCapture overlay once liveness has been confirmed.
  const submitAttendanceWithFace = async (facePhoto: Blob) => {
    const position = pendingPositionRef.current;
    if (!position) {
      setError('Data lokasi hilang. Coba lagi.');
      setFaceCaptureOpen(false);
      return;
    }
    setIsSubmitting(true);
    const form = new FormData();
    form.append('type', pendingActionRef.current);
    form.append('latitude', String(position.coords.latitude));
    form.append('longitude', String(position.coords.longitude));
    form.append('accuracy', String(position.coords.accuracy));
    form.append('gps_timestamp', String(position.timestamp));
    form.append('image', facePhoto, 'face.jpg');

    try {
      await api.postForm('/attendance/event', form);
      await fetchAttendanceData();
      setFaceCaptureOpen(false);
    } catch (err) {
      if (err instanceof ApiError) {
        const messages = err.data?.errors
          ? Object.values(err.data.errors).flat().join(' ')
          : err.message;
        setError(messages as string);
      } else if (err instanceof Error) {
        setError(err.message);
      }
      // Surface the error on the dashboard, not inside the overlay.
      setFaceCaptureOpen(false);
    } finally {
      setIsSubmitting(false);
    }
  };

  const formatClockTime = (date: Date) =>
    date.toLocaleTimeString('id-ID', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
      timeZone: 'Asia/Makassar',
    });

  const formatTime = (time: string | null) => {
    if (!time) return '--:--';
    // time is stored as WITA (Asia/Makassar, UTC+8) on the server
    return time.slice(0, 5);
  };

  const formatHistoryDate = (dateStr: string) => {
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  };

  const getAttendanceStatusLabel = (status: Attendance['status']) => {
    return status === 'present' ? 'Hadir' : status === 'late' ? 'Terlambat' : 'Absen';
  };

  const getAttendanceStatusColor = (status: Attendance['status']) => {
    return getStatusColor(status === 'present' ? 'approved' : 'pending');
  };

  const buttonDisabled = isSubmitting || geoState === 'requesting' || faceCaptureOpen || nextAction === 'done';

  const buttonConfig: Record<NextAction, { label: string; variant: 'default' | 'destructive' | 'outline' | 'secondary' }> = {
    check_in: { label: 'Check In Kehadiran', variant: 'default' },
    break_start: { label: 'Mulai Istirahat', variant: 'outline' },
    break_end: { label: 'Akhiri Istirahat', variant: 'outline' },
    check_out: { label: 'Check Out Sekarang', variant: 'destructive' },
    done: { label: 'Sudah Check Out', variant: 'outline' },
  };

  // Label the working hours: the employee's shift (when shift-mode) or global office hours.
  const workHoursLabel = (() => {
    if (shiftEnabled && activeShift) {
      return `Shift ${activeShift.name} (${activeShift.check_in.slice(0, 5)} - ${activeShift.check_out.slice(0, 5)})`;
    }
    if (officeHours) {
      return `Jam Kerja (${officeHours.check_in} - ${officeHours.check_out})`;
    }
    return 'Jam Kerja';
  })();

  return (
    <div className="space-y-6 animate-in fade-in duration-300">
      {/* Welcome Section */}
      <div className="space-y-1">
        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{getGreeting()}, Rekan</p>
        <h2 className="text-2xl font-bold tracking-tight text-foreground">{user?.name}</h2>
        <div className="flex items-center gap-1.5 text-xs text-primary font-medium">
          <ShieldCheck size={14} className="text-primary" />
          <span>{employee?.position || 'Karyawan'} • {employee?.department || 'Divisi'}</span>
        </div>
      </div>

      {/* Live Attendance Card */}
      <div className="bg-card border border-border rounded-2xl p-6 relative overflow-hidden shadow-sm group">
        <div className="absolute -right-16 -top-16 w-32 h-32 bg-primary/5 rounded-full blur-2xl group-hover:bg-primary/10 transition-all duration-700"></div>

        <div className="flex justify-between items-start mb-4">
          <div>
            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Jam Kerja Anda</p>
            <h3 className="text-sm font-bold text-foreground mt-0.5">{workHoursLabel}</h3>
          </div>
          <div className="flex items-center gap-1 bg-secondary border border-border px-2.5 py-1 rounded-lg text-muted-foreground text-xs font-medium">
            <CalendarIcon size={12} />
            <span>{formatDate(new Date())}</span>
          </div>
        </div>

        {/* Ticking Clock */}
        <div className="my-6 text-center">
          <span className="font-mono text-4xl font-extrabold tracking-widest text-foreground">
            {formatClockTime(time)}
          </span>

          {/* GPS Status */}
          <div className="flex items-center justify-center gap-1 text-[11px] text-muted-foreground mt-2 font-medium">
            {geoState === 'requesting' ? (
              <>
                <Loader2 size={12} className="animate-spin text-primary" />
                <span>Mendapatkan lokasi GPS...</span>
              </>
            ) : geoState === 'denied' || geoState === 'unavailable' ? (
              <>
                <AlertCircle size={12} className="text-destructive" />
                <span className="text-destructive">GPS tidak tersedia</span>
              </>
            ) : (
              <>
                <Navigation size={12} className="text-primary" />
                <span>GPS siap</span>
              </>
            )}
          </div>

          {/* Today timeline summary */}
          {!isLoadingAttendance && todayAttendance && (
            <div className="flex justify-center gap-4 mt-3 flex-wrap">
              <div className="text-center">
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider">Masuk</p>
                <p className="font-mono text-sm font-bold text-foreground">{formatTime(todayAttendance.check_in)}</p>
              </div>
              <div className="w-px bg-border"></div>
              <div className="text-center">
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider">Pulang</p>
                <p className="font-mono text-sm font-bold text-foreground">{formatTime(todayAttendance.check_out)}</p>
              </div>
            </div>
          )}
        </div>

        {/* Error message */}
        {error && (
          <div className="flex items-start gap-2 mb-3 p-3 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs">
            <AlertCircle size={14} className="shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {/* GPS info hint */}
        {geoState === 'idle' && !error && (
          <div className="flex items-center gap-1.5 mb-3 text-[11px] text-muted-foreground">
            <MapPin size={11} className="text-primary" />
            <span>Lokasi GPS Anda akan dicatat saat absen</span>
          </div>
        )}

        <Button
          onClick={handleAttendance}
          disabled={buttonDisabled}
          variant={buttonConfig[nextAction].variant}
          className="w-full h-11 rounded-xl font-bold text-sm tracking-wide shadow-sm active:scale-[0.98] transition-all"
        >
          {isSubmitting || geoState === 'requesting' || faceCaptureOpen ? (
            <Loader2 size={16} className="mr-2 animate-spin" />
          ) : nextAction === 'break_start' || nextAction === 'break_end' ? (
            <Coffee size={16} className="mr-2" />
          ) : (
            <Clock size={16} className="mr-2" />
          )}
          {faceCaptureOpen
            ? 'Verifikasi Wajah...'
            : isSubmitting
              ? 'Memproses...'
              : geoState === 'requesting'
                ? 'Mengambil GPS...'
                : buttonConfig[nextAction].label}
        </Button>

        {/* Face verification hint */}
        {geoState === 'idle' && !error && !faceCaptureOpen && (
          <div className="flex items-center gap-1.5 mt-2 text-[11px] text-muted-foreground">
            <ScanFace size={11} className="text-primary" />
            <span>Verifikasi wajah otomatis saat absen</span>
          </div>
        )}
      </div>

      {/* Quick Menu Grid */}
      <div className="space-y-3">
        <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-widest pl-1">Menu Layanan</h3>
        <div className="grid grid-cols-4 gap-3">
          {[
            { label: 'Cuti', icon: Coffee, color: 'text-amber-500 bg-amber-500/10 border-amber-500/20', tab: 'cuti' },
            { label: 'Gaji', icon: FileText, color: 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20', tab: 'gaji' },
            { label: 'Laporan', icon: TrendingUp, color: 'text-purple-500 bg-purple-500/10 border-purple-500/20', tab: 'laporan' },
            { label: 'Profil', icon: User, color: 'text-primary bg-primary/10 border-primary/20', tab: 'profile' },
          ].filter((item) => item.tab !== 'cuti' || leaveEnabled).filter((item) => item.tab !== 'gaji' || payrollEnabled).map((item, idx) => (
            <button
              key={idx}
              onClick={() => onNavigate(item.tab)}
              className="flex flex-col items-center justify-center p-3 rounded-2xl bg-card border border-border hover:bg-muted/50 transition-colors"
            >
              <div className={`w-10 h-10 rounded-xl flex items-center justify-center border ${item.color} mb-2 shadow-inner`}>
                <item.icon size={18} />
              </div>
              <span className="text-[11px] font-semibold text-foreground">{item.label}</span>
            </button>
          ))}
        </div>
      </div>

      {/* Attendance History */}
      <div className="space-y-3">
        <div className="flex justify-between items-center pl-1">
          <h3 className="text-xs font-bold text-muted-foreground uppercase tracking-widest">Kehadiran Terakhir</h3>
          <button className="text-xs font-bold text-primary hover:underline flex items-center gap-0.5">
            <span>Semua</span>
            <ChevronRight size={12} />
          </button>
        </div>

        {isLoadingAttendance ? (
          <div className="space-y-2.5">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-16 rounded-xl bg-muted animate-pulse border border-border" />
            ))}
          </div>
        ) : history.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground text-xs">
            Belum ada riwayat kehadiran.
          </div>
        ) : (
          <div className="space-y-2.5">
            {history.slice(0, 5).map((log) => (
              <div
                key={log.id}
                className="flex items-center justify-between p-4 rounded-xl bg-card border border-border hover:bg-muted/30 transition-colors"
              >
                <div className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-lg bg-secondary border border-border flex items-center justify-center">
                    <CheckCircle2
                      size={16}
                      className={log.status === 'present' ? 'text-green-500' : 'text-amber-500'}
                    />
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-foreground">{formatHistoryDate(log.date)}</h4>
                    <p className="text-[10px] text-muted-foreground mt-0.5">
                      Masuk: <span className="font-semibold font-mono">{formatTime(log.check_in)}</span>
                      {' • '}
                      Pulang: <span className="font-semibold font-mono">{formatTime(log.check_out)}</span>
                    </p>
                  </div>
                </div>
                <div className={`px-2.5 py-0.5 rounded-full border text-[9px] font-bold ${getAttendanceStatusColor(log.status)}`}>
                  {getAttendanceStatusLabel(log.status)}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Face verification overlay (liveness + capture) */}
      <FaceCapture
        open={faceCaptureOpen}
        onClose={() => setFaceCaptureOpen(false)}
        onCapture={submitAttendanceWithFace}
      />
    </div>
  );
}
