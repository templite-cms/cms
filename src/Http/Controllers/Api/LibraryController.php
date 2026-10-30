<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Templite\Cms\Exceptions\LibraryFileWriteException;
use Templite\Cms\Helpers\StringHelper;
use Templite\Cms\Http\Resources\LibraryResource;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\LibraryFile;
use Templite\Cms\Services\Cdnjs\CatalogDownloader;
use Templite\Cms\Services\Cdnjs\CdnjsClient;
use Templite\Cms\Services\LibraryFileSync;
use Templite\Cms\Services\PageAssetCompiler;

class LibraryController extends Controller
{
    /**
     * Допустимые MIME-типы для файлов библиотек.
     * Laravel-правило mimes для .js/.css ненадёжно (часто отдаёт text/plain),
     * поэтому расширение и MIME проверяются вручную.
     */
    private const JS_ALLOWED_MIMES = ['application/javascript', 'text/javascript', 'application/x-javascript', 'text/plain'];
    private const CSS_ALLOWED_MIMES = ['text/css', 'text/plain'];

    /** @OA\Get(path="/libraries", summary="Список библиотек", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="active_only", in="query", @OA\Schema(type="boolean")), @OA\Parameter(name="search", in="query", @OA\Schema(type="string")), @OA\Response(response=200, description="Список библиотек")) */
    public function index(Request $request): JsonResponse
    {
        $query = Library::withCount(['blocks', 'templatePages', 'files']);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->has('search') && $request->search !== '') {
            $escaped = StringHelper::escapeLike($request->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', "%{$escaped}%")
                  ->orWhere('slug', 'like', "%{$escaped}%")
                  ->orWhere('description', 'like', "%{$escaped}%");
            });
        }

        $allowedSortFields = ['name', 'slug', 'active', 'sort_order'];
        $sortField = in_array($request->input('sort_field'), $allowedSortFields, true)
            ? $request->input('sort_field')
            : 'sort_order';
        $sortOrder = $request->input('sort_order') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sortField, $sortOrder);
        if ($sortField !== 'name') {
            $query->orderBy('name');
        }

        return $this->success(LibraryResource::collection($query->get()));
    }

    /** @OA\Post(path="/libraries", summary="Создать библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\RequestBody(required=true, @OA\JsonContent(required={"name","slug"}, @OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="sort_order", type="integer"), @OA\Property(property="active", type="boolean"), @OA\Property(property="files", type="array", @OA\Items(type="object")))), @OA\Response(response=201, description="Библиотека создана")) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            // 100, а не 255: slug входит в путь файла дважды
            // (cms/libraries/{slug}/{slug}-XXXXXXXX.js), и при длине под 255
            // путь не влезает в library_files.path varchar(255). Граница
            // продиктована бюджетом длины пути, а не самим slug
            'slug' => ['required', 'string', 'max:100', 'unique:libraries', 'regex:/^[a-z][a-z0-9\-]*$/'],
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ], $this->fileRules()));

        $files = $this->orderedFiles($data);
        unset($data['files']);

        if ($files !== null) {
            $this->validateFiles($files, null);
        }

        $library = Library::create($data);

        if ($files !== null) {
            // POST атомарен при любой ошибке: полупустая библиотека заняла бы
            // slug, и повторная отправка той же формы упёрлась бы в unique.
            // Транзакция здесь не годится — файлы на диске она не откатит
            // и дала бы ложную гарантию, поэтому убираем за собой вручную
            try {
                app(LibraryFileSync::class)->sync($library, $files);
            } catch (LibraryFileWriteException $e) {
                // Отказ записи на диск — единственное исключение, чей текст
                // безопасно показать пользователю
                $this->rollbackLibrary($library);

                return $this->error($e->getMessage(), 500);
            } catch (\Throwable $e) {
                // Любая другая ошибка: убираем за собой, но ответ формирует
                // штатный обработчик. Своими руками текст отдавать нельзя —
                // QueryException наследуется от RuntimeException, и наружу
                // утёк бы SQL с параметрами подключения даже при APP_DEBUG=false.
                // Рендерер Laravel заодно запишет причину в лог
                $this->rollbackLibrary($library);

                throw $e;
            }
        }

        return $this->success(
            new LibraryResource($library->fresh()->load('files')->loadCount(['blocks', 'templatePages', 'files'])),
            'Библиотека создана.',
            201
        );
    }

    /** @OA\Get(path="/libraries/{id}", summary="Получить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Данные библиотеки")) */
    public function show(int $id): JsonResponse
    {
        $library = Library::with('files')
            ->withCount(['blocks', 'templatePages', 'files'])
            ->findOrFail($id);

        return $this->success(new LibraryResource($library));
    }

    /** @OA\Put(path="/libraries/{id}", summary="Обновить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="name", type="string"), @OA\Property(property="slug", type="string"), @OA\Property(property="description", type="string"), @OA\Property(property="sort_order", type="integer"), @OA\Property(property="active", type="boolean"), @OA\Property(property="files", type="array", @OA\Items(type="object")))), @OA\Response(response=200, description="Библиотека обновлена")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $library = Library::findOrFail($id);

        $data = $request->validate(array_merge([
            'name' => 'sometimes|string|max:255',
            // 100 — по той же причине, что и в store(): бюджет длины path
            'slug' => ['sometimes', 'string', 'max:100', 'unique:libraries,slug,' . $id, 'regex:/^[a-z][a-z0-9\-]*$/'],
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ], $this->fileRules()));

        $files = $this->orderedFiles($data);
        unset($data['files']);

        if ($files !== null) {
            $this->validateFiles($files, $library);
        }

        $library->update($data);

        $writeFailure = null;

        if ($files !== null) {
            try {
                app(LibraryFileSync::class)->sync($library, $files);
            } catch (LibraryFileWriteException $e) {
                // Ловим только отказ записи на диск — см. комментарий в store().
                // Sync обрывается посреди цикла, поэтому часть списка уже
                // применена — сообщаем именно это, а не «ничего не сохранено».
                // Техническая причина уже в логе: Laravel вызывает report()
                // перед возвратом false из put()
                //
                // Ответ откладываем: пересборка ниже нужна и на этой ветке.
                // finally для неё не годится — исключение из компилятора
                // подменило бы собой исходную ошибку
                $writeFailure = $e->getMessage() . ' Часть файлов могла остаться несохранённой.';
            }
        }

        // Пересобираем в обоих исходах: при отказе часть списка уже применена,
        // и без этого страницы остались бы с ассетами прежнего состояния
        app(PageAssetCompiler::class)->recompileForLibrary($library->id);

        if ($writeFailure !== null) {
            return $this->error($writeFailure, 500);
        }

        return $this->success(
            new LibraryResource($library->fresh()->load('files')->loadCount(['blocks', 'templatePages', 'files'])),
            'Библиотека обновлена.'
        );
    }

    /** @OA\Delete(path="/libraries/{id}", summary="Удалить библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Библиотека удалена"), @OA\Response(response=422, description="Библиотека используется")) */
    public function destroy(int $id): JsonResponse
    {
        $library = Library::withCount(['blocks', 'templatePages'])->findOrFail($id);

        if ($library->blocks_count > 0 || $library->template_pages_count > 0) {
            return $this->error(
                'Невозможно удалить библиотеку, так как она используется в блоках или шаблонах.',
                422
            );
        }

        // Записи library_files уходят каскадом
        $this->deleteLibraryFiles($library);
        $library->delete();

        return $this->success(null, 'Библиотека удалена.');
    }

    /** @OA\Post(path="/libraries/{id}/catalog-files", summary="Скачать файлы пакета cdnjs в библиотеку", tags={"Libraries"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(required={"package","version","files"}, @OA\Property(property="package", type="string"), @OA\Property(property="version", type="string"), @OA\Property(property="files", type="array", @OA\Items(type="string")))), @OA\Response(response=200, description="Файлы добавлены"), @OA\Response(response=503, description="Каталог недоступен")) */
    public function catalogFiles(Request $request, int $id): JsonResponse
    {
        $library = Library::findOrFail($id);

        $data = $request->validate([
            'package' => ['required', 'string', 'regex:' . CdnjsCatalogController::NAME_PATTERN],
            'version' => ['required', 'string', 'regex:' . CdnjsCatalogController::VERSION_PATTERN],
            'files' => 'required|array|min:1|max:' . CatalogDownloader::MAX_FILES,
            'files.*' => 'required|string|max:255',
        ]);

        $catalog = app(CdnjsClient::class)->files($data['package'], $data['version']);

        if (!($catalog['ok'] ?? false)) {
            // Лежащий каталог — это «повторите позже», а не «исправьте название»:
            // под одним кодом пользователь с опечаткой в имени ждал бы впустую
            return ($catalog['error'] ?? '') === 'catalog_unavailable'
                ? $this->error('Каталог cdnjs сейчас недоступен. Повторите позже.', 503)
                : $this->error('Пакет или версия не найдены в каталоге cdnjs.', 404);
        }

        try {
            // Сам цикл скачивания — в CatalogDownloader: тот же код обслуживает
            // инструмент ассистента, и разойтись они больше не могут
            $result = app(CatalogDownloader::class)
                ->download($library, $data['package'], $data['version'], $data['files']);
        } catch (LibraryFileWriteException $e) {
            return $this->error($e->getMessage(), 500);
        }

        // Пустой результат — это отказ, а не успех с пустым списком:
        // иначе фронт по флагу success покажет зелёное уведомление
        // поверх того, что ничего не добавилось
        if (!$result['added']) {
            return $this->error(
                'Ни один файл добавить не удалось: ' . implode('; ', array_map(
                    fn ($f) => "{$f['file']} — {$f['reason']}",
                    $result['failed']
                )),
                422
            );
        }

        app(PageAssetCompiler::class)->recompileForLibrary($library->id);

        return $this->success(
            [
                'library' => new LibraryResource($library->fresh()->load('files')->loadCount(['blocks', 'templatePages', 'files'])),
                'added' => $result['added'],
                'failed' => $result['failed'],
            ],
            $result['failed']
                ? 'Часть файлов добавить не удалось.'
                : 'Файлы добавлены.'
        );
    }

    /**
     * Откат неудавшегося store(): стираем файлы с диска и саму библиотеку.
     * Записи library_files уходят каскадом следом за ней.
     *
     * Только для store(). В update() библиотека существовала до запроса,
     * и удалять её из-за ошибки нельзя.
     */
    private function rollbackLibrary(Library $library): void
    {
        $this->deleteLibraryFiles($library);
        $library->delete();
    }

    /**
     * Удаляет локальные файлы библиотеки с диска — строго по фактическим
     * путям её записей, и ничего сверх того.
     *
     * Каталог cms/libraries/{slug} целиком не сносится намеренно: slug
     * переиспользуем. Библиотеку переименовали (файлы остались в старом
     * каталоге), под освободившимся slug завели новую — и удаление новой
     * снесло бы файлы живой первой. Через откат store() это означало бы,
     * что неудачное создание уничтожает чужие файлы.
     *
     * Плата — пустые каталоги от переименованных библиотек остаются на диске.
     *
     * load(), а не loadMissing(): связь протухает. sync() создаёт записи
     * через $library->files()->create(), в загруженную коллекцию они не
     * попадают, а исключение обрывает цикл до unsetRelation() — loadMissing()
     * увидел бы устаревший список и не нашёл только что записанные файлы.
     */
    private function deleteLibraryFiles(Library $library): void
    {
        $library->load('files');

        foreach ($library->files as $file) {
            if ($file->source === 'local' && filled($file->path) && Storage::disk('public')->exists($file->path)) {
                Storage::disk('public')->delete($file->path);
            }
        }
    }

    /**
     * Список файлов из проверенных данных, восстановленный в порядке запроса.
     *
     * validate() собирает вложенный массив в порядке обхода правил, а не в
     * порядке запроса: правило files.*.id создаёт ключи только для строк,
     * где id есть, и они оказываются первыми. Строка без id (то есть новая)
     * получает свой ключ позже, при разборе files.*.type. В итоге
     * array_values() ниже по течению — и в validateFiles(), и в
     * LibraryFileSync — переставляет новую строку в конец: пользователь
     * перетащил файл наверх, сохранил, а порядок подключения не изменился.
     * Ключи здесь — исходные индексы запроса, поэтому ksort() их и чинит.
     */
    private function orderedFiles(array $data): ?array
    {
        $files = $data['files'] ?? null;

        if (is_array($files)) {
            ksort($files);
        }

        return $files;
    }

    /**
     * Правила для массива файлов. Всё, что нельзя выразить правилами,
     * проверяется в validateFiles().
     */
    private function fileRules(): array
    {
        return [
            // nullable: для MCP files=null читается как «не трогать файлы» —
            // ровно как отсутствие ключа. Ветка $files !== null делает такой
            // запрос no-op
            'files' => 'sometimes|nullable|array|max:50',
            'files.*.id' => 'nullable|integer',
            'files.*.type' => 'required|in:' . implode(',', LibraryFile::TYPES),
            'files.*.source' => 'required|in:' . implode(',', LibraryFile::SOURCES),
            'files.*.url' => 'nullable|url|max:500',
            // Потолок общий со скачиванием из каталога и живёт в CatalogDownloader:
            // иначе через каталог проходило бы больше, чем руками
            'files.*.upload' => 'nullable|file|max:' . CatalogDownloader::MAX_FILE_KB,
            'files.*.version' => 'nullable|string|max:50',
            'files.*.loading' => 'nullable|in:' . implode(',', LibraryFile::LOADINGS),
            'files.*.media' => 'nullable|string|max:50',
        ];
    }

    /**
     * Связь source ↔ url/upload, совместимость флага с типом,
     * принадлежность id библиотеке, расширение и MIME загружаемого файла.
     *
     * @throws ValidationException
     */
    private function validateFiles(array $files, ?Library $library): void
    {
        $library?->loadMissing('files');
        $existingIds = $library ? $library->files->pluck('id')->all() : [];

        $errors = [];

        // Повторный id в списке LibraryFileSync трактует как новую запись.
        // Логика здесь обязана совпадать с ним дословно: иначе валидатор
        // нашёл бы path у существующей записи, пропустил бы local без upload,
        // а сервис создал бы новую строку с path = NULL
        $seenIds = [];

        foreach (array_values($files) as $i => $file) {
            $type = $file['type'] ?? null;
            $source = $file['source'] ?? null;
            $id = isset($file['id']) ? (int) $file['id'] : null;
            $upload = $file['upload'] ?? null;

            if ($id !== null && !in_array($id, $existingIds, true)) {
                $errors["files.{$i}.id"] = ['Файл не принадлежит этой библиотеке.'];
                continue;
            }

            // Тот же отбор, что и в LibraryFileSync: id учитывается, только
            // если он ненулевой и встречается впервые
            $existing = $id && !in_array($id, $seenIds, true) && $library
                ? $library->files->firstWhere('id', $id)
                : null;

            if ($id) {
                $seenIds[] = $id;
            }

            if ($type === 'js' && filled($file['media'] ?? null)) {
                $errors["files.{$i}.media"] = ['Параметр media допустим только для CSS-файлов.'];
            }

            if ($type === 'css' && filled($file['loading'] ?? null)) {
                $errors["files.{$i}.loading"] = ['Флаг подключения допустим только для JS-файлов.'];
            }

            if ($source === 'cdn') {
                if (blank($file['url'] ?? null)) {
                    $errors["files.{$i}.url"] = ['Укажите ссылку на файл.'];
                }
                continue;
            }

            if (!$upload instanceof UploadedFile) {
                if (blank($existing?->path)) {
                    $errors["files.{$i}.upload"] = ['Выберите файл для загрузки.'];
                } elseif ($existing->type !== $type) {
                    // Тип сменили, а файл оставили прежний: запись css/local
                    // указывала бы на .js, и на странице появился бы
                    // <link rel="stylesheet" href="...js">
                    $errors["files.{$i}.upload"] = ['При смене типа файла загрузите его заново.'];
                }
                continue;
            }

            $expectedExtension = $type === 'js' ? 'js' : 'css';
            if (strtolower($upload->getClientOriginalExtension()) !== $expectedExtension) {
                $errors["files.{$i}.upload"] = ["Файл должен иметь расширение .{$expectedExtension}"];
                continue;
            }

            $allowedMimes = $type === 'js' ? self::JS_ALLOWED_MIMES : self::CSS_ALLOWED_MIMES;
            if (!in_array($upload->getMimeType(), $allowedMimes, true)) {
                $errors["files.{$i}.upload"] = ['Недопустимый MIME-тип файла.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
