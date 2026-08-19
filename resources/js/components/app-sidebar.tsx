import { Link, usePage } from '@inertiajs/react';
import {
    CalendarCog,
    CalendarDays,
    ClipboardList,
    Clock,
    Database,
    LayoutGrid,
    Settings2,
    SlidersHorizontal,
    Users,
} from 'lucide-react';
import { index as attendanceSettingsIndex } from '@/actions/App/Http/Controllers/AttendanceSettingController';
import { index as featureSettingsIndex } from '@/actions/App/Http/Controllers/FeatureSettingController';
import { index as leavesIndex } from '@/actions/App/Http/Controllers/Leave/LeaveController';
import { index as masterDataIndex } from '@/actions/App/Http/Controllers/MasterData/MasterDataController';
import { index as shiftIndex } from '@/actions/App/Http/Controllers/Shifts/ShiftController';
import { index as workCalendarIndex } from '@/actions/App/Http/Controllers/WorkCalendarController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as attendanceIndex } from '@/routes/attendance';
import { index as employeesIndex } from '@/routes/employees';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard.url(),
        icon: LayoutGrid,
    },
    {
        title: 'Karyawan',
        href: employeesIndex.url(),
        icon: Users,
    },
    {
        title: 'Kehadiran',
        href: attendanceIndex.url(),
        icon: ClipboardList,
    },
    {
        title: 'Cuti',
        href: leavesIndex.url(),
        icon: CalendarDays,
    },
    {
        title: 'Master Data',
        href: masterDataIndex.url(),
        icon: Database,
    },
    {
        title: 'Shift',
        href: shiftIndex.url(),
        icon: Clock,
    },
    {
        title: 'Kalender Kerja',
        href: workCalendarIndex.url(),
        icon: CalendarCog,
    },
    {
        title: 'Pengaturan Absensi',
        href: attendanceSettingsIndex.url(),
        icon: Settings2,
    },
    {
        title: 'Pengaturan Fitur',
        href: featureSettingsIndex.url(),
        icon: SlidersHorizontal,
    },
];

export function AppSidebar() {
    const { features } = usePage().props;

    const items = mainNavItems.filter(
        (item) =>
            (item.title !== 'Cuti' || features?.leave !== false) &&
            (item.title !== 'Shift' || features?.shift !== false),
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            render={<Link href={dashboard.url()} prefetch />}
                        >
                            <AppLogo />
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
