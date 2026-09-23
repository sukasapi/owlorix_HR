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

    // Projects module
    case ViewProjects = 'projects.view';
    case ManageProjects = 'projects.manage';
    case LogActivity = 'activity.log';
    // Decide proposals and review evidence on every sub project, not only the ones a person leads
    case OverseeProjects = 'projects.oversee';
    // Set and read hour budgets of projects and sub projects (docs/14)
    case ManageBudgets = 'projects.budget';
    // Studio production pipeline: the stages tasks move through (docs/14)
    case ManagePipeline = 'pipeline.manage';

    // Leave (docs/14): everyone who clocks in asks, a Team Lead decides for their team, PM and PD for anyone
    case RequestLeave = 'leave.request';
    case ApproveLeave = 'leave.approve';
    case ApproveAnyLeave = 'leave.approve_any';

    // Monitor kerja and Beban kerja; OverseeProjects widens the scope from own teams to the whole studio
    case ViewWorkMonitor = 'monitoring.work';

    // Superadmin
    case ManageUsers = 'users.manage';
    case ImpersonateUsers = 'users.impersonate';
    case ManageTeams = 'teams.manage';
    case ManageCalendar = 'calendar.manage';
    case ManageSettings = 'settings.manage';
    case ManageDevices = 'devices.manage';
    case ApplyCorrections = 'corrections.apply';
    case ViewAllReports = 'reports.view_all';
    case ExportReports = 'reports.export';
    case ViewAuditLog = 'audit.view';
    // Monitor aktivitas: sign-ins, page visits, changes and clock events of everyone
    case ViewActivityMonitor = 'monitoring.activity';
    // Leave types, yearly quotas, and every request
    case ManageLeave = 'leave.manage';
}
