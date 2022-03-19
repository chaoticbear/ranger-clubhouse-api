<?php

namespace App\Http\Controllers;

use App\Lib\Moodle;
use App\Mail\OnlineTrainingEnrollmentMail;
use App\Models\Person;
use App\Models\PersonOnlineTraining;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class OnlineTrainingController extends ApiController
{
    /**
     * Return a list of people who have completed online training.
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function index(): JsonResponse
    {
        $query = request()->validate([
            'year' => 'required|integer',
            'person_id' => 'sometimes|integer'
        ]);

        $this->authorize('view', PersonOnlineTraining::class);
        $rows = PersonOnlineTraining::findForQuery($query);
        return $this->success($rows, null, 'person_ot');
    }

    /**
     * Create an online training account (if none exists)
     *
     * @param Person $person
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function setupPerson(Person $person): JsonResponse
    {
        $this->authorize('setupPerson', [PersonOnlineTraining::class, $person]);

        /*
         * Any active Ranger with 2 or more years experience gets to take the half course.
         * Everyone else (PNVs, Auditors, Binaries, Inactives, etc) take the full course.
         */
        /*
        if ($person->status == Person::ACTIVE
            && count(Timesheet::findYears($person->id, Timesheet::YEARS_RANGERED)) >= 2) {
            $courseId = setting('MoodleHalfCourseId');
            $type = 'half';
        } else {
        */
        $courseId = setting('MoodleFullCourseId');
        $type = 'full';
        /* }*/

        $password = null;
        $exists = true;
        $lms = null;

        if (empty($person->lms_id)) {
            // See if the person already has an online account setup
            $lms = new Moodle();
            if ($lms->findPerson($person) == false) {
                // Nope, create the user
                if ($lms->createUser($person, $password) == false) {
                    // Crap, failed.
                    return response()->json(['status' => 'fail']);
                }
                $exists = false;
            }
        }

        if ($person->lms_course != $courseId) {
            if (!$lms) {
                $lms = new Moodle;
            }

            // Enroll the person in the course
            $lms->enrollPerson($person, $courseId);
        }

        if (!$exists) {
            mail_to($person->email, new OnlineTrainingEnrollmentMail($person, $type, $password), true);
        }

        return response()->json([
            'status' => $exists ? 'exists' : 'created',
            'password' => $password,
            'course_type' => $type,
            'expiry_date' => (string)$person->lms_course_expiry,
        ]);
    }

    /**
     * Attempt to scan the Moodle enrollments and associate any account that does not have a user id
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function linkUsers(): JsonResponse
    {
        $this->authorize('linkUsers', PersonOnlineTraining::class);

        $fullId = setting('MoodleFullCourseId');
        $halfId = setting('MoodleHalfCourseId');

        $lms = new Moodle();

        return response()->json([
            'full_course' => !empty($fullId) ? $lms->linkUsersInCourse($fullId) : [],
            'half_course' => !empty($halfId) ? $lms->linkUsersInCourse($halfId) : [],
        ]);
    }

    /**
     * Obtain the online training configuration
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function config(): JsonResponse
    {
        $this->authorize('config', PersonOnlineTraining::class);

        $otSettings = setting([
            'OnlineTrainingDisabledAllowSignups',
            'OnlineTrainingEnabled',
            'MoodleFullCourseId',
            'MoodleHalfCourseId'
        ]);

        return response()->json($otSettings);
    }

    /**
     * Retrieve all available courses
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function courses(): JsonResponse
    {
        $this->authorize('courses', PersonOnlineTraining::class);

        $lms = new Moodle();
        $fullCourse = setting('MoodleFullCourseId');
        $halfCourse = setting('MoodleHalfCourseId');
        $courses = $lms->retrieveAvailableCourses();
        foreach ($courses as $course) {
            if ($course->id == $fullCourse) {
                $course->is_full_course = true;
            }
            if ($course->id == $halfCourse) {
                $course->is_half_course = true;
            }
        }
        return response()->json(['courses' => $courses]);
    }

    /**
     * Retrieve all available courses
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function setCourseType(): JsonResponse
    {
        $this->authorize('setCourseType', PersonOnlineTraining::class);

        $params = request()->validate([
            'course_id' => 'integer|required',
            'type' => 'string|required'
        ]);

        $setting = Setting::findOrFail($params['type'] == 'full' ? 'MoodleFullCourseId' : 'MoodleHalfCourseId');
        $setting->value = (string)$params['course_id'];
        $setting->auditReason = "online course update";
        if (!$setting->save()) {
            return $this->restError($setting);
        }
        Setting::kickQueues();
        return $this->success();
    }

    /**
     * Retrieve everyone who is enrolled.
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */

    public function enrollment(): JsonResponse
    {
        $this->authorize('enrollment', PersonOnlineTraining::class);
        $lms = new Moodle();

        $fullId = setting('MoodleFullCourseId');
        $halfId = setting('MoodleHalfCourseId');

        return response()->json([
            'full_course' => !empty($fullId) ? $lms->retrieveCourseEnrollmentWithCompletion($fullId) : [],
            'half_course' => !empty($halfId) ? $lms->retrieveCourseEnrollmentWithCompletion($halfId) : [],
        ]);
    }
}
