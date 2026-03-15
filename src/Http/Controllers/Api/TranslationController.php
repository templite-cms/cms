<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Models\GlobalFieldValueTranslation;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Models\PageBlockTranslation;
use Templite\Cms\Models\PageTranslation;

class TranslationController extends Controller
{
    // =====================================================================
    // Page Translations (Переводы страниц)
    // =====================================================================

    /**
     * Получить перевод страницы для указанного языка.
     *
     * @OA\Get(
     *     path="/pages/{page}/translations/{lang}",
     *     summary="Перевод страницы",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Данные перевода или null")
     * )
     */
    public function getPageTranslation(int $page, string $lang): JsonResponse
    {
        $pageModel = Page::findOrFail($page);

        $translation = PageTranslation::where('page_id', $pageModel->id)
            ->where('lang', $lang)
            ->first();

        return $this->success($translation);
    }

    /**
     * Сохранить перевод страницы для указанного языка.
     *
     * @OA\Put(
     *     path="/pages/{page}/translations/{lang}",
     *     summary="Сохранить перевод страницы",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="title", type="string"),
     *         @OA\Property(property="bread_title", type="string", nullable=true),
     *         @OA\Property(property="seo_data", type="object", nullable=true),
     *         @OA\Property(property="social_data", type="object", nullable=true)
     *     )),
     *     @OA\Response(response=200, description="Перевод сохранён")
     * )
     */
    public function savePageTranslation(Request $request, int $page, string $lang): JsonResponse
    {
        $pageModel = Page::findOrFail($page);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'bread_title' => 'nullable|string|max:255',
            'seo_data' => 'nullable|array',
            'social_data' => 'nullable|array',
        ]);

        $translation = PageTranslation::updateOrCreate(
            ['page_id' => $pageModel->id, 'lang' => $lang],
            $data
        );

        $this->logAction('save_translation', 'page', $pageModel->id, [
            'lang' => $lang,
            'title' => $pageModel->title,
        ]);

        return $this->success($translation, 'Перевод страницы сохранён.');
    }

    // =====================================================================
    // Block Translations (Переводы блоков)
    // =====================================================================

    /**
     * Получить перевод блока на странице для указанного языка.
     *
     * @OA\Get(
     *     path="/page-blocks/{pageBlock}/translations/{lang}",
     *     summary="Перевод блока",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="pageBlock", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Данные перевода или null")
     * )
     */
    public function getBlockTranslation(int $pageBlock, string $lang): JsonResponse
    {
        $pb = PageBlock::findOrFail($pageBlock);

        $translation = PageBlockTranslation::where('page_block_id', $pb->id)
            ->where('lang', $lang)
            ->first();

        return $this->success($translation);
    }

    /**
     * Сохранить перевод блока на странице для указанного языка.
     *
     * @OA\Put(
     *     path="/page-blocks/{pageBlock}/translations/{lang}",
     *     summary="Сохранить перевод блока",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="pageBlock", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="data", type="object")
     *     )),
     *     @OA\Response(response=200, description="Перевод блока сохранён")
     * )
     */
    public function saveBlockTranslation(Request $request, int $pageBlock, string $lang): JsonResponse
    {
        $pb = PageBlock::findOrFail($pageBlock);

        $data = $request->validate([
            'data' => 'required|array',
        ]);

        $translation = PageBlockTranslation::updateOrCreate(
            ['page_block_id' => $pb->id, 'lang' => $lang],
            ['data' => $data['data']]
        );

        $this->logAction('save_translation', 'page_block', $pb->id, [
            'lang' => $lang,
            'page_id' => $pb->page_id,
        ]);

        return $this->success($translation, 'Перевод блока сохранён.');
    }

    /**
     * Скопировать данные блока (default) в перевод.
     *
     * @OA\Post(
     *     path="/page-blocks/{pageBlock}/translations/{lang}/copy-from-default",
     *     summary="Копировать данные блока в перевод",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="pageBlock", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Данные скопированы в перевод")
     * )
     */
    public function copyBlockFromDefault(int $pageBlock, string $lang): JsonResponse
    {
        $pb = PageBlock::findOrFail($pageBlock);

        $translation = PageBlockTranslation::updateOrCreate(
            ['page_block_id' => $pb->id, 'lang' => $lang],
            ['data' => $pb->data ?? []]
        );

        $this->logAction('copy_translation', 'page_block', $pb->id, [
            'lang' => $lang,
            'page_id' => $pb->page_id,
        ]);

        return $this->success($translation, 'Данные блока скопированы в перевод.');
    }

    // =====================================================================
    // Bulk Block Translations for a Page (Массовые переводы блоков страницы)
    // =====================================================================

    /**
     * Получить все переводы блоков страницы для указанного языка.
     *
     * @OA\Get(
     *     path="/pages/{page}/block-translations/{lang}",
     *     summary="Все переводы блоков страницы",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Переводы блоков, ключ — page_block_id")
     * )
     */
    public function getPageBlockTranslations(int $page, string $lang): JsonResponse
    {
        $pageModel = Page::findOrFail($page);

        $pageBlockIds = PageBlock::where('page_id', $pageModel->id)->pluck('id');

        $translations = PageBlockTranslation::whereIn('page_block_id', $pageBlockIds)
            ->where('lang', $lang)
            ->get()
            ->keyBy('page_block_id');

        return $this->success($translations);
    }

    /**
     * Массовое сохранение переводов блоков страницы.
     *
     * @OA\Put(
     *     path="/pages/{page}/block-translations/{lang}",
     *     summary="Массовое сохранение переводов блоков",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="page", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="blocks", type="array", @OA\Items(
     *             @OA\Property(property="page_block_id", type="integer"),
     *             @OA\Property(property="data", type="object")
     *         ))
     *     )),
     *     @OA\Response(response=200, description="Переводы блоков сохранены")
     * )
     */
    public function savePageBlockTranslations(Request $request, int $page, string $lang): JsonResponse
    {
        $pageModel = Page::findOrFail($page);

        $data = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.page_block_id' => 'required|integer|exists:page_blocks,id',
            'blocks.*.data' => 'required|array',
        ]);

        // Verify all page_block_ids belong to this page
        $pageBlockIds = PageBlock::where('page_id', $pageModel->id)->pluck('id')->toArray();

        $saved = [];
        foreach ($data['blocks'] as $item) {
            if (!in_array($item['page_block_id'], $pageBlockIds)) {
                continue;
            }

            $saved[] = PageBlockTranslation::updateOrCreate(
                ['page_block_id' => $item['page_block_id'], 'lang' => $lang],
                ['data' => $item['data']]
            );
        }

        $this->logAction('save_translations_bulk', 'page', $pageModel->id, [
            'lang' => $lang,
            'count' => count($saved),
        ]);

        return $this->success($saved, 'Переводы блоков сохранены.');
    }

    // =====================================================================
    // Global Field Translations (Переводы глобальных полей)
    // =====================================================================

    /**
     * Получить все переводы глобальных полей для указанного языка.
     *
     * @OA\Get(
     *     path="/global-settings/translations/{lang}",
     *     summary="Переводы глобальных полей",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Переводы, ключ — global_field_value_id")
     * )
     */
    public function getGlobalTranslations(string $lang): JsonResponse
    {
        $translations = GlobalFieldValueTranslation::where('lang', $lang)
            ->get()
            ->keyBy('global_field_value_id');

        return $this->success($translations);
    }

    /**
     * Сохранить переводы глобальных полей для указанного языка.
     *
     * @OA\Put(
     *     path="/global-settings/translations/{lang}",
     *     summary="Сохранить переводы глобальных полей",
     *     tags={"Translations"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="values", type="object", description="Ключ — global_field_value_id, значение — переведённое значение или null для удаления")
     *     )),
     *     @OA\Response(response=200, description="Переводы сохранены")
     * )
     */
    public function saveGlobalTranslations(Request $request, string $lang): JsonResponse
    {
        $data = $request->validate([
            'values' => 'required|array',
        ]);

        $saved = 0;
        $deleted = 0;

        foreach ($data['values'] as $globalFieldValueId => $value) {
            // Verify the global_field_value exists
            if (!GlobalFieldValue::where('id', $globalFieldValueId)->exists()) {
                continue;
            }

            if (is_null($value)) {
                // Delete translation if value is null
                GlobalFieldValueTranslation::where('global_field_value_id', $globalFieldValueId)
                    ->where('lang', $lang)
                    ->delete();
                $deleted++;
            } else {
                GlobalFieldValueTranslation::updateOrCreate(
                    ['global_field_value_id' => $globalFieldValueId, 'lang' => $lang],
                    ['value' => is_string($value) ? $value : json_encode($value)]
                );
                $saved++;
            }
        }

        $this->logAction('save_translations', 'global_settings', null, [
            'lang' => $lang,
            'saved' => $saved,
            'deleted' => $deleted,
        ]);

        return $this->success(null, 'Переводы глобальных полей сохранены.');
    }
}
