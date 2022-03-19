<?php

/*
 * NOTE: when adding new columns to the person table, there are three
 * places the column should be added to:
 *
 * - The $fillable array in this file
 * - in app/Http/Filters/PersonFilter.php
 * - on the frontend app/models/person.js
 */

namespace App\Models;

use App\Helpers\SqlHelper;
use Carbon\Carbon;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use NumberFormatter;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;


class Person extends ApiModel implements JWTSubject, AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable, Notifiable;

    // How long is the reset password token good for? (in seconds)
    const RESET_PASSWORD_EXPIRE = (3600 * 2);

    // How long is the pnv invite token sent in the welcome email good for? (in seconds)
    const PNV_INVITATION_EXPIRE = (3600 * 24 * 14);

    // For resetting and adding roles & positions for new users
    const REMOVE_ALL = 0;
    const ADD_NEW_USER = 1;

    const ACTIVE = 'active';
    const ALPHA = 'alpha';
    const AUDITOR = 'auditor';
    const BONKED = 'bonked';
    const DECEASED = 'deceased';
    const DISMISSED = 'dismissed';
    const INACTIVE = 'inactive';
    const INACTIVE_EXTENSION = 'inactive extension';
    const NON_RANGER = 'non ranger';
    const PAST_PROSPECTIVE = 'past prospective';
    const PROSPECTIVE = 'prospective';
    const RESIGNED = 'resigned';
    const RETIRED = 'retired';
    const SUSPENDED = 'suspended';
    const UBERBONKED = 'uberbonked';

    // Deprecated statuses - no longer used, retained for historical purposes
    const PROSPECTIVE_WAITLIST = 'prospective waitlist';
    const VINTAGE = 'vintage';

    /*
     * Statuses consider 'live' or still active account allowed
     * to login, and do stuff.
     * Used by App\Validator\StateForCountry & BroadcastController
     */

    const LIVE_STATUSES = [
        Person::ACTIVE,
        Person::ALPHA,
        Person::INACTIVE,
        Person::INACTIVE_EXTENSION,
        Person::NON_RANGER,
        Person::PAST_PROSPECTIVE,
        Person::PROSPECTIVE,
        Person::RETIRED,
    ];

    const ACTIVE_STATUSES = [
        Person::ACTIVE,
        Person::INACTIVE,
        Person::INACTIVE_EXTENSION,
        Person::RETIRED
    ];

    /*
     * Locked status are those which the account cannot be allowed
     * to logged into (either tempoarily or permanently), and which
     * should not receive messages.
     */

    const LOCKED_STATUSES = [
        Person::DECEASED,
        Person::DISMISSED,
        Person::RESIGNED,
        Person::SUSPENDED,
        Person::UBERBONKED,
    ];

    /*
     * No messages status are those that should not receive any messages
     * either Clubhouse Messaging or the RBS
     */

    const NO_MESSAGES_STATUSES = [
        Person::BONKED,
        Person::DECEASED,
        Person::DISMISSED,
        Person::PAST_PROSPECTIVE,
        Person::RESIGNED,
        Person::SUSPENDED,
        Person::UBERBONKED
    ];


    /**
     * The database table name.
     * @var string
     */
    protected $table = 'person';
    protected $auditModel = true;

    public $auditExclude = [
        'password',
        'tpassword',
        'tpassword_expire',
        'logged_in_at',
        'last_seen_at',
        'callsign_normalized',
        'callsign_soundex'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'tpassword',
        'tpassword_expire',
        'callsign_normalized',
        'callsign_soundex'
    ];

    protected $casts = [
        'active_next_event' => 'boolean',
        'behavioral_agreement' => 'boolean',
        'callsign_approved' => 'boolean',
        'has_note_on_file' => 'boolean',
        'on_site' => 'boolean',
        'osha10' => 'boolean',
        'osha30' => 'boolean',
        'vehicle_blacklisted' => 'boolean',

        'create_date' => 'datetime',
        'date_verified' => 'date',
        'status_date' => 'date',
        'message_updated_at' => 'datetime',
        'timestamp' => 'timestamp',
        'logged_in_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'lms_course_expiry' => 'datetime',

        'reviewed_pi_at' => 'datetime',
        'pi_reviewed_for_dashboard_at' => 'datetime',
    ];

    /*
     * Do not forget to add the column name to PersonFilter as well.
     */

    protected $fillable = [
        'callsign',
        'callsign_approved',
        'formerly_known_as',
        'callsign_pronounce',

        'status',
        'status_date',
        'timestamp',

        'date_verified',
        'create_date',


        'vintage',
        'behavioral_agreement',

        'gender',
        'pronouns',
        'pronouns_custom',

        'message',

        'email',
        'first_name',
        'mi',
        'last_name',
        'street1',
        'street2',
        'apt',
        'city',
        'state',
        'zip',
        'country',

        'home_phone',
        'alt_phone',

        'on_site',
        'longsleeveshirt_size_style',
        'teeshirt_size_style',
        'emergency_contact',
        'camp_location',

        'reviewed_pi_at',
        'has_reviewed_pi',  // Pseudo field

        'vehicle_blacklisted',

        // various external services identifiers
        'bpguid',
        'sfuid',
        'lms_id',
        'lms_course',
        'lms_course_expiry',

        'active_next_event',
        'has_note_on_file',

        'known_rangers',
        'known_pnvs',

        // 'meta' objects
        'languages',

        // SMS fields
        'sms_on_playa',
        'sms_off_playa',
        'sms_on_playa_verified',
        'sms_off_playa_verified',
        'sms_on_playa_stopped',
        'sms_off_playa_stopped',
        'sms_on_playa_code',
        'sms_off_playa_code',

        // Certifications
        'osha10',
        'osha30',
    ];

    // Various associated person tables
    const ASSOC_TABLES = [
        //'access_document_changes',
        'access_document_delivery',
        'access_document',
        'action_logs',
        'alert_person',
        'asset_person',
        'bmid',
        'broadcast_message',
        //'contact_log',
        'manual_review',
        'mentee_status',
        'person_event',
        'person_intake',
        'person_intake_note',
        'person_language',
        'person_mentor',
        'person_message',
        'person_online_training',
        'person_position',
        'person_role',
        'person_slot',
        'radio_eligible',
        'timesheet',
        'timesheet_log',
        'timesheet_missing',
        'trainee_note',
        'trainee_status',
        'trainer_status'
    ];

    protected $rules = [
        'callsign' => 'required|string|max:64',
        'callsign_pronounce' => 'sometimes|string|nullable|max:200',
        'status' => 'required|string',
        'formerly_known_as' => 'sometimes|string|nullable|max:200',

        'first_name' => 'required|string|max:25',
        'mi' => 'sometimes|string|nullable|max:10',
        'last_name' => 'required|string|max:25',

        'email' => 'required|string|max:50',

        'street1' => 'required|string|nullable|max:128',
        'street2' => 'sometimes|string|nullable|max:128',
        'apt' => 'sometimes|string|nullable|max:10',
        'city' => 'required|string|max:50',

        'state' => 'state_for_country:live_only',
        'country' => 'required|string|max:25',

        'home_phone' => 'sometimes|string|max:25',
        'alt_phone' => 'sometimes|string|nullable|max:25',

        'camp_location' => 'sometimes|string|nullable|max:200',
        'gender' => 'sometimes|string|nullable|max:32',
        'pronouns' => 'sometimes|string|nullable',
        'pronouns_custom' => 'sometimes|string|nullable',

        'has_reviewed_pi' => 'sometimes|boolean',
    ];

    public $has_reviewed_pi;

    /*
     * The roles the person holds
     * @var array
     */

    public $roles;


    /*
     * The languages the person speaks. (handled thru class PersonLanguage)
     * @var string
     */

    public $languages;

    /**
     * Setup various before save or create callback methods
     */

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            // Set the create date to the current time
            $model->create_date = now();
        });

        self::saving(function ($model) {
            if ($model->isDirty('message')) {
                $model->message_updated_at = now();
            }

            // Ensure shirts are always set correctly
            if (empty($model->longsleeveshirt_size_style)) {
                $model->longsleeveshirt_size_style = 'Unknown';
            }

            if (empty($model->teeshirt_size_style)) {
                $model->teeshirt_size_style = 'Unknown';
            }

            if ($model->pronouns != 'custom') {
                $model->pronouns_custom = '';
            }

            /*
             * When the status is updated to Past Prospective and the callsign is
             * not being changed, reset the callsign and un-approve it.
             */

            if ($model->isDirty('status')
                && $model->status == Person::PAST_PROSPECTIVE
                && !$model->isDirty('callsign')) {
                $model->resetCallsign();
                $model->callsign_approved = false;
            }
        });
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return int
     */

    public function getJWTIdentifier(): int
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function person_position()
    {
        return $this->hasMany(PersonPosition::class);
    }

    public function person_photo()
    {
        return $this->belongsTo(PersonPhoto::class);
    }

    public function person_role()
    {
        return $this->hasMany(PersonRole::class);
    }

    /**
     * Find an account by its email.
     *
     * @param string $email
     * @return Person|null
     */

    public static function findByEmail(string $email) : ?Person
    {
        return self::where('email', $email)->first();
    }

    /**
     * Find a record by callsign
     *
     * @param string $callsign
     * @return Person|null
     */

    public static function findByCallsign(string $callsign) : ?Person
    {
        return self::where('callsign', $callsign)->first();
    }

    /**
     * Look up an account id by its callsign
     *
     * @param string $callsign
     * @return int|null
     */

    public static function findIdByCallsign(string $callsign) : ?int
    {
        $row = self::select('id')->where('callsign', $callsign)->first();
        if ($row) {
            return $row->id;
        }

        return null;
    }

    /**
     * Find a person by their LMS ID.
     *
     * @param string $lmsId
     * @return ?Person
     */

    public static function findByLmsID(string $lmsId) : ?Person
    {
        return self::where('lms_id', $lmsId)->first();
    }


    public function save($options = [])
    {
        $isNew = !$this->exists;
        if ($isNew) {
            // Creating record = require callsign & email
            $this->rules['callsign'] = 'required|string|unique:person,callsign';
            $this->rules['email'] = 'required|string|unique:person,email';
        } else {
            if ($this->isDirty('callsign')) {
                // updating a callsign on an existing record
                $this->rules['callsign'] = 'required|string|unique:person,callsign,' . $this->id;
            }
            if ($this->isDirty('email')) {
                $this->rules['email'] = 'required|string|unique:person,email,' . $this->id;
            }
        }

        $result = parent::save($options);

        if ($isNew) {
            // Kill the rules in case the newly recreated record is updated further, otherwise
            // the callsign & email rules will be acted upon and always fail.
            unset($this->rules['callsign']);
            unset($this->rules['email']);
        }

        return $result;
    }

    /*
     * Bulk lookup by callsigns
     * return an associative array index by callsign
     */

    public static function findAllByCallsigns(array $callsigns)
    {
        $normalizedCallsigns = array_map(fn($name) => Person::normalizeCallsign($name), $callsigns);
        $rows = self::whereIn('callsign_normalized', $normalizedCallsigns)->get();

        return $rows->reduce(function ($keys, $row) {
            $keys[strtolower($row->callsign_normalized)] = $row;
            return $keys;
        }, []);
    }

    public static function emailExists($email)
    {
        return self::where('email', $email)->exists();
    }

    /**
     * Find people based on criteria:
     * query: string to search for
     * search_fields: list of fields to search - callsign, name, formerly_known_as, email (comma separated)
     * statuses: list of statuses to match (comma separated)
     * limit: number of results to limit query to
     * offset: record offset in search
     *
     * @param array $query
     * @return array
     */

    public static function findForQuery(array $query): array
    {
        if (isset($query['query'])) {
            $orderCallsigns = true;
            $orderRealName = true;
            $orderEmail = true;
            $orderFKA = true;

            // remove duplicate spaces
            $q = trim(preg_replace('/\s+/', ' ', $query['query']));
            $normalized = self::normalizeCallsign($q);
            $metaphone = metaphone(self::spellOutNumbers($normalized));

            if (str_starts_with($q, '+')) {
                // Search by number
                $q = ltrim($q, '+');
                $person = self::find(intval($q));

                if ($person) {
                    $total = $limit = 1;
                } else {
                    $total = $limit = 0;
                }
                return [
                    'people' => $person ? [$person] : [],
                    'total' => $total,
                    'limit' => $limit
                ];
            }
            $likeQuery = '%' . $q . '%';

            $emailOnly = (stripos($q, '@') !== false);
            if ($emailOnly) {
                // Force email only search if @ is present
                $sql = self::where(function ($sql) use ($likeQuery, $q) {
                    $sql->where('email', $q);
                    $sql->orWhere('email', 'like', $likeQuery);
                });
                $orderCallsigns = false;
                $orderFKA = false;
                $orderRealName = false;
            } elseif (isset($query['search_fields'])) {
                $fields = explode(',', $query['search_fields']);

                $orderCallsigns = false;
                $orderRealName = false;
                $orderEmail = false;
                $orderFKA = false;

                foreach ($fields as $field) {
                    switch ($field) {
                        case 'callsign':
                            $orderCallsigns = true;
                            break;
                        case 'name':
                            $orderRealName = true;
                            break;
                        case 'formerly_known_as':
                            $orderFKA = true;
                            break;
                        case 'email':
                            $orderEmail = true;
                            break;
                    }
                }


                $sql = self::where(function ($sql) use ($q, $fields, $likeQuery, $normalized, $metaphone) {
                    foreach ($fields as $field) {
                        switch ($field) {
                            case 'callsign':
                                $sql->orWhere('callsign_normalized', $normalized);
                                $sql->orWhere('callsign_normalized', 'like', '%' . $normalized . '%');
                                $sql->orWhere('callsign_soundex', $metaphone);
                                $sql->orWhere('callsign_soundex', 'like', $metaphone . '%');
                                break;

                            case 'name':
                                $sql->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE " . SqlHelper::quote($likeQuery));
                                $sql->orWhere('last_name', 'like', SqlHelper::quote($q . '%'));
                                break;

                            case 'email':
                            case 'formerly_known_as':
                                $sql->orWhere($field, 'like', $likeQuery);
                                break;


                            default:
                                throw new InvalidArgumentException("Search field '$field' is invalid.");
                        }
                    }
                });
            } else {
                $sql = self::where('callsign', 'like', $likeQuery);
            }

            if ($emailOnly) {
                $sql->orderBy('email');
            } else {
                $orderBy = "CASE";
                /*
                 * Callsign sort priority is
                 *  - Exact callsign match
                 * - Beginning of callsign match
                 * - Substring callsign match
                 * - Exact phonetic callsign match
                 * - Beginning of phonetic match
                 * - substring phonetic match
                 * - Everything else
                 */
                if ($orderCallsigns) {
                    $orderBy .= " WHEN callsign_normalized=" . SqlHelper::quote($normalized) . " THEN CONCAT('01', callsign)";
                    $orderBy .= " WHEN callsign_normalized like " . SqlHelper::quote($normalized . '%') . " THEN CONCAT('02', callsign)";
                    $orderBy .= " WHEN callsign_normalized like " . SqlHelper::quote('%' . $normalized . '%') . " THEN CONCAT('03', callsign)";
                    $orderBy .= " WHEN callsign_soundex=" . SqlHelper::quote($metaphone) . " THEN CONCAT('04', callsign)";
                    $orderBy .= " WHEN callsign_soundex like " . SqlHelper::quote($metaphone . '%') . " THEN CONCAT('05', callsign)";
                    $orderBy .= " WHEN callsign_soundex like " . SqlHelper::quote('%' . $metaphone . '%') . " THEN CONCAT('06', callsign)";
                }

                if ($orderRealName) {
                    $orderBy .= " WHEN CONCAT(first_name, ' ', last_name) LIKE " . SqlHelper::quote("%" . $q . "%") . " THEN CONCAT('12', first_name, ' ', last_name)";
                    $orderBy .= " WHEN CONCAT(last_name) LIKE " . SqlHelper::quote($q . "%") . " THEN CONCAT('11', 'last_name')";
                }

                if ($orderFKA) {
                    $orderBy .= " WHEN formerly_known_as LIKE " . SqlHelper::quote("%" . $q . "%") . " THEN CONCAT('20', formerly_known_as)";
                }

                if ($orderEmail) {
                    $orderBy .= " WHEN email LIKE " . SqlHelper::quote("%" . $q . "%") . " THEN CONCAT('30', email)";
                }
                $orderBy .= " ELSE CONCAT('99', callsign) END";
                $sql->orderBy(DB::raw($orderBy));
            }
        } else {
            $sql = self::orderBy('callsign');
        }

        if (isset($query['statuses'])) {
            $sql->whereIn('status', explode(',', $query['statuses']));
        }

        if (isset($query['exclude_statuses'])) {
            $sql->whereNotIn('status', explode(',', $query['exclude_statuses']));
        }


        if (isset($query['limit'])) {
            $limit = $query['limit'];
        } else {
            $limit = 50;
        }

        if (isset($query['offset'])) {
            $sql = $sql->offset($query['offset']);
        }

        $total = $sql->count();
        $sql->limit($limit);

        return [
            'people' => $sql->get(),
            'total' => $total,
            'limit' => $limit
        ];
    }

    /**
     * Normalize a callsign by removing spaces, and converting to lowercase
     *
     * @param string $callsign
     * @return string
     */

    public static function normalizeCallsign(string $callsign): string
    {
        return strtolower(preg_replace('/[^\w]/', '', $callsign));
    }

    /**
     * Search for callsign types
     *
     * @param string $query string to match against callsigns
     * @param string $type callsign search type
     * @param int $limit
     * @return mixed person id & callsigns which match
     */

    public static function searchCallsigns(string $query, string $type, int $limit): mixed
    {
        $like = '%' . $query . '%';

        $normalized = self::normalizeCallsign($query);
        $metaphone = metaphone(self::spellOutNumbers($normalized));
        $quoted = SqlHelper::quote($normalized);
        $orderBy = "CASE WHEN callsign_normalized=$quoted THEN CONCAT('01', callsign)";
        $quoted = SqlHelper::quote($metaphone);
        $orderBy .= " WHEN callsign_soundex=$quoted THEN CONCAT('02', callsign)";
        $orderBy .= " ELSE CONCAT('03', callsign) END";

        $sql = DB::table('person')
            ->where(function ($q) use ($query, $like, $normalized, $metaphone) {
                $q->orWhere('callsign_soundex', $metaphone);
                $q->orWhere('callsign_normalized', $normalized);
                $q->orWhere('callsign_normalized', 'like', '%' . $normalized . '%');
            })->limit($limit)
            ->orderBy(DB::raw($orderBy));

        switch ($type) {
            case 'contact':
                return $sql->select('person.id', 'callsign', DB::raw('IF(person.status="inactive", true,false) as is_inactive'), DB::raw('IFNULL(alert_person.use_email,1) as allow_contact'))
                    ->whereIn('status', [Person::ACTIVE, Person::INACTIVE])
                    ->leftJoin('alert_person', function ($join) {
                        $join->whereRaw('alert_person.person_id=person.id');
                        $join->where('alert_person.alert_id', '=', Alert::RANGER_CONTACT);
                    })->get()->toArray();

            // Trying to send a clubhouse message
            case 'message':
                return $sql->whereIn('status', [Person::ACTIVE, Person::INACTIVE, Person::ALPHA])->get(['id', 'callsign']);

            // Search all users
            case 'all':
                return $sql->get(['id', 'callsign']);
        }

        throw new InvalidArgumentException("Unknown type [$type]");
    }

    /**
     * Is the given password the one for this account?
     *
     * @param string $password
     * @return bool
     */

    public function isValidPassword(string $password): bool
    {
        $encyptedPw = $this->password;
        if (strpos($encyptedPw, ':') === false) {
            return false;
        }

        list($salt, $sha) = explode(':', $encyptedPw);
        $hashedPw = sha1($salt . $password);

        return ($hashedPw == $sha);
    }

    /**
     * Set a new password and clear out the temporary login token.
     *
     * @param string $password
     * @return bool
     */

    public function changePassword(string $password): bool
    {
        $salt = self::generateRandomString();
        $sha = sha1($salt . $password);

        $this->password = "$salt:$sha";

        // Clear out the temporary login token
        $this->tpassword = '';
        $this->tpassword_expire = 0;
        return $this->saveWithoutValidation();
    }


    /**
     * Create, or extend, a temporary login token used to reset a password or, for an invited
     * PNV, set a password.
     *
     * @param int $expireDuration time in seconds the token is good for.
     * @return string
     */

    public function createTemporaryLoginToken(int $expireDuration = self::RESET_PASSWORD_EXPIRE): string
    {
        $timestamp = now()->timestamp;
        // Generate a new token if none exists or the token has expired
        if (empty($this->tpassword) || $timestamp > $this->tpassword_expire) {
            $this->tpassword = sprintf("%04x%s", $this->id, self::generateRandomString());
        }
        $this->tpassword_expire = $timestamp + $expireDuration;
        $this->saveWithoutValidation();

        return $this->tpassword;
    }

    /**
     * Return the role ids (used by Person record serialization)
     *
     * @return ?array
     */

    public function getRolesAttribute(): ?array
    {
        return $this->roles;
    }

    /**
     * Retrieve the person's assigned roles.
     *
     * Add MANAGE if person has MANAGE_ON_PLAYA and if LoginManageOnPlayaEnabled is true
     * Add TRAINER if person has TRAINER_SEASONAL and if TrainingSeasonalRoleEnabled is true
     */

    public function retrieveRoles(): void
    {
        $this->roles = PersonRole::findRoleIdsForPerson($this->id);

        if (in_array(Role::MANAGE_ON_PLAYA, $this->roles) && setting('LoginManageOnPlayaEnabled')) {
            $this->roles[] = Role::MANAGE;
        }

        if (in_array(Role::TRAINER_SEASONAL, $this->roles) && setting('TrainingSeasonalRoleEnabled')) {
            $this->roles[] = Role::TRAINER;
        }
    }

    /**
     * Check to see if the person has a specific role or roles.
     *
     * @param array|int $role
     * @return bool true if the person has the role
     */

    public function hasRole(array|int $role): bool
    {
        if ($this->roles === null) {
            $this->retrieveRoles();
        }

        if (is_array($role)) {
            foreach ($role as $r) {
                if (in_array($r, $this->roles)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($role, $this->roles);
    }

    /**
     * Is the person an Admin?
     *
     * @return bool
     */

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /**
     * Is the person a prospective new volunteer? (prospector or alpha status)
     *
     * @return bool
     */

    public function isPNV(): bool
    {
        $status = $this->status;

        return ($status == Person::PROSPECTIVE || $status == Person::ALPHA);
    }

    /**
     * Is the person an auditor?
     *
     * @return bool
     */

    public function isAuditor(): bool
    {
        return ($this->status == Person::AUDITOR);
    }

    /**
     * Creates a 10 character random alphanumeric string. Use primarily to generate
     * a temporary password.
     *
     * @return string
     */

    public static function generateRandomString(): string
    {
        $length = 10;
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($characters) - 1;
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[mt_rand(0, $max)];
        }

        return $token;
    }

    /**
     * Obtain the pseudo-field languages
     *
     * @return mixed
     */

    public function getLanguagesAttribute()
    {
        return $this->languages;
    }

    /**
     * Set the pseudo-field languages
     *
     * @param $value
     * @return void
     */

    public function setLanguagesAttribute($value)
    {
        $this->languages = $value;
    }

    /**
     * Set pseudo-field has_reviewed_pi.
     *
     * @param $value
     * @return void
     */

    public function setHasReviewedPiAttribute($value)
    {
        $this->has_reviewed_pi = $value;
    }

    /**
     * Obtain the create_date field.
     *
     * Accounts created prior to 2010 have a 0000-00-00 date. Return null if that's
     * the case.
     *
     * @return Carbon|null
     */

    public function getCreateDateAttribute(): ?Carbon
    {
        if ($this->attributes == null) {
            return null;
        }

        $date = $this->attributes['create_date'] ?? null;

        if ($date == null) {
            return null;
        }

        $date = Carbon::parse($date);
        if ($date->year <= 0) {
            return null;
        }

        return $date;
    }

    /**
     * Change the account status and adjust the positions & roles if need be.
     *
     * TODO figure out a better way to do this. Mostly copied from Clubhouse 1.
     *
     * @param string $newStatus
     * @param string $oldStatus
     * @param string $reason
     */

    public function changeStatus(string $newStatus, string $oldStatus, string $reason)
    {
        if ($newStatus == $oldStatus) {
            return;
        }

        $personId = $this->id;
        $this->status_date = now();
        $this->status = $newStatus;

        PersonStatus::record($this->id, $oldStatus, $newStatus, $reason, Auth::id());

        $changeReason = $reason . " new status $newStatus";

        switch ($newStatus) {
            case Person::ACTIVE:
                // grant the new ranger all the basic positions
                $addIds = Position::where('all_rangers', true)->pluck('id');
                PersonPosition::addIdsToPerson($personId, $addIds, $changeReason);

                // Add login role
                $addIds = Role::where('new_user_eligible', true)->pluck('id');
                PersonRole::addIdsToPerson($personId, $addIds, $changeReason);

                // First-year Alphas get the Dirt - Shiny Penny position
                if ($oldStatus == Person::ALPHA) {
                    PersonPosition::addIdsToPerson($personId, [Position::DIRT_SHINY_PENNY], $changeReason);
                }
                break;

            case Person::ALPHA:
                // grant the alpha position
                PersonPosition::addIdsToPerson($personId, [Position::ALPHA], $changeReason);
                break;

            case Person::UBERBONKED:
            case Person::DECEASED:
            case Person::DISMISSED:
            case Person::RESIGNED:
                // Remove all positions
                PersonPosition::resetPositions($personId, $changeReason, Person::REMOVE_ALL);

                // Remove all roles
                PersonRole::resetRoles($personId, $changeReason, Person::REMOVE_ALL);
                break;

            case Person::BONKED:
                // Remove all positions
                PersonPosition::resetPositions($personId, $changeReason, Person::REMOVE_ALL);
                break;

            // Note that it used to be that changing status to INACTIVE
            // removed all of your positions other than "Training."  We decided
            // in 2015 not to do this anymore because we lose too much historical
            // information.

            // If you are one of the below, the only role you get is login
            // and position is Training

            case Person::RETIRED:
            case Person::AUDITOR:
            case Person::PROSPECTIVE:
            case Person::PROSPECTIVE_WAITLIST:
            case Person::PAST_PROSPECTIVE:
                // Remove all roles, and reset back to the default roles
                PersonRole::resetRoles($personId, $changeReason, Person::ADD_NEW_USER);

                // Remove all positions, and reset back to the default positions
                PersonPosition::resetPositions($personId, $changeReason, Person::ADD_NEW_USER);
                break;
        }

        if ($oldStatus == Person::ALPHA) {
            // if you're no longer an alpha, you can't sign up for alpha shifts
            PersonPosition::removeIdsFromPerson($personId, [Position::ALPHA], $reason . ' no longer alpha');
        }

        if (in_array($newStatus, self::LOCKED_STATUSES)) {
            $event = PersonEvent::findForPersonYear($this->id, current_year());
            if ($event) {
                $event->asset_authorized = false;
                $event->auditReason = 'locked status ' . $newStatus;
                $event->saveWithoutValidation();
            }
        }
    }

    /**
     * Reset callsign to the last name, first character of first name, and the last two digits of the current year
     * LastFirstYY
     *
     * If the person was bonked, append a 'B'.
     * If the person is an auditor, append a '(NR)'
     * If the new callsign already exits, find one that does not exist by appending a number to the last name.
     *
     * e.g. Jane Smith, year 2019 -> SmithJ19
     *           or Smith1J19, Smith2J19, etc if SmithJ19 already exists.
     *
     * @return bool true if the callsign was successfully reset
     */

    public function resetCallsign(): bool
    {
        $year = current_year() % 100;
        for ($tries = 0; $tries < 10; $tries++) {
            $newCallsign = $this->last_name;
            if ($tries > 0) {
                $newCallsign .= $tries + 1;
            }
            $newCallsign .= substr($this->first_name, 0, 1) . $year;
            if ($this->status == Person::BONKED) {
                $newCallsign .= 'B';
            } else if ($this->status == Person::AUDITOR) {
                $newCallsign .= '(NR)';
            }

            if (!self::where('callsign', $newCallsign)->exists()) {
                $this->callsign = $newCallsign;
                return true;
            }
        }

        return false;
    }

    /**
     * Store a normalized and metaphone version of the callsign
     *
     * @param string $value
     */

    public function setCallsignAttribute(string $value)
    {
        $value = trim($value);
        $this->attributes['callsign'] = $value;
        $this->attributes['callsign_normalized'] = self::normalizeCallsign($value ?? ' ');
        $this->attributes['callsign_soundex'] = metaphone(self::spellOutNumbers($this->attributes['callsign_normalized']));

        // Update the callsign FKA if the callsign did actually change.
        if ($this->isDirty('callsign')) {
            $oldCallsign = $this->getOriginal('callsign');
            if (!empty($oldCallsign)) {
                $fka = $this->formerly_known_as;
                if (empty($fka)) {
                    $this->formerly_known_as = $oldCallsign;
                } elseif (stripos($fka, $oldCallsign) === false) {
                    $this->formerly_known_as = $fka . ',' . $oldCallsign;
                }
            }
        }
    }

    /**
     * Normalize long sleeve shirt sizes
     *
     * @return string
     */

    public function getLongsleeveshirtSizeStyleAttribute(): string
    {
        return $this->attributes['longsleeveshirt_size_style'] ?? 'Unknown';
    }

    /**
     * Normalize T-Shirt sizes
     *
     * @return string
     */

    public function getTeeshirtSizeStyleAttribute(): string
    {
        return $this->attributes['teeshirt_size_style'] ?? 'Unknown';
    }

    /**
     * Summarize gender - used by the Shift Lead Report
     *
     * @param ?string $gender
     * @return string
     */

    public static function summarizeGender(?string $gender): string
    {
        $check = trim(strtolower($gender));

        // Female gender
        if (preg_match('/\b(female|girl|femme|lady|she|her|woman|famale|femal|fem|cis[\s\-]?female)\b/', $check) || $check == 'f') {
            return 'F';
        }

        // Male gender
        if (preg_match('/\b(male|dude|fella|man|boy)\b/', $check) || $check == 'm') {
            return 'M';
        }

        // Non-Binary
        if (preg_match('/\bnon[\s\-]?binary\b/', $check)) {
            return 'NB';
        }

        // Queer (no gender stated)
        if (preg_match('/\bqueer\b/', $check)) {
            return 'Q';
        }

        // Gender Fluid
        if (preg_match('/\bfluid\b/', $check)) {
            return 'GF';
        }

        // Gender, yes? what does that even mean?
        if ($check == 'yes') {
            return '';
        }

        // Can't determine - return the value
        return $gender ?? '';
    }

    /**
     * Split the FKA into an array
     *
     * @param bool $filter
     * @return array
     */

    public function formerlyKnownAsArray(bool $filter = false): array
    {
        return self::splitCommas($this->formerly_known_as, $filter);
    }

    /**
     * Split the Known PNVs into an array
     *
     * @return array
     */

    public function knownPnvsArray(): array
    {
        return self::splitCommas($this->known_pnvs);
    }

    /**
     * Split the Known Rangers into an array
     *
     * @return array
     */

    public function knownRangersArray(): array
    {
        return self::splitCommas($this->known_rangers);
    }

    /**
     * Has the person reviewed their personal information?
     *
     * @return bool
     */
    public function hasReviewedPi(): bool
    {
        return ($this->pi_reviewed_for_dashboard_at
            && $this->pi_reviewed_for_dashboard_at->year == current_year());
    }

    /**
     * Split a string into an array and filter out callsign indicators
     * (i.e., remove year, bonk indicator, and non-ranger suffixes)
     *
     * @param ?string $str
     * @param bool $filter
     * @return array
     */

    public static function splitCommas(?string $str, bool $filter = false): array
    {
        if (empty($str)) {
            return [];
        }

        $names = preg_split('/\s*,\s*/', trim($str));
        if (!$filter) {
            return $names;
        }

        return array_values(array_filter($names, function ($name) {
            return !preg_match('/\d{2,4}[B]?(\(NR\))?$/', $name);
        }));
    }

    /**
     * Set pronouns_custom field to a string value or empty string
     *
     * @param $value
     * @return void
     */

    public function setPronounsCustomAttribute($value)
    {
        $this->attributes['pronouns_custom'] = $value ?? '';
    }

    /**
     * Set pronouns field to a string value or empty string
     *
     * @param $value
     * @return void
     */

    public function setPronounsAttribute($value)
    {
        $this->attributes['pronouns'] = $value ?? '';
    }

    /**
     * Spell out all the numbers in a string.
     * e.g., 3pio -> threepio, hubcap92 -> hubcapninetytwo
     *
     * @param string $word
     * @return string
     */

    public static function spellOutNumbers(string $word): string
    {
        $formatter = new NumberFormatter("en", NumberFormatter::SPELLOUT);
        return preg_replace_callback('/\d+/', fn($number) => $formatter->format((int)$number[0]), $word);
    }
}
