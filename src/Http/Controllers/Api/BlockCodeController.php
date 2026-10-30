<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockCodeVersion;
use Templite\Cms\Services\BladeSecurityValidator;
use Templite\Cms\Services\BlockCodeLinter;
use Templite\Cms\Services\BlockCodeVersionService;
use Templite\Cms\Services\BlockRenderer;
use Templite\Cms\Services\ScssTokenFallback;

class BlockCodeController extends Controller
{
    public function __construct(
        protected BlockRenderer $blockRenderer,
        protected BlockCodeVersionService $versions,
        protected ScssTokenFallback $scssFallback,
        protected BlockCodeLinter $linter,
    ) {}

    /** @OA\Get(path="/blocks/{id}/code", summary="Получить код блока", tags={"Block Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Код блока")) */
    public function show(int $id): JsonResponse
    {
        $block = Block::findOrFail($id);
        $path = $this->blockRenderer->resolveBlockPath($block);

        $code = ['template' => '', 'style' => '', 'script' => ''];

        if ($path) {
            if (file_exists($path . '/template.blade.php')) {
                $code['template'] = file_get_contents($path . '/template.blade.php');
            }
            if (file_exists($path . '/style.scss')) {
                $code['style'] = file_get_contents($path . '/style.scss');
            } elseif (file_exists($path . '/style.css')) {
                $code['style'] = file_get_contents($path . '/style.css');
            }
            if (file_exists($path . '/script.js')) {
                $code['script'] = file_get_contents($path . '/script.js');
            }
        }

        return $this->success($code);
    }

    /** @OA\Put(path="/blocks/{id}/code", summary="Сохранить код блока", tags={"Block Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="template", type="string"), @OA\Property(property="style", type="string"), @OA\Property(property="script", type="string"))), @OA\Response(response=200, description="Сохранено")) */
    public function update(Request $request, int $id): JsonResponse
    {
        $block = Block::findOrFail($id);
        $data = $request->validate([
            'template' => 'nullable|string',
            'style' => 'nullable|string',
            'script' => 'nullable|string',
            'version_source' => 'nullable|string|in:manual,ai,restore',
        ]);

        // Снимок текущего состояния, если история пуста, — иначе исходный код потеряется.
        $this->versions->ensureInitialVersion($block);

        // Валидация Blade-шаблона на запрещённые конструкции
        if (isset($data['template']) && $data['template'] !== '') {
            $bladeValidator = new BladeSecurityValidator();
            $violations = $bladeValidator->validate($data['template']);
            if (!empty($violations)) {
                return $this->error(
                    'Код содержит запрещённые конструкции: ' . implode(', ', $violations),
                    422
                );
            }
        }

        // Определяем путь для сохранения (storage/cms/blocks/{slug})
        $path = storage_path('cms/blocks/' . basename($block->slug));
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        if (array_key_exists('template', $data)) {
            file_put_contents($path . '/template.blade.php', $data['template'] ?? '');
        }
        if (array_key_exists('style', $data)) {
            // Токены дизайн-системы получают значения по умолчанию: на публичном
            // сайте они не определены, и блок вышел бы без цветов и фонов.
            $styleContent = $this->scssFallback->apply($data['style'] ?? '');
            if ($styleContent === '') {
                // Удаляем файлы стилей если содержимое пустое
                @unlink($path . '/style.scss');
                @unlink($path . '/style.css');
            } else {
                file_put_contents($path . '/style.scss', $styleContent);
            }
        }
        if (array_key_exists('script', $data)) {
            $scriptContent = $data['script'] ?? '';
            if ($scriptContent === '') {
                @unlink($path . '/script.js');
            } else {
                file_put_contents($path . '/script.js', $scriptContent);
            }
        }

        // Обновляем source и path если блок был из БД
        if ($block->source === 'database') {
            $block->update(['source' => 'database', 'path' => 'storage/cms/blocks/' . basename($block->slug)]);
        }

        // Invalidate block cache for all page instances of this block
        app(\Templite\Cms\Services\CacheManager::class)->invalidateBlockType($block->id);

        // Recompile assets for all pages using this block
        app(\Templite\Cms\Services\PageAssetCompiler::class)->recompileForBlock($block->id);

        $this->logAction('update_code', 'block', $block->id, ['name' => $block->name]);

        // Версия — полный снимок того, что теперь на диске: при частичной правке
        // (например, AI поменял только стиль) непереданные части не теряются.
        $saved = $this->versions->readFrom($path);
        $this->versions->store($block, $saved, $data['version_source'] ?? 'manual');

        // Предупреждения не мешают сохранению — подсказывают, где блок поведёт себя неожиданно
        $warnings = $this->linter->lint(
            $block,
            $saved['template'] ?? '',
            $saved['style'] ?? '',
            $saved['script'] ?? '',
        );

        return $this->success(['warnings' => $warnings], 'Код блока сохранён.');
    }

    /** @OA\Get(path="/blocks/{id}/code/versions", summary="История кода блока", tags={"Block Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Список версий")) */
    public function versions(int $id): JsonResponse
    {
        $block = Block::findOrFail($id);

        $versions = BlockCodeVersion::where('block_id', $block->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get(['id', 'block_id', 'user_id', 'source', 'created_at'])
            ->map(fn (BlockCodeVersion $v) => [
                'id' => $v->id,
                'source' => $v->source,
                'author' => $v->user?->name,
                'created_at' => $v->created_at?->toIso8601String(),
            ]);

        return $this->success($versions);
    }

    /** @OA\Get(path="/blocks/{id}/code/versions/{versionId}", summary="Версия кода блока", tags={"Block Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="versionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Код версии")) */
    public function version(int $id, int $versionId): JsonResponse
    {
        $version = BlockCodeVersion::where('block_id', $id)->findOrFail($versionId);

        return $this->success([
            'id' => $version->id,
            'source' => $version->source,
            'created_at' => $version->created_at?->toIso8601String(),
            'template' => $version->template,
            'style' => $version->style,
            'script' => $version->script,
        ]);
    }

    /** @OA\Post(path="/blocks/{id}/code/versions/{versionId}/restore", summary="Восстановить версию кода", tags={"Block Code"}, security={{"bearerAuth":{}}}, @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")), @OA\Parameter(name="versionId", in="path", required=true, @OA\Schema(type="integer")), @OA\Response(response=200, description="Восстановлено")) */
    public function restore(Request $request, int $id, int $versionId): JsonResponse
    {
        $version = BlockCodeVersion::where('block_id', $id)->findOrFail($versionId);

        // Пишем через тот же update(): он валидирует Blade, чистит кэш и пересобирает ассеты.
        // NULL — часть, не попавшая в старую частичную версию: её не трогаем, иначе
        // восстановление правки одного стиля стёрло бы шаблон.
        $restoreRequest = Request::create("/api/cms/blocks/{$id}/code", 'PUT', array_filter([
            'template' => $version->template,
            'style' => $version->style,
            'script' => $version->script,
        ], fn ($v) => $v !== null) + ['version_source' => 'restore']);
        $restoreRequest->setUserResolver(fn () => $request->user());

        return $this->update($restoreRequest, $id);
    }
}
