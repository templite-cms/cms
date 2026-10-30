<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ключ провайдера AI. Принадлежит менеджеру; другим менеджерам владелец
 * выдаёт доступ через cms_ai_key_access.
 *
 * @property int $id
 * @property int $owner_manager_id
 * @property string $title
 * @property string $provider
 * @property string $api_key
 * @property string|null $model
 * @property string|null $image_model
 * @property int|null $max_steps
 * @property int|null $max_images
 */
class AiKey extends Model
{
    public const DEFAULT_MODEL = 'z-ai/glm-5.3-flash';

    public const DEFAULT_IMAGE_MODEL = 'google/gemini-2.5-flash-image';

    public const DEFAULT_MAX_STEPS = 12;

    public const DEFAULT_MAX_IMAGES = 3;

    /** Провайдеры, которые CMS умеет вызывать. Yandex добавится сюда вместе с клиентом. */
    public const PROVIDERS = ['openrouter'];

    protected $table = 'cms_ai_keys';

    protected $fillable = [
        'owner_manager_id',
        'title',
        'provider',
        'api_key',
        'model',
        'image_model',
        'max_steps',
        'max_images',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'max_steps' => 'integer',
        'max_images' => 'integer',
    ];

    /** Ключ наружу не отдаём никогда — только маска. */
    protected $hidden = ['api_key'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'owner_manager_id');
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(Manager::class, 'cms_ai_key_access', 'key_id', 'manager_id')
            ->withTimestamps();
    }

    /**
     * Маска ключа. Если APP_KEY сменился, расшифровать нечем — отдаём пустую
     * строку: страница настроек должна открыться, чтобы ключ можно было ввести заново.
     */
    public function maskedKey(): string
    {
        try {
            $key = (string) $this->api_key;
        } catch (\Throwable) {
            return '';
        }

        return $key === '' ? '' : '***'.substr($key, -4);
    }

    public function chatModel(): string
    {
        return $this->model ?: self::DEFAULT_MODEL;
    }

    public function pictureModel(): string
    {
        return $this->image_model ?: self::DEFAULT_IMAGE_MODEL;
    }

    public function stepLimit(): int
    {
        return $this->max_steps ?: self::DEFAULT_MAX_STEPS;
    }

    public function imageLimit(): int
    {
        return $this->max_images ?: self::DEFAULT_MAX_IMAGES;
    }
}
