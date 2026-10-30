<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пользователи сайта удалены из ядра: модели User/UserType/UserField, guard'ы,
 * API личного кабинета и тип поля `user`. Исходные миграции таблиц удалены, поэтому
 * на свежей установке таблиц нет и миграция ничего не делает. На существующей базе:
 *
 * 1. Строки personal_access_tokens и block_fields с морфами site_user / site_user_type.
 *    Алиасы сняты из morph map, а enforceMorphMap бросает исключение на неизвестном
 *    типе — такие строки не должны пережить удаление кода.
 * 2. Поля типа `user` из block_fields (поля блоков и шаблонов) и из global_fields
 *    вместе с их значениями в global_field_values. Тип листовой, дочерних полей нет.
 *    ID пользователей, сохранённые в JSON-данных блоков, после этого просто не читаются.
 * 3. Таблицы cms_users, cms_user_fields, cms_user_types — в порядке внешних ключей.
 *
 * Данные не восстанавливаются — down() пустой.
 */
return new class extends Migration {
    private const MORPHS = ['site_user', 'site_user_type'];

    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens') && Schema::hasColumn('personal_access_tokens', 'tokenable_type')) {
            DB::table('personal_access_tokens')->whereIn('tokenable_type', self::MORPHS)->delete();
        }

        if (Schema::hasTable('block_fields')) {
            if (Schema::hasColumn('block_fields', 'fieldable_type')) {
                DB::table('block_fields')->whereIn('fieldable_type', self::MORPHS)->delete();
            }

            DB::table('block_fields')->where('type', 'user')->delete();
        }

        if (Schema::hasTable('global_fields')) {
            $fieldIds = DB::table('global_fields')->where('type', 'user')->pluck('id');

            if ($fieldIds->isNotEmpty()) {
                if (Schema::hasTable('global_field_values')) {
                    DB::table('global_field_values')->whereIn('global_field_id', $fieldIds)->delete();
                }

                DB::table('global_fields')->whereIn('id', $fieldIds)->delete();
            }
        }

        Schema::dropIfExists('cms_users');
        Schema::dropIfExists('cms_user_fields');
        Schema::dropIfExists('cms_user_types');
    }

    public function down(): void
    {
        // Пользователи сайта удалены из ядра — восстанавливать нечего.
    }
};
