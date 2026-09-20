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
        $management = [
            Permission::ClockIn,
            Permission::ApproveOvertime,
            Permission::ViewTeamBoard,
            Permission::OpenWorkdays,
            Permission::ProposeCorrections,
            Permission::ViewTeamReports,
        ];

        return match ($this) {
            self::Employee => [Permission::ClockIn],
            self::TeamLead => $management,
            self::ProjectManager => [...$management, Permission::ApproveAnyOvertime],
            self::ProjectDirector => [...$management, Permission::ApproveAnyOvertime, Permission::ChangeOvertimeDecisions],
            self::Superadmin => [
                Permission::ClockIn,
                Permission::ManageUsers,
                Permission::ManageTeams,
                Permission::ManageCalendar,
                Permission::ManageSettings,
                Permission::ManageDevices,
                Permission::ApplyCorrections,
                Permission::ChangeOvertimeDecisions,
                Permission::ViewAllReports,
                Permission::ExportReports,
                Permission::ViewAuditLog,
            ],
        };
    }

    public function isManagement(): bool
    {
        return in_array($this, [self::TeamLead, self::ProjectManager, self::ProjectDirector], true);
    }
}
