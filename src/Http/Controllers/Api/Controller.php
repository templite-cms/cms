<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Models\ManagerLog;

/**
 * @OA\Info(
 *     title="Templite CMS API",
 *     version="1.0.0",
 *     description="REST API для Templite CMS -- блочной CMS на Laravel 11",
 *     @OA\Contact(email="support@templite.ru")
 * )
 *
 * @OA\Server(url="/api/cms", description="CMS API")
 *
 * @OA\SecurityScheme(
 *     securityScheme="sanctumAuth",
 *     type="apiKey",
 *     in="cookie",
 *     name="templite_session",
 *     description="Сессионная аутентификация. Отправляйте cookie после успешного login."
 * )
 *
 * @OA\Tag(name="Auth", description="Аутентификация")
 * @OA\Tag(name="Pages", description="Страницы")
 * @OA\Tag(name="Page Types", description="Типы страниц")
 * @OA\Tag(name="Page Type Attributes", description="Атрибуты типов страниц")
 * @OA\Tag(name="Page Blocks", description="Блоки страниц")
 * @OA\Tag(name="Blocks", description="Блоки")
 * @OA\Tag(name="Block Types", description="Типы блоков")
 * @OA\Tag(name="Block Fields", description="Поля блоков")
 * @OA\Tag(name="Block Tabs & Sections", description="Вкладки и секции блоков")
 * @OA\Tag(name="Block Code", description="Код блоков")
 * @OA\Tag(name="Actions", description="Actions")
 * @OA\Tag(name="Block Actions", description="Привязка Actions к блокам")
 * @OA\Tag(name="Components", description="Blade-компоненты")
 * @OA\Tag(name="Templates", description="Шаблоны страниц")
 * @OA\Tag(name="Template Blocks", description="Блоки шаблонов")
 * @OA\Tag(name="Menus", description="Меню")
 * @OA\Tag(name="Menu Items", description="Пункты меню")
 * @OA\Tag(name="Global Settings", description="Глобальные настройки")
 * @OA\Tag(name="Media", description="Медиафайлы")
 * @OA\Tag(name="Media Folders", description="Папки медиафайлов")
 * @OA\Tag(name="Managers", description="Менеджеры")
 * @OA\Tag(name="Manager Types", description="Типы менеджеров")
 * @OA\Tag(name="Logs", description="Логи действий")
 * @OA\Tag(name="Libraries", description="Библиотеки")
 * @OA\Tag(name="CoreSettings / Queues", description="Мониторинг очередей")
 * @OA\Tag(name="CoreSettings / Schedule", description="Мониторинг расписания")
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Успешный ответ.
     */
    protected function success(mixed $data = null, string $message = 'OK', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Ответ с ошибкой.
     */
    protected function error(string $message = 'Error', int $code = 400, mixed $errors = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    protected function logAction(string $action, string $entityType, ?int $entityId = null, ?array $data = null): void
    {
        $manager = auth()->user();
        if (!$manager) {
            return;
        }

        $logEntry = [
            'manager_id'  => $manager->id,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'data'        => $data,
            'ip'          => request()->ip(),
        ];

        try {
            ManagerLog::create($logEntry);
        } catch (\Throwable $e) {
            Log::channel('security')->error('Audit log DB write failed', [
                'audit_entry' => $logEntry,
                'exception'   => $e->getMessage(),
            ]);
        }
    }
}
