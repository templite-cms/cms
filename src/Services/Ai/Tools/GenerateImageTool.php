<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Http\UploadedFile;
use Templite\Cms\Models\AiKey;
use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\OpenRouterClient;
use Templite\Cms\Services\FileService;

class GenerateImageTool implements Tool
{
    /**
     * Счётчик за один запрос пользователя: генерация платная, и модель склонна
     * предлагать несколько вариантов «на выбор». Сбрасывается петлёй агента.
     */
    private static int $generated = 0;

    /** Ключ и модель текущего диалога — их задаёт петля агента перед запуском. */
    private static ?AiKey $key = null;

    private static ?string $model = null;

    public function __construct(private readonly FileService $files)
    {
    }

    public static function resetCounter(): void
    {
        self::$generated = 0;
    }

    public static function useKey(AiKey $key, ?string $model = null): void
    {
        self::$key = $key;
        self::$model = $model;
    }

    /** Ключ не должен пережить запрос: в persistent-воркере им воспользовался бы следующий. */
    public static function clear(): void
    {
        self::$key = null;
        self::$model = null;
        self::$generated = 0;
    }

    public function name(): string
    {
        return 'generate_image';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string', 'description' => 'Подробное описание изображения'],
                'aspect_ratio' => ['type' => 'string', 'description' => 'Например 16:9 для фона, 1:1 для иконки, 4:3'],
                'name' => ['type' => 'string', 'description' => 'Имя файла в библиотеке'],
                'folder_id' => ['type' => 'integer', 'description' => 'Папка медиабиблиотеки'],
            ],
            'required' => ['prompt'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        if (! self::$key) {
            return ['error' => 'no_key', 'message' => 'Ключ AI не выбран.'];
        }

        $limit = self::$key->imageLimit();

        if (self::$generated >= $limit) {
            return [
                'error' => 'generation_limit_reached',
                'message' => "Достигнут лимит генераций за один запрос ({$limit}). Предложи пользователю продолжить новым сообщением.",
            ];
        }

        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($prompt === '') {
            return ['error' => 'empty_prompt'];
        }

        $folderId = isset($args['folder_id']) ? (int) $args['folder_id'] : null;

        if ($folderId && ! FileFolder::whereKey($folderId)->exists()) {
            return ['error' => 'folder_not_found'];
        }

        $payload = array_filter([
            'prompt' => $prompt,
            'aspect_ratio' => $args['aspect_ratio'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $result = OpenRouterClient::forKey(self::$key)->generateImage($payload, self::$model ?: self::$key->pictureModel());
        } catch (\Throwable $e) {
            return ['error' => 'generation_failed', 'message' => $e->getMessage()];
        }

        $binary = base64_decode((string) ($result['b64_json'] ?? ''), true);

        if ($binary === false || $binary === '') {
            return ['error' => 'empty_image'];
        }

        self::$generated++;

        $mime = $result['media_type'] ?? 'image/png';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $tmp = tempnam(sys_get_temp_dir(), 'cms-generated-');
        file_put_contents($tmp, $binary);

        $fileName = ($args['name'] ?? 'generated-'.substr(md5($prompt), 0, 8)).'.'.$extension;
        $file = $this->files->upload(new UploadedFile($tmp, $fileName, $mime, null, true), $folderId);

        // Карантин: пока пользователь не увидел картинку и не подтвердил её,
        // файл не отдаётся в библиотеку и не может попасть в поле блока.
        $file->update([
            'ai_pending' => true,
            'meta' => array_merge($file->meta ?? [], [
                'ai_prompt' => $prompt,
                'ai_aspect_ratio' => $args['aspect_ratio'] ?? null,
                // Подтвердить или удалить картинку вправе только тот, кто её заказал.
                'ai_manager_id' => (auth()->user() ?? auth('manager')->user())?->id,
            ]),
        ]);

        return [
            'file_id' => $file->id,
            'name' => $file->name,
            'status' => 'awaiting_confirmation',
            'message' => 'Изображение показано пользователю и ждёт подтверждения. Не подставляй его в поля и не вызывай update_block_data с этим file_id — сервер отклонит. Заверши ответ и дождись, пока пользователь подтвердит картинку.',
            'generated_in_request' => self::$generated,
            // Карточка с превью и кнопками в чате.
            'image_review' => [
                'file_id' => $file->id,
                'name' => $file->name,
                'url' => $file->url,
                'prompt' => $prompt,
                'aspect_ratio' => $args['aspect_ratio'] ?? null,
            ],
        ];
    }
}
