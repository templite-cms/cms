<?php

namespace Templite\Cms;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\ManagerType;

/**
 * Логика команды cms:install.
 * Создаёт необходимые директории, публикует конфиги, создаёт суперадмина.
 */
class CmsInstaller
{
    /**
     * Директории, необходимые для работы CMS.
     */
    protected function getDirectories(): array
    {
        return [
            app_path('Blocks'),
            app_path('Actions'),
            app_path('View/Components/Cms'),
            storage_path('cms/blocks'),
            storage_path('cms/actions'),
            storage_path('cms/components'),
            storage_path('cms/templates'),
        ];
    }

    /**
     * Выполнить установку CMS.
     */
    public function install(): array
    {
        $results = [];

        foreach ($this->getDirectories() as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
                $results[] = "Создана директория: {$dir}";
            }
        }

        return $results;
    }

    /**
     * Создать необходимые директории для CMS.
     */
    public function createDirectories(): void
    {
        foreach ($this->getDirectories() as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
    }

    /**
     * Создать суперадмина CMS.
     */
    public function createSuperAdmin(string $login, string $password): Manager
    {
        $type = ManagerType::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Суперадмин', 'permissions' => ['*']]
        );

        return Manager::updateOrCreate(
            ['login' => $login],
            [
                'password' => Hash::make($password),
                'type_id' => $type->id,
                'name' => 'Администратор',
                'is_active' => true,
            ]
        );
    }
}
