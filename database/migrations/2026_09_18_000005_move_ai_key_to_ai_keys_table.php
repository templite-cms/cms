<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** Настройки, которые с этого момента живут на ключе, а не на сайте. */
    private const MOVED = ['ai.api_key', 'ai.model', 'ai.image_model', 'ai.max_steps', 'ai.max_images'];

    /**
     * Ключ был общим на всю установку. Отдаём его первому админу: он и так
     * им распоряжался, а доступ остальным он выдаст руками.
     */
    public function up(): void
    {
        $config = DB::table('cms_config')->whereIn('key', self::MOVED)->pluck('value', 'key');
        $encrypted = $config['ai.api_key'] ?? null;

        if ($encrypted) {
            $ownerId = $this->firstAdminId();

            if ($ownerId) {
                try {
                    $plain = Crypt::decryptString($encrypted);

                    $keyId = DB::table('cms_ai_keys')->insertGetId([
                        'owner_manager_id' => $ownerId,
                        'title' => 'Основной ключ',
                        'provider' => 'openrouter',
                        'api_key' => Crypt::encryptString($plain),
                        'model' => $config['ai.model'] ?? null,
                        'image_model' => $config['ai.image_model'] ?? null,
                        'max_steps' => isset($config['ai.max_steps']) ? (int) $config['ai.max_steps'] : null,
                        'max_images' => isset($config['ai.max_images']) ? (int) $config['ai.max_images'] : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('managers')->where('id', $ownerId)->update(['active_ai_key_id' => $keyId]);
                } catch (\Throwable $e) {
                    // Текст исключения не пишем: QueryException подставляет в него
                    // биндинги, а среди них зашифрованный ключ.
                    Log::warning('AI key migration skipped: '.$e::class);
                }
            } else {
                Log::warning('AI key migration skipped: no managers found');
            }
        }

        DB::table('cms_config')->whereIn('key', self::MOVED)->delete();

        // Настройки лежат в кэше: без сброса CMS ещё сутки отдавала бы
        // удалённый ключ из cms_config.
        \Templite\Cms\Models\CmsConfig::clearCache();
    }

    /** Ключ обратно в cms_config не возвращаем: откат оставляет CMS без ключа. */
    public function down(): void
    {
    }

    private function firstAdminId(): ?int
    {
        $admin = DB::table('managers')
            ->leftJoin('manager_types', 'managers.type_id', '=', 'manager_types.id')
            ->orderBy('managers.id')
            ->get(['managers.id', 'managers.use_personal_permissions', 'managers.personal_permissions', 'manager_types.permissions'])
            ->first(function ($row) {
                $personal = json_decode((string) $row->personal_permissions, true) ?: [];
                $type = json_decode((string) $row->permissions, true) ?: [];

                // Та же логика, что в AuthManager: пустые персональные права
                // означают «берём права типа», иначе админа можно не найти.
                $permissions = ($row->use_personal_permissions && $personal) ? $personal : $type;

                return in_array('*', $permissions, true);
            });

        return $admin?->id ?? DB::table('managers')->orderBy('id')->value('id');
    }
};
