export * from './api.js';
export * from './face.js';

// Shared utility helpers
export const formatCurrency = (amount: number, locale = 'id-ID', currency = 'IDR'): string => {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0
  }).format(amount);
};

export const formatDate = (date: string | Date, locale = 'id-ID'): string => {
  const d = typeof date === 'string' ? new Date(date) : date;
  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'long',
    timeZone: 'Asia/Makassar',
  }).format(d);
};

export const getAppVersion = (): string => '1.0.0-beta';
export const getAppName = (): string => 'HRIS Modern Suite';
export const getDeveloperName = (): string => 'Antigravity Developer Team';
export const getFooterMessage = (): string => `© ${new Date().getFullYear()} ${getAppName()} | Built with Google DeepMind Antigravity`;
export const getStatusColor = (status: string): string => {
  switch (status.toLowerCase()) {
    case 'active':
    case 'present':
    case 'approved':
      return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/25';
    case 'pending':
    case 'late':
      return 'bg-amber-500/10 text-amber-400 border-amber-500/25';
    case 'inactive':
    case 'absent':
    case 'rejected':
      return 'bg-rose-500/10 text-rose-400 border-rose-500/25';
    default:
      return 'bg-slate-500/10 text-slate-400 border-slate-500/25';
  }
};
export const getGreeting = (): string => {
  const hrs = new Date().getHours();
  if (hrs < 12) return 'Selamat Pagi';
  if (hrs < 15) return 'Selamat Siang';
  if (hrs < 18) return 'Selamat Sore';
  return 'Selamat Malam';
};
