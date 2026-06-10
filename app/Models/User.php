<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\LoginAttempt;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'ic_number',
        'phone',
        'address',
        'state',
        'team',
        'profile_image_url',
        'status',
        'last_login_at',
        'last_message_digest_at',
        'failed_login_count',
        'locked_at',
        'locked_by',
        'lock_reason',
        'password',
        'emergency_contact',
        'banking_info',
        'statutory_info',
        'medical_info',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_message_digest_at' => 'datetime',
        'locked_at' => 'datetime',
        'password' => 'hashed',
        'emergency_contact' => 'array',
        'banking_info' => 'array',
        'statutory_info' => 'array',
        'medical_info' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    public function payrollClaims(): HasMany
    {
        return $this->hasMany(PayrollClaim::class);
    }

    public function workflowNotifications(): HasMany
    {
        return $this->hasMany(WorkflowNotification::class, 'owner_user_id');
    }
}
