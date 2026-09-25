<?php

namespace App\Modules\Identity\Access;

/**
 * Seeded roles. Code checks permissions, never role names; roles only group permissions.
 */
enum Role: string
{
    case Employee = 'employee';
    case TeamLead = 'team_lead';
    case ProjectManager = 'project_manager';
    case ProjectDirector = 'project_director';
    case Superadmin = 'superadmin';

    /** @return list<Permission> */
    public function permissions(): array
    {
        $work = [
            Permission::ClockIn,
            Permission::ViewProjects,
            Permission::LogActivity,
            Permission::RequestLeave,
        ];

        $management = [
            ...$work,
            Permission::ApproveOvertime,
            Permission::ViewTeamBoard,
            Permission::OpenWorkdays,
            Permission::ProposeCorrections,
            Permission::ViewTeamReports,
            Permission::ManageProjects,
            Permission::ApproveLeave,
            Permission::ViewWorkMonitor,
        ];

        return match ($this) {
            self::Employee => $work,
            self::TeamLead => $management,
            self::ProjectManager => [...$management, Permission::ApproveAnyOvertime, Permission::OverseeProjects, Permission::ApproveAnyLeave, Permission::ManageBudgets],
            self::ProjectDirector => [...$management, Permission::ApproveAnyOvertime, Permission::ChangeOvertimeDecisions, Permission::OverseeProjects, Permission::ApproveAnyLeave, Permission::ManageBudgets, Permission::ManagePipeline],
            self::Superadmin => [
                Permission::ClockIn,
                Permission::ViewTeamBoard,
                Permission::ViewProjects,
                Permission::ManageProjects,
                Permission::OverseeProjects,
                Permission::LogActivity,
                Permission::RequestLeave,
                Permission::ManageBudgets,
                Permission::ManagePipeline,
                Permission::ViewWorkMonitor,
                Permission::ManageUsers,
                Permission::ImpersonateUsers,
                Permission::ManageTeams,
                Permission::ManageCalendar,
                Permission::ManageSettings,
                Permission::ManageDevices,
                Permission::ApplyCorrections,
                Permission::ChangeOvertimeDecisions,
                Permission::ViewAllReports,
                Permission::ExportReports,
                Permission::ViewAuditLog,
                Permission::ViewActivityMonitor,
                Permission::ViewAppUsage,
                Permission::ManageLeave,
            ],
        };
    }

    public function isManagement(): bool
    {
        return in_array($this, [self::TeamLead, self::ProjectManager, self::ProjectDirector], true);
    }
}
