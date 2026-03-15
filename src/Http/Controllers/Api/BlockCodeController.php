<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\Block;
use Templite\Cms\Services\BladeSecurityValidator;
use Templite\Cms\Services\BlockRenderer;

class BlockCodeController extends Controller
{
    public function __construct(protected BlockRenderer $blockRenderer) {}

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
        ]);

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
            $styleContent = $data['style'] ?? '';
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

        // Recompile assets for all pages using this block
        app(\Templite\Cms\Services\PageAssetCompiler::class)->recompileForBlock($block->id);

        $this->logAction('update_code', 'block', $block->id, ['name' => $block->name]);

        return $this->success(null, 'Код блока сохранён.');
    }
}
