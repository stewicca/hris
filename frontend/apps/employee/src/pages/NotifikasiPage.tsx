import { api } from '@hris/shared';
import { Bell, CheckCircle2, Wallet, Info } from 'lucide-react';
import React, { useState, useEffect, useCallback } from 'react';

interface Notification {
  id: string;
  type: 'leave' | 'salary' | 'info';
  title: string;
  body: string;
  read: boolean;
  created_at: string;
}

const ICONS: Record<Notification['type'], React.ReactNode> = {
  leave: <CheckCircle2 size={15} className="text-emerald-400" />,
  salary: <Wallet size={15} className="text-primary" />,
  info: <Info size={15} className="text-blue-400" />,
};

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  if (days > 0) return `${days} hari lalu`;
  if (hours > 0) return `${hours} jam lalu`;
  if (minutes > 0) return `${minutes} menit lalu`;
  return 'Baru saja';
}

const MOCK_NOTIFICATIONS: Notification[] = [
  {
    id: 'mock-1',
    type: 'leave',
    title: 'Cuti Disetujui',
    body: 'Pengajuan cuti 3 hari (20 Mei 2026 - 22 Mei 2026) telah disetujui.',
    read: false,
    created_at: new Date(Date.now() - 86400000).toISOString(),
  },
  {
    id: 'mock-2',
    type: 'salary',
    title: 'Slip Gaji Terbit',
    body: 'Slip gaji periode Mei 2026 sudah tersedia.',
    read: true,
    created_at: new Date(Date.now() - 86400000 * 3).toISOString(),
  },
];

interface NotifikasiPageProps {
  isMock: boolean;
  onUnreadChange?: (count: number) => void;
}

export default function NotifikasiPage({ isMock, onUnreadChange }: NotifikasiPageProps) {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const fetchData = useCallback(async () => {
    setIsLoading(true);
    try {
      if (isMock) {
        await new Promise((r) => setTimeout(r, 400));
        setNotifications(MOCK_NOTIFICATIONS);
      } else {
        const data = await api.get<{ notifications: Notification[] }>('/notifications');
        setNotifications(data.notifications);
      }
    } catch {
      setNotifications([]);
    } finally {
      setIsLoading(false);
    }
  }, [isMock]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const unreadCount = notifications.filter((n) => !n.read).length;

  useEffect(() => { onUnreadChange?.(unreadCount); }, [unreadCount, onUnreadChange]);

  const markRead = (id: string) => {
    setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, read: true } : n)));
    if (!isMock) api.post(`/notifications/${id}/read`).catch(() => {});
  };

  const markAllRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
    if (!isMock) api.post('/notifications/read-all').catch(() => {});
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-300">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Bell size={18} className="text-primary" />
          <div>
            <h2 className="text-lg font-bold tracking-tight text-foreground">Notifikasi</h2>
            <p className="text-[11px] text-muted-foreground">
              {unreadCount > 0 ? `${unreadCount} notifikasi belum dibaca` : 'Semua sudah dibaca'}
            </p>
          </div>
        </div>
        {unreadCount > 0 && (
          <button
            onClick={markAllRead}
            className="text-[10px] font-bold text-primary hover:underline"
          >
            Tandai semua
          </button>
        )}
      </div>

      {/* Notification list */}
      {isLoading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-16 rounded-2xl bg-muted animate-pulse border border-border" />
          ))}
        </div>
      ) : notifications.length === 0 ? (
        <div className="text-center py-12 text-muted-foreground">
          <Bell size={32} className="mx-auto mb-3 opacity-30" />
          <p className="text-sm font-semibold">Belum ada notifikasi</p>
        </div>
      ) : (
        <div className="space-y-2.5">
          {notifications.map((n) => (
            <button
              key={n.id}
              onClick={() => markRead(n.id)}
              className={`w-full text-left flex items-start gap-3 p-4 rounded-2xl border transition-colors ${
                n.read
                  ? 'bg-card border-border'
                  : 'bg-primary/5 border-primary/20 hover:bg-primary/10'
              }`}
            >
              <div className="w-8 h-8 rounded-xl bg-secondary border border-border flex items-center justify-center shrink-0 mt-0.5">
                {ICONS[n.type] ?? ICONS.info}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-2">
                  <p className={`text-xs font-bold ${n.read ? 'text-foreground' : 'text-primary'}`}>
                    {n.title}
                  </p>
                  <span className="text-[9px] text-muted-foreground shrink-0">{timeAgo(n.created_at)}</span>
                </div>
                <p className="text-[11px] text-muted-foreground mt-0.5 leading-relaxed line-clamp-2">
                  {n.body}
                </p>
              </div>
              {!n.read && (
                <span className="w-1.5 h-1.5 rounded-full bg-primary mt-2 shrink-0" />
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
