<?php

namespace App;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function trackers()
    {
        return $this->hasMany(Tracker::class);
    }

    /**
     * @param string $name
     * @return Tracker
     */
    public function tracker($name)
    {
        return $this->trackers()->where('tracker', $name)->firstOrFail();
    }

    public function servers()
    {
        return $this->belongsToMany(
            Server::class, (new ApiKey())->getTable(),
            'user_id', 'server')
            ->using(ApiKey::class)
            ->withPivot('api_key')
            ->withTimestamps();
    }
}
