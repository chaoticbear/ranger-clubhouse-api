<?php

namespace App\Lib\Reports;

use App\Models\Timesheet;
use Carbon\Carbon;

class PayrollReport
{
    public static function execute(string $startTime,
                                   string $endTime,
                                   int    $breakAfterHours,
                                   int    $breakDuration,
                                   array  $positionIds): array
    {
        $entriesByPerson = Timesheet::select('timesheet.*')
            ->join('position', 'position.id', 'timesheet.position_id')
            ->join('person', 'person.id', 'timesheet.person_id')
            ->with([
                'person:id,callsign,first_name,last_name,email,employee_id',
                'position:id,title,paycode,no_payroll_hours_adjustment'
            ])
            ->whereIn('timesheet.position_id', $positionIds)
            ->where(function ($w) use ($startTime, $endTime) {
                $w->where(function ($q) use ($startTime, $endTime) {
                    $q->where('timesheet.on_duty', '<=', $startTime);
                    $q->where('timesheet.off_duty', '>=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // Shift happens within the period
                    $q->where('timesheet.on_duty', '>=', $startTime);
                    $q->where('timesheet.off_duty', '<=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // Shift ends within the period
                    $q->where('timesheet.off_duty', '>', $startTime);
                    $q->where('timesheet.off_duty', '<=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // Shift begins within the period
                    $q->where('timesheet.on_duty', '>=', $startTime);
                    $q->where('timesheet.on_duty', '<', $endTime);
                });
            })->orderBy('timesheet.on_duty')
            ->get()
            ->groupBy('person_id');

        $startTime = Carbon::parse($startTime);
        $endTime = Carbon::parse($endTime);

        $people = [];
        $peopleWithoutIds = [];
        foreach ($entriesByPerson as $personId => $entries) {
            $shifts = [];
            foreach ($entries as $entry) {
                $notes = [];
                $onDuty = $entry->on_duty;
                $offDuty = ($entry->off_duty ?? now());

                $shift = [
                    'id' => $entry->id,
                    'position_id' => $entry->position->id,
                    'position_title' => $entry->position->title,
                    'paycode' => $entry->position->paycode,
                    'verified' => $entry->review_status == Timesheet::STATUS_VERIFIED || $entry->review_status == Timesheet::STATUS_APPROVED,
                    'orig_on_duty' => (string)$onDuty,
                    'orig_off_duty' => (string)$offDuty,
                    'orig_duration' => $offDuty->diffInSeconds($onDuty),
                ];

                if (!$entry->off_duty) {
                    $shift['still_on_duty'] = true;
                    $notes[] = 'Still on duty';
                    $offDuty = now();
                } else {
                    $offDuty = $entry->off_duty;
                }

                if ($startTime->gt($onDuty)) {
                    $notes[] = 'Truncated start time - orig. ' . self::formatDt($onDuty);
                    $onDuty = $startTime;
                }

                if ($offDuty->gt($endTime)) {
                    $notes[] = 'Truncated end time - orig. ' . self::formatDt($offDuty);
                    $offDuty = $endTime;
                }

                $durationSeconds = $offDuty->diffInSeconds($onDuty);
                $shift['duration'] = $durationSeconds;
                $shift['on_duty'] = self::formatDt($onDuty);
                $shift['off_duty'] = self::formatDt($offDuty);

                if ($entry->position->no_payroll_hours_adjustment) {
                    array_unshift($notes, 'Position set to not adjust hours.');
                } else if ($breakAfterHours) {
                    $hoursRoundedDown = (int)floor($durationSeconds / 3600);
                    if ($hoursRoundedDown > $breakAfterHours) {
                        $shift['meal_adjusted'] = self::computeMealBreak($onDuty, $durationSeconds, $breakAfterHours, $breakDuration);
                    }
                }

                $shift['notes'] = implode("\n", $notes);
                $shifts[] = $shift;
            }

            $person = $entries[0]->person;
            $info = [
                'id' => $person->id,
                'callsign' => $person->callsign,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'employee_id' => $person->employee_id,
                'shifts' => $shifts,
            ];

            if ($person->employee_id) {
                $people[] = $info;
            } else {
                $peopleWithoutIds[] = $info;
            }
        }

        usort($people, fn($a, $b) => strcasecmp($a['callsign'], $b['callsign']));
        usort($peopleWithoutIds, fn($a, $b) => strcasecmp($a['callsign'], $b['callsign']));

        return [
            'people' => $people,
            'people_without_ids' => $peopleWithoutIds
        ];
    }

    public static function computeMealBreak(Carbon $onDuty,
                                            int    $durationSeconds,
                                            int    $breakAfterHours,
                                            int    $breakDurationMinutes): array
    {
        // Split at meal
        $breakForMeal = $onDuty->clone();
        $breakForMeal->addHours($breakAfterHours);
        $afterMeal = $breakForMeal->clone()->addMinutes($breakDurationMinutes);
        $afterMealDurationSeconds = ($durationSeconds - (($breakAfterHours * 3600) + ($breakDurationMinutes * 60)));
        $endTime = $afterMeal->clone()->addSeconds($afterMealDurationSeconds);

        return [
            'first_half' => [
                'on_duty' => self::formatDt($onDuty),
                'off_duty' => self::formatDt($breakForMeal),
            ],
            'second_half' => [
                'on_duty' => self::formatDt($afterMeal),
                'off_duty' => self::formatDt($endTime),
            ]
        ];
    }

    public static function formatDt(Carbon $dt): string
    {
        return $dt->format('Y-m-d G:i');
    }
}