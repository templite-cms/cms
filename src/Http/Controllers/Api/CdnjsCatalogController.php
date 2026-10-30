<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Services\Cdnjs\CdnjsClient;

class CdnjsCatalogController extends Controller
{
    /**
     * Имя пакета и версия уходят в URL каталога, поэтому набор символов
     * ограничен явно: слэши и точки-точки внутрь пути не попадут.
     *
     * Первый символ обязан быть буквой или цифрой. Имя из одних точек регулярку
     * прошло бы, а curl схлопнул бы «/libraries/.» до «/libraries/» ещё до
     * отправки — и CMS на каждый такой запрос тянула бы весь каталог (754 КБ).
     * Шаблон сверен с полным списком cdnjs: из 4615 имён не проходит ни одно.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';
    public const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$/';

    public function __construct(private readonly CdnjsClient $cdnjs)
    {
    }

    /** @OA\Get(path="/libraries/catalog/search", summary="Поиск пакетов в cdnjs", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string")), @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")), @OA\Response(response=200, description="Найденные пакеты"), @OA\Response(response=503, description="Каталог недоступен")) */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|max:100',
            'limit' => 'integer|min:1|max:50',
        ]);

        $result = $this->cdnjs->search($data['q'], $data['limit'] ?? 20);

        if (!($result['ok'] ?? false)) {
            return $this->error('Каталог cdnjs сейчас недоступен. Добавьте файлы вручную или повторите позже.', 503);
        }

        return $this->success($result['results']);
    }

    /** @OA\Get(path="/libraries/catalog/{name}", summary="Версии пакета", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="name", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Версии и главный файл"), @OA\Response(response=404, description="Пакет не найден")) */
    public function package(string $name): JsonResponse
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return $this->error('Недопустимое имя пакета.', 422);
        }

        $result = $this->cdnjs->package($name);

        if (!($result['ok'] ?? false)) {
            // Лежащий каталог — это «повторите позже», а не «исправьте название»:
            // под одним 404 пользователь пошёл бы искать опечатку в верном имени
            return ($result['error'] ?? '') === 'catalog_unavailable'
                ? $this->error('Каталог cdnjs сейчас недоступен. Добавьте файлы вручную или повторите позже.', 503)
                : $this->error('Пакет не найден в каталоге cdnjs.', 404);
        }

        return $this->success([
            'name' => $result['name'],
            'filename' => $result['filename'],
            'latest' => $result['latest'],
            'versions' => $result['versions'],
        ]);
    }

    /** @OA\Get(path="/libraries/catalog/{name}/{version}", summary="Файлы версии пакета", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="name", in="path", required=true, @OA\Schema(type="string")), @OA\Parameter(name="version", in="path", required=true, @OA\Schema(type="string")), @OA\Response(response=200, description="Файлы с URL, SRI и флагом recommended"), @OA\Response(response=404, description="Версия не найдена")) */
    public function files(string $name, string $version): JsonResponse
    {
        if (!preg_match(self::NAME_PATTERN, $name) || !preg_match(self::VERSION_PATTERN, $version)) {
            return $this->error('Недопустимое имя пакета или версия.', 422);
        }

        $result = $this->cdnjs->files($name, $version);

        if (!($result['ok'] ?? false)) {
            $error = $result['error'] ?? '';

            if ($error === 'catalog_unavailable') {
                return $this->error('Каталог cdnjs сейчас недоступен. Добавьте файлы вручную или повторите позже.', 503);
            }

            // Разводим отсутствующий пакет и отсутствующую версию: иначе
            // ассистент на опечатку в имени начнёт перебирать версии
            return $error === 'package_not_found'
                ? $this->error('Пакет не найден в каталоге cdnjs.', 404)
                : $this->error('Версия не найдена в каталоге cdnjs.', 404);
        }

        return $this->success([
            'name' => $result['name'],
            'version' => $result['version'],
            // total — сколько файлов в версии до фильтрации по расширению.
            // Без него пустой список необъясним: пакеты вроде simple-icons
            // состоят из тысяч картинок, и после фильтра не остаётся ничего
            'total' => $result['total'],
            'files' => $result['files'],
        ]);
    }
}
