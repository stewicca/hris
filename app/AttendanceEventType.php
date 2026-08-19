<?php

namespace App;

enum AttendanceEventType: string
{
    case CheckIn = 'check_in';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case CheckOut = 'check_out';
}
