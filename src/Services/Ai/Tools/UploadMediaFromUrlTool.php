<?php

namespace Templite\Cms\Services\Ai\Tools;

use Illuminate\Support\Facades\Validator;
use Templite\Cms\Models\CmsConfig;
use Templite\Cms\Models\FileFolder;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\FileService;
use Templite\Cms\Services\Media\RemoteFileFetcher;

class UploadMediaFromUrlTool implements Tool
{
    public function __construct(
        private readonly RemoteFileFetcher $fetcher,
        private readonly FileService $files,
    ) {
    }

    public function name(): string
    {
        return 'upload_media_from_url';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Прямая ссылка на файл (http/https)'],
                'folder_id' => ['type' => 'integer', 'description' => 'Папка назначения; без неё — корень'],
                'name' => ['type' => 'string', 'description' => 'Имя файла в библиотеке'],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $url = trim((string) ($args['url'] ?? ''));

        if ($url === '') {
            return ['error' => 'invalid_url'];
        }

        $folderId = isset($args['folder_id']) ? (int) $args['folder_id'] : null;

        if ($folderId && ! FileFolder::whereKey($folderId)->exists()) {
            return ['error' => 'folder_not_found'];
        }

        $maxBytes = ((int) config('cms.max_upload_size', 10)) * 1024 * 1024;
        $result = $this->fetcher->fetch($url, $maxBytes);

        if (! ($result['ok'] ?? false)) {
            return [
                'error' => $result['error'] ?? 'download_failed',
                'message' => $result['message'] ?? null,
            ];
        }

        $uploaded = $result['file'];

        // Тип файла проверяем тем же правилом, что и обычную загрузку из админки
        // (MediaController::upload): в конфиге расширения сгруппированы по типам.
        $configValue = CmsConfig::getValue('allowed_file_extensions');

        if ($configValue !== null && $configValue !== '') {
            $extensions = array_values(array_filter(array_map(
                'trim',
                explode(',', strtolower($configValue))
            )));
        } else {
            $extensions = collect(config('cms.allowed_file_types', []))
                ->flatten()->unique()->values()->all();
        }

        if ($extensions) {
            $check = Validator::make(['file' => $uploaded], ['file' => 'mimes:'.implode(',', $extensions)]);

            if ($check->fails()) {
                return ['error' => 'type_not_allowed', 'message' => 'Такой тип файла загружать нельзя.'];
            }
        }

        $file = $this->files->upload($uploaded, $folderId);

        if (! empty($args['name'])) {
            $file->update(['name' => $args['name']]);
        }

        return [
            'file_id' => $file->id,
            'name' => $file->name,
            'type' => $file->type,
            'size' => $file->size,
        ];
    }
}
