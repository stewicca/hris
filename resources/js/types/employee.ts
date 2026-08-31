import type { User } from './auth';

export type Department = {
    id: number;
    name: string;
    employees_count?: number;
};

export type Position = {
    id: number;
    name: string;
    employees_count?: number;
};

export type Shift = {
    id: number;
    name: string;
    check_in: string;
    check_out: string;
    late_threshold: string;
    grace_minutes: number;
    break_enabled: boolean;
    break_start: string | null;
    break_end: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type Employee = {
    id: number;
    user_id: number | null;
    user?: User;
    employee_number: string;
    name: string;
    email: string;
    phone: string | null;
    bank_account_number: string | null;
    department_id: number | null;
    position_id: number | null;
    shift_id: number | null;
    department: Department | null;
    position: Position | null;
    shift: Shift | null;
    hire_date: string | null;
    status: 'active' | 'inactive';
    annual_leave_quota: number;
    face_embedding?: number[] | null;
    face_photo_path?: string | null;
    face_enrolled_at?: string | null;
    created_at: string;
    updated_at: string;
};

export type LeaveSummary = {
    quota: number;
    used: number;
    pending: number;
    remaining: number;
};

/** A status stored on an attendance record. */
export type AttendanceStatus =
    | 'present'
    | 'late'
    | 'absent'
    | 'sick'
    | 'permit';

/**
 * A row on the daily attendance board. `leave` and `holiday` are derived for
 * employees with no record at all, so they appear here but are never stored.
 */
export type AttendanceRecord = {
    employee_id: number;
    employee_number: string;
    name: string;
    department: string | null;
    position: string | null;
    attendance_id: number | null;
    check_in: string | null;
    check_out: string | null;
    break_start: string | null;
    break_end: string | null;
    notes: string | null;
    recorded_manually: boolean;
    can_delete: boolean;
    status: AttendanceStatus | 'leave' | 'holiday';
};

export type Attendance = {
    id: number;
    employee_id: number;
    date: string;
    check_in: string | null;
    check_out: string | null;
    break_start: string | null;
    break_end: string | null;
    check_in_lat: number | null;
    check_in_lng: number | null;
    check_in_accuracy: number | null;
    check_out_lat: number | null;
    check_out_lng: number | null;
    check_out_accuracy: number | null;
    shift_id: number | null;
    shift?: Shift | null;
    status: AttendanceStatus;
    notes: string | null;
    recorded_by: number | null;
    created_at: string;
    updated_at: string;
};

export type AttendanceSummary = {
    present: number;
    late: number;
    absent: number;
    /** Days marked sick or excused, which are absences nobody is faulted for. */
    excused: number;
    leave: number;
    total: number;
    is_working_day: boolean;
};

export type MonthlyAttendance = {
    year: number;
    month: number;
    present: number;
    late: number;
    absent: number;
    excused: number;
    /** Rupiah this month's attendance cost under the deduction rules. */
    deduction: number;
    total: number;
};

export type PaginatedAttendances = {
    data: Attendance[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type Leave = {
    id: number;
    employee_id: number;
    employee?: Employee;
    approved_by: number | null;
    type: 'annual' | 'sick' | 'permit';
    start_date: string;
    end_date: string;
    days: number;
    reason: string;
    status: 'pending' | 'approved' | 'rejected';
    approved_at: string | null;
    rejection_reason: string | null;
    created_at: string;
    updated_at: string;
};

export type SalaryComponent = {
    label: string;
    amount: number;
    type: 'income' | 'deduction';
};

export type Salary = {
    id: number;
    employee_id: number;
    period: string;
    period_label: string;
    components: SalaryComponent[];
    status: 'pending' | 'paid';
    paid_at: string | null;
    gross: number;
    deductions: number;
    net: number;
    created_at: string;
    updated_at: string;
};

export type PaginatedLeaves = {
    data: Leave[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type PaginatedEmployees = {
    data: Employee[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
