<?php

namespace App\Modules\Identity\Access;

enum Permission: string
{
    case ClockIn = 'attendance.clock_in';

    // Management (Team Lead, Project Manager, Project Director)
    case ApproveOvertime = 'overtime.approve';
    case ApproveAnyOvertime = 'overtime.approve_any';
    case ViewTeamBoard = 'team_board.view';
    case OpenWorkdays = 'calendar.open_workdays';
    case ProposeCorrections = 'corrections.propose';
    case ViewTeamReports = 'reports.view_team';

    // Project Director and Superadmin
    case ChangeOvertimeDecisions = 'overtime.change_decisions';

    // Superadmin
    case ManageUsers = 'users.manage';
    case ManageTeams = 'teams.manage';
    case ManageCalendar = 'calendar.manage';
    case ManageSettings = 'settings.manage';
    case ManageDevices = 'devices.manage';
    case ApplyCorrections = 'corrections.apply';
    case ViewAllReports = 'reports.view_all';
    case ExportReports = 'reports.export';
    case ViewAuditLog = 'audit.view';
}
