<?php

namespace Templite\Cms\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    protected $table = 'cms_users';

    protected $fillable = [
        'user_type_id',
        'name',
        'email',
        'password',
        'avatar_id',
        'data',
        'settings',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'data' => 'json',
        'settings' => 'json',
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected $appends = ['avatar_url'];

    // --- Relationships ---

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_id');
    }

    // --- Accessors ---

    /**
     * URL аватара: загруженный файл или дефолтный.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_id) {
            if ($this->relationLoaded('avatar') && $this->avatar) {
                return $this->avatar->disk === 'local'
                    ? '/api/cms/media/serve/' . $this->avatar->id
                    : $this->avatar->url;
            }

            return '/api/cms/media/serve/' . $this->avatar_id;
        }

        return null;
    }

    // --- Methods ---

    /**
     * Получить значение кастомного поля из data JSON.
     */
    public function getFieldValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Установить значение кастомного поля в data JSON.
     */
    public function setFieldValue(string $key, mixed $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->data = $data;
    }

    /**
     * Получить имя guard'а для этого пользователя.
     */
    public function getGuardName(): string
    {
        return $this->userType?->guard ?? 'web';
    }
}
