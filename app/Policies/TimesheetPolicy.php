<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\Role;
use App\Models\Timesheet;
use Illuminate\Auth\Access\HandlesAuthorization;

class TimesheetPolicy
{
    use HandlesAuthorization;

    public function before(Person $user)
    {
        if ($user->hasRole([Role::TIMESHEET_MANAGEMENT, Role::ADMIN])) {
            return true;
        }
    }

    /*
     * Determine whether the user can view the timesheet.
     */

    public function index(Person $user, $personId): bool
    {
        return $user->hasRole(Role::MANAGE) || ($user->id == $personId);
    }

    /*
     * can the user create a timesheet
     */

    public function store(Person $user, Timesheet $timesheet): bool
    {
        return false;
    }

    /*
     * Can the user update a timesheet?
     */

    public function update(Person $user, Timesheet $timesheet): bool
    {
        return $user->hasRole(Role::MANAGE) || ($user->id == $timesheet->person_id);
    }

    /**
     * Can the user update the position on an active timesheet?
     */

    public function updatePosition(Person $user, Timesheet $timesheet): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can a user confirm the timesheet?
     */

    public function confirm(Person $user, $personId): bool
    {
        return $user->hasRole(Role::TECH_NINJA) || ($user->id == $personId);
    }

    /*
     * Can a user delete a timesheet? Only timesheet manager,
     * or admin, covered in before()
     */

    public function destroy(Person $user, Timesheet $timesheet): bool
    {
        return false;
    }

    /*
     * Can user signin the person?
     */

    public function signin(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can user re-signin the person?
     */

    public function resignin(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can user signoff the timesheet?
     */

    public function signoff(Person $user, Timesheet $timesheet): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can user see a timesheet log?
     */

    public function log(Person $user, $id): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user see the timesheet correction requests?
     */

    public function correctionRequests(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user see the timesheet unconfirmed people?
     */

    public function unconfirmedPeople(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user see the timesheet unconfirmed people?
     */

    public function sanityChecker(Person $user): bool
    {
        return $user->hasRole([Role::ADMIN, Role::TIMESHEET_MANAGEMENT]);
    }

    /**
     * Can the user run a freaking years report?
     */

    public function freakingYearsReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user run a freaking years report?
     */

    public function shirtsEarnedReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user run a potential shirts earned report?
     */

    public function potentialShirtsEarnedReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user run a radio eligibility report?
     */

    public function radioEligibilityReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user bulk sign in and/or out people?
     */

    public function bulkSignInOut(Person $user): bool
    {
        return false;
    }

    /**
     * Can the user run the hours/credit report
     */

    public function hoursCreditsReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user run the Special Teams report
     */

    public function specialTeamsReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the user run the Thank You cards report
     */

    public function thankYou(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user run the Timesheet By Callsign report
     */

    public function timesheetByCallsign(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user run the Timesheet Totals report
     */

    public function timesheetTotals(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /*
     * Can the user run the Timesheet By Position Report
     */

    public function timesheetByPosition(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    /**
     * Can the person run the On Duty Shift Lead Report
     *
     * @param Person $user
     * @return bool
     */
    public function onDutyShiftLeadReport(Person $user): bool
    {
        return $user->hasRole(Role::MANAGE);
    }

    public function retentionReport(Person $user): bool
    {
        return false;
    }

    public function topHourEarnersReport(Person $user): bool
    {
        return false;
    }

    public function repairSlotAssociations(Person $user) : bool
    {
        return false;
    }

    public function eventStatsReport(Person $user) : bool
    {
        return $user->hasRole(Role::MANAGE);
    }
}
