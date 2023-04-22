<?php

namespace App\Models;

use App\Attributes\NullIfEmptyAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SurveyAnswer extends ApiModel
{
    protected $table = 'survey_answer';
    public $timestamps = true;

    /*
     * Note the survey_answer.callsign column has the callsign value from the survey form from 2019 and prior.
     * The field was free form, and not validated. The reporting code still uses the column if person_id is 0.
     */

    protected $fillable = [
        'survey_id',
        'survey_group_id',
        'survey_question_id',
        'person_id',
        'trainer_id',
        'slot_id',
        'response',
        'can_share_name'
    ];

    protected $rules = [
        'person_id' => 'required|integer',
        'survey_id' => 'required|integer',
        'survey_group_id' => 'required|integer',
        'survey_question_id' => 'required|integer',
        'slot_id' => 'required|integer',
        'response' => 'required',
        'can_share_name' => 'sometimes|boolean',
        'trainer_id' => 'sometimes|integer|nullable',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'can_share_name' => false,
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Delete all answer from a person for a slot
     *
     * @param int $surveyId
     * @param int $personId
     * @param int $slotId
     */

    public static function deleteAllForPersonSlot(int $surveyId, int $personId, int $slotId)
    {
        DB::table('survey_answer')->where('survey_id', $surveyId)
            ->where('person_id', $personId)
            ->where('slot_id', $slotId)
            ->delete();
    }

    /**
     * Delete all answers related to a particular slot.
     *
     * @param int $slotId
     */

    public static function deleteForSlot(int $slotId): void
    {
        self::where('slot_id', $slotId)->delete();
    }

    /**
     * Does the person have feedback as a trainer?
     * @param $personId
     * @return bool
     */

    public static function haveTrainerFeedback($personId): bool
    {
        return self::where('trainer_id', $personId)->exists();
    }

    public function trainerId(): Attribute
    {
        return NullIfEmptyAttribute::make();
    }
}
