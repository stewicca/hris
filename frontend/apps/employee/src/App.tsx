import { api } from '@hris/shared';
import {
  Clock,
  Calendar as CalendarIcon,
  User,
  Bell,
  Wifi,
  WifiOff,
  Loader2,
  Sun,
  Moon,
  Monitor,
  CalendarDays,
} from 'lucide-react';
import React, { useState, useEffect } from 'react';
import Login from './components/Login';
import { useAppearance } from './hooks/use-appearance';
import CalendarPage from './pages/CalendarPage';
import Dashboard from './pages/Dashboard';
import LeavePage from './pages/LeavePage';
import NotifikasiPage from './pages/NotifikasiPage';
import Profile from './pages/Profile';
import ReportPage from './pages/ReportPage';
import SalaryPage from './pages/SalaryPage';

type Tab = 'home' | 'calendar' | 'cuti' | 'profile' | 'gaji' | 'laporan' | 'notifications';

export default function App() {
  const [apiStatus, setApiStatus] = useState<'checking' | 'connected' | 'mock'>('checking');
  const [activeTab, setActiveTab] = useState<Tab>('home');
  const [unreadCount, setUnreadCount] = useState(0);

  const { appearance, updateAppearance } = useAppearance();

  const cycleAppearance = () => {
    if (appearance === 'system') updateAppearance('light');
    else if (appearance === 'light') updateAppearance('dark');
    else updateAppearance('system');
  };

  const [user, setUser] = useState<any>(null);
  const [employee, setEmployee] = useState<any>(null);
  const [leaveEnabled, setLeaveEnabled] = useState(true);
  const [breakEnabled, setBreakEnabled] = useState(false);
  const [shiftEnabled, setShiftEnabled] = useState(false);
  const [payrollEnabled, setPayrollEnabled] = useState(true);
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const initApp = async () => {
      try {
        let connectionStatus: 'connected' | 'mock' = 'mock';
        try {
          await api.get('/status');
          setApiStatus('connected');
          connectionStatus = 'connected';
        } catch {
          setApiStatus('mock');
        }

        if (connectionStatus === 'connected') {
          try {
            const data = await api.get('/me');
            if (data?.user) {
              setUser(data.user);
              setEmployee(data.employee);
              setIsAuthenticated(true);
              try {
                const settings = await api.get('/attendance/settings');
                if (settings && typeof settings.leave_enabled === 'boolean') {
                  setLeaveEnabled(settings.leave_enabled);
                }
                if (settings && typeof settings.break_enabled === 'boolean') {
                  setBreakEnabled(settings.break_enabled);
                }
                if (settings && typeof settings.shift_enabled === 'boolean') {
                  setShiftEnabled(settings.shift_enabled);
                }
                if (settings && typeof settings.payroll_enabled === 'boolean') {
                  setPayrollEnabled(settings.payroll_enabled);
                }
              } catch {
                // Feature flags default (leave enabled, break/shift off) when settings are unavailable.
              }
              try {
                const notif = await api.get('/notifications');
                setUnreadCount(notif.unread ?? 0);
              } catch {
                // Ignore notification fetch failures
              }
            }
          } catch {
            // Not authenticated
          }
        }
      } finally {
        setIsLoading(false);
      }
    };
    initApp();
  }, []);

  const handleLoginSuccess = (loggedInUser: any, loggedInEmployee: any, isMock: boolean) => {
    setUser(loggedInUser);
    setEmployee(loggedInEmployee);
    setIsAuthenticated(true);
    if (isMock) {
      setApiStatus('mock');
    } else {
      api.get('/notifications').then((d) => setUnreadCount(d.unread ?? 0)).catch(() => {});
    }
  };

  const handleLogout = async () => {
    setIsLoading(true);
    try {
      if (apiStatus === 'connected') await api.post('/logout');
    } catch (err) {
      console.warn('Logout failed, clearing local state.', err);
    } finally {
      setUser(null);
      setEmployee(null);
      setIsAuthenticated(false);
      setIsLoading(false);
      setActiveTab('home');
      setUnreadCount(0);
    }
  };

  const handleBellClick = () => {
    setActiveTab('notifications');
  };

  const isMock = apiStatus === 'mock';

  if (isLoading) {
    return (
      <div className="min-h-screen bg-background text-foreground flex flex-col items-center justify-center max-w-md mx-auto relative border-x border-border shadow-2xl">
        <div className="absolute -left-20 top-20 w-64 h-64 bg-primary/5 rounded-full blur-3xl animate-pulse" />
        <div className="absolute -right-20 bottom-20 w-64 h-64 bg-primary/5 rounded-full blur-3xl animate-pulse" />
        <div className="w-12 h-12 rounded-xl bg-primary text-primary-foreground flex items-center justify-center font-bold text-lg shadow-md mb-4 animate-bounce">
          H
        </div>
        <div className="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase tracking-widest">
          <Loader2 size={14} className="animate-spin text-primary" />
          <span>Memuat Aplikasi...</span>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Login apiStatus={apiStatus} onLoginSuccess={handleLoginSuccess} />;
  }

  const bottomNavTabs = [
    { id: 'home' as Tab, label: 'Beranda', icon: Clock },
    { id: 'calendar' as Tab, label: 'Kalender', icon: CalendarIcon },
    { id: 'cuti' as Tab, label: 'Cuti', icon: CalendarDays },
    { id: 'profile' as Tab, label: 'Profil', icon: User },
  ].filter((tab) => tab.id !== 'cuti' || leaveEnabled);

  const pageTitle: Record<Tab, string> = {
    home: 'Beranda',
    calendar: 'Kalender',
    cuti: 'Cuti & Izin',
    profile: 'Profil',
    gaji: 'Slip Gaji',
    laporan: 'Laporan',
    notifications: 'Notifikasi',
  };

  const isSubPage = ['gaji', 'laporan', 'notifications'].includes(activeTab);

  return (
    <div className="min-h-screen bg-background text-foreground flex flex-col justify-between max-w-md mx-auto relative border-x border-border shadow-2xl">
      {/* Header */}
      <header className="sticky top-0 bg-background/80 backdrop-blur-md border-b border-border px-5 py-4 flex items-center justify-between z-40">
        <div className="flex items-center gap-3">
          {isSubPage ? (
            <button
              onClick={() => setActiveTab('home')}
              className="w-9 h-9 rounded-xl bg-secondary border border-border flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
              aria-label="Kembali"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                <path d="M15 18l-6-6 6-6" />
              </svg>
            </button>
          ) : (
            <div className="w-9 h-9 rounded-xl bg-primary text-primary-foreground flex items-center justify-center font-bold text-lg shadow-sm">
              H
            </div>
          )}
          <div>
            <h1 className="text-base font-bold tracking-tight text-foreground">
              {isSubPage ? pageTitle[activeTab] : 'HRIS WPA'}
            </h1>
            {!isSubPage && (
              <p className="text-[10px] text-muted-foreground font-semibold tracking-wider uppercase">Portal Karyawan</p>
            )}
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* API status */}
          <div className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[9px] font-bold uppercase transition-all ${
            apiStatus === 'connected'
              ? 'bg-green-500/10 text-green-600 border-green-500/25 dark:text-green-400'
              : 'bg-amber-500/10 text-amber-600 border-amber-500/25 dark:text-amber-400'
          }`}>
            {apiStatus === 'connected' ? <Wifi size={10} /> : <WifiOff size={10} />}
            {apiStatus === 'connected' ? 'Live' : 'Demo'}
          </div>

          {/* Appearance */}
          <button
            onClick={cycleAppearance}
            className="w-8 h-8 rounded-lg bg-secondary border border-border flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
          >
            {appearance === 'light' ? <Sun size={14} /> : appearance === 'dark' ? <Moon size={14} /> : <Monitor size={14} />}
          </button>

          {/* Bell */}
          <button
            onClick={handleBellClick}
            className="relative w-8 h-8 rounded-lg bg-secondary border border-border flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
          >
            <Bell size={14} />
            {unreadCount > 0 && (
              <span className="absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full bg-primary" />
            )}
          </button>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 px-5 py-6 overflow-y-auto pb-24">
        {activeTab === 'home' && (
          <Dashboard
            user={user}
            employee={employee}
            leaveEnabled={leaveEnabled}
            breakEnabled={breakEnabled}
            shiftEnabled={shiftEnabled}
            payrollEnabled={payrollEnabled}
            onNavigate={setActiveTab}
          />
        )}
        {activeTab === 'calendar' && <CalendarPage isMock={isMock} />}
        {activeTab === 'cuti' && leaveEnabled && <LeavePage isMock={isMock} />}
        {activeTab === 'profile' && (
          <Profile user={user} employee={employee} isMock={isMock} onLogout={handleLogout} onProfileUpdate={setEmployee} />
        )}
        {activeTab === 'gaji' && payrollEnabled && <SalaryPage isMock={isMock} />}
        {activeTab === 'laporan' && <ReportPage isMock={isMock} />}
        {activeTab === 'notifications' && <NotifikasiPage isMock={isMock} onUnreadChange={setUnreadCount} />}
      </main>

      {/* Bottom Nav */}
      <nav className="absolute bottom-0 left-0 right-0 bg-background/95 backdrop-blur-md border-t border-border px-6 py-3 flex justify-between z-40">
        {bottomNavTabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`flex flex-col items-center gap-1 transition-colors ${
              activeTab === tab.id || (isSubPage && tab.id === 'home' && activeTab !== 'notifications')
                ? 'text-primary'
                : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            <tab.icon size={16} />
            <span className="text-[9px] font-bold tracking-wider uppercase">{tab.label}</span>
          </button>
        ))}
      </nav>
    </div>
  );
}
