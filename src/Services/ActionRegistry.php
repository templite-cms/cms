<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Contracts\RegistryInterface;
use Templite\Cms\Models\Action;

class ActionRegistry implements RegistryInterface
{
    /**
     * Реестр actions: [source => [slug => class/path]]
     */
    protected array $registry = [
        'app' => [],
        'storage' => [],
        'vendor' => [],
    ];

    protected array $priority = ['app', 'storage', 'vendor'];

    /**
     * {@inheritdoc}
     */
    public function find(string $slug): mixed
    {
        foreach ($this->priority as $source) {
            if (isset($this->registry[$source][$slug])) {
                return $this->registry[$source][$slug];
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function all(): Collection
    {
        $result = [];

        foreach (array_reverse($this->priority) as $source) {
            foreach ($this->registry[$source] as $slug => $entity) {
                $result[$slug] = $entity;
            }
        }

        return collect($result);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $slug): bool
    {
        return $this->find($slug) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $slug, mixed $entity, string $source): void
    {
        if (!isset($this->registry[$source])) {
            $this->registry[$source] = [];
        }

        $this->registry[$source][$slug] = $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function fromSource(string $source): Collection
    {
        return collect($this->registry[$source] ?? []);
    }

    /**
     * Создать экземпляр action по slug.
     */
    public function resolve(string $slug): ?BlockActionInterface
    {
        $entry = $this->find($slug);

        if ($entry === null) {
            return null;
        }

        // Если это класс (строка)
        if (is_string($entry) && class_exists($entry)) {
            $instance = app($entry);
            if ($instance instanceof BlockActionInterface) {
                return $instance;
            }
        }

        // Если это массив с path (файл из storage)
        if (is_array($entry) && isset($entry['path'])) {
            return $this->loadFromFile($entry['path']);
        }

        return null;
    }

    /**
     * Полное имя класса, объявленного в коде action, либо null.
     *
     * Разбор тот же, что в Api\ActionController::extractClassName(): файл
     * проходит валидатор, а тот допускает ровно один именованный класс и
     * запрещает анонимные. Промах регулярки не опаснее прежнего поведения —
     * управление просто уходит в require.
     */
    public static function declaredClass(string $code): ?string
    {
        if (!preg_match('/class\s+(\w+)/', $code, $m)) {
            return null;
        }

        return preg_match('/namespace\s+([\w\\\\]+)\s*;/', $code, $ns)
            ? $ns[1] . '\\' . $m[1]
            : $m[1];
    }

    /**
     * Какой код уже выполнился в этом процессе: путь файла => хэш содержимого.
     *
     * Нужен, чтобы отличить «класс загружен из того же кода, что лежит на
     * диске» от «файл переписали после загрузки». Второе означает, что
     * выполнится прежняя версия класса — переобъявить его PHP не умеет.
     *
     * @var array<string,string>
     */
    protected static array $loadedCode = [];

    /**
     * Запомнить код, который сейчас будет выполнен.
     *
     * Запись только первая: класс объявляет именно она, а последующие правки
     * файла на уже объявленный класс не влияют.
     */
    public static function rememberLoadedCode(string $path, string $code): void
    {
        $key = realpath($path) ?: $path;

        static::$loadedCode[$key] ??= hash('sha256', $code);
    }

    /** Хэш кода, из которого класс этого файла был объявлен, либо null. */
    public static function loadedCodeHash(string $path): ?string
    {
        return static::$loadedCode[realpath($path) ?: $path] ?? null;
    }

    /**
     * Путь к файлу, в котором объявлен класс, либо null.
     *
     * ReflectionClass::getFileName() возвращает false для классов без файла
     * (внутренние расширения, eval) — такой случай тоже сводим к null,
     * чтобы вызывающий код не спутал «файл неизвестен» с «файл совпадает».
     */
    protected function fileOfClass(string $class): ?string
    {
        try {
            $file = (new \ReflectionClass($class))->getFileName();
        } catch (\ReflectionException $e) {
            return null;
        }

        return $file !== false ? $file : null;
    }

    /**
     * Загрузить action из файла с проверкой целостности и валидацией кода.
     *
     * 1. Проверяет SHA-256 хэш файла против сохранённого в БД
     * 2. Валидирует код через ActionCodeValidator (токенизатор)
     * 3. Логирует нарушения целостности
     */
    protected function loadFromFile(string $path): ?BlockActionInterface
    {
        if (!file_exists($path)) {
            return null;
        }

        $slug = pathinfo($path, PATHINFO_FILENAME);

        // Проверка целостности: хэш файла должен совпадать с сохранённым
        $action = Action::where('slug', $slug)->first();
        if ($action && $action->code_hash) {
            $validator = app(ActionCodeValidator::class);
            $currentHash = $validator->hashFile($path);

            if (!hash_equals($action->code_hash, $currentHash)) {
                $logData = [
                    'slug' => $slug,
                    'path' => $path,
                    'expected_hash' => $action->code_hash,
                    'actual_hash' => $currentHash,
                ];

                try {
                    Log::channel('security')->critical('Action file integrity violation', $logData);
                } catch (\Throwable $e) {
                    Log::critical('Action file integrity violation', $logData);
                }

                // Файл был изменён вне CMS — отказываемся загружать
                return null;
            }
        }

        // Валидация кода через токенизатор перед загрузкой
        $code = file_get_contents($path);
        if ($code === false) {
            return null;
        }

        $validator = app(ActionCodeValidator::class);
        $errors = $validator->validate($code);

        if (!empty($errors)) {
            $logData = [
                'slug' => $slug,
                'path' => $path,
                'errors' => $errors,
            ];

            try {
                Log::channel('security')->warning('Action file failed validation', $logData);
            } catch (\Throwable $e) {
                Log::warning('Action file failed validation', $logData);
            }
            return null;
        }

        // PHP не выгружает объявленные классы, а require выполняет файл заново.
        // Код этого action мог уже загрузиться в текущем процессе: парсер
        // сигнатуры при сохранении делает require_once, а ассистент правит и
        // тестирует action в одном запросе. Второе объявление того же класса —
        // фатальная ошибка, которую не поймать try/catch: она убила бы SSE-поток
        // вместе с чатом. В админке этого не видно — там шаги идут разными
        // HTTP-запросами.
        //
        // Имя класса само по себе ничего не говорит о том, из какого файла
        // оно объявлено: stubs/action.stub у всех actions по умолчанию даёт
        // одинаковые namespace и класс, и уникальность имени нигде не
        // проверяется. Поэтому объявленность класса — не повод сразу его
        // инстанцировать: нужно убедиться, что объявил его именно этот файл.
        $declaredClass = static::declaredClass($code);

        if ($declaredClass !== null && class_exists($declaredClass, false)) {
            $declaredIn = $this->fileOfClass($declaredClass);

            // Класс уже объявлен этим же файлом — повторное выполнение не
            // нужно и невозможно: require объявил бы его второй раз и убил
            // бы процесс фатальной ошибкой.
            if ($declaredIn !== null && $declaredIn === realpath($path)) {
                $instance = app($declaredClass);

                return $instance instanceof BlockActionInterface ? $instance : null;
            }

            // Имя класса занято другим файлом — коллизия между двумя
            // actions. Выполнить require нельзя (фатал «Cannot declare
            // class»), инстанцировать чужой класс тоже нельзя — action
            // молча заработал бы по чужой логике. Отказываемся и логируем.
            $logData = [
                'slug' => $slug,
                'path' => $path,
                'class' => $declaredClass,
                'declared_in' => $declaredIn,
            ];

            try {
                Log::channel('security')->warning('Action class name collision', $logData);
            } catch (\Throwable $e) {
                Log::warning('Action class name collision', $logData);
            }

            return null;
        }

        static::rememberLoadedCode($path, $code);

        $declaredBefore = get_declared_classes();
        $class = require $path;

        // Формат 1: файл возвращает экземпляр (return new class implements ... {})
        if ($class instanceof BlockActionInterface) {
            return $class;
        }

        // Формат 2: файл объявляет именованный класс (как в stubs/action.stub:
        // namespace App\Actions; class MyAction implements BlockActionInterface {}).
        // require такого файла возвращает int(1), поэтому ищем класс,
        // объявленный этим файлом, и инстанцируем его через контейнер.
        foreach (array_diff(get_declared_classes(), $declaredBefore) as $declared) {
            if (is_subclass_of($declared, BlockActionInterface::class)) {
                return app($declared);
            }
        }

        return null;
    }

    /**
     * Сканировать actions из app/Actions.
     */
    public function scanAppActions(): void
    {
        $path = app_path('Actions');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $slug = $this->toSlug($slug);

            // Определяем полное имя класса
            $className = 'App\\Actions\\' . pathinfo($file, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $this->register($slug, $className, 'app');
            }
        }
    }

    /**
     * Сканировать actions из storage/cms/actions.
     */
    public function scanStorageActions(): void
    {
        $path = storage_path('cms/actions');

        if (!is_dir($path)) {
            return;
        }

        $files = glob($path . '/*.php');

        foreach ($files as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $this->register($slug, ['path' => $file, 'source' => 'storage'], 'storage');
        }
    }

    /**
     * Полное сканирование всех источников.
     */
    public function scan(): void
    {
        $this->scanAppActions();
        $this->scanStorageActions();
    }

    /**
     * Конвертировать CamelCase имя в slug.
     */
    protected function toSlug(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
    }
}
