<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

//#[Fillable(['name', 'email', 'password'])]
//#[Hidden(['password', 'remember_token'])]
class User extends Model implements AuthenticatableContract, JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Authenticatable, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    protected $fillable = [
        'name',
        'email',
        'password',
        'account',
        'role',
        'email_verified_at'
    ];
    protected $hidden = [
        'password',
        'remember_token',
    ];
    public function device()
    {
        return $this->hasMany(Device::class);
    }
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * 检查用户是否为管理员
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
