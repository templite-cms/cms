<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Templite\Cms\Http\Resources\CityPageResource;
use Templite\Cms\Http\Resources\PageResource;
use Templite\Cms\Models\City;
use Templite\Cms\Models\CityPage;
use Templite\Cms\Models\CityPageBlock;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Services\PageAssetCompiler;
use Templite\Cms\Services\UrlGenerator;

/**
 * @OA\Tag(name="City Pages", description="Городские оверрайды страниц")
 */
class CityPageController extends Controller
{
    public function __construct(
        protected UrlGenerator $urlGenerator,
    ) {}

    /**
     * Список городов с состоянием для конкретной страницы-источника.
     *
     * @OA\Get(
     *     path="/pages/{pageId}/cities",
     *     summary="Список городских версий страницы",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="pageId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Список городов со статусами")
     * )
     */
    public function index(int $pageId): JsonResponse
    {
        $page = Page::findOrFail($pageId);

        $cities = City::active()->ordered()->get();

        $cityPages = CityPage::where('source_page_id', $pageId)
            ->with('blockOverrides')
            ->get()
            ->keyBy('city_id');

        $result = $cities->map(function (City $city) use ($cityPages) {
            $cityPage = $cityPages->get($city->id);

            return [
                'city_id' => $city->id,
                'city_name' => $city->name,
                'city_slug' => $city->slug,
                'status' => $this->resolveCityPageStatus($cityPage),
                'city_page_id' => $cityPage?->id,
                'has_overrides' => $cityPage ? $cityPage->hasOverrides() : false,
                'is_materialized' => $cityPage?->is_materialized ?? false,
                'materialized_page_id' => $cityPage?->materialized_page_id,
            ];
        });

        return $this->success($result);
    }

    /**
     * Получить или создать оверрайд для города + страницы.
     *
     * @OA\Get(
     *     path="/pages/{pageId}/cities/{cityId}",
     *     summary="Получить оверрайд городской версии",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Данные оверрайда")
     * )
     */
    public function show(int $pageId, int $cityId): JsonResponse
    {
        Page::findOrFail($pageId);
        City::findOrFail($cityId);

        $cityPage = CityPage::firstOrCreate(
            ['city_id' => $cityId, 'source_page_id' => $pageId],
        );

        $cityPage->load(['city', 'sourcePage', 'blockOverrides.block']);

        return $this->success(new CityPageResource($cityPage));
    }

    /**
     * Обновить оверрайды городской версии страницы.
     *
     * @OA\Put(
     *     path="/pages/{pageId}/cities/{cityId}",
     *     summary="Обновить оверрайд",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Обновлено")
     * )
     */
    public function update(Request $request, int $pageId, int $cityId): JsonResponse
    {
        Page::findOrFail($pageId);
        City::findOrFail($cityId);

        $data = $request->validate([
            'title_override' => 'nullable|string|max:255',
            'bread_title_override' => 'nullable|string|max:255',
            'seo_data_override' => 'nullable|array',
            'social_data_override' => 'nullable|array',
            'template_data_override' => 'nullable|array',
            'status_override' => 'nullable|integer|in:0,1',
        ]);

        $cityPage = CityPage::updateOrCreate(
            ['city_id' => $cityId, 'source_page_id' => $pageId],
            $data,
        );

        $cityPage->load(['city', 'sourcePage', 'blockOverrides.block']);

        $this->logAction('update', 'city_page', $cityPage->id, [
            'city_id' => $cityId,
            'page_id' => $pageId,
        ]);

        return $this->success(new CityPageResource($cityPage));
    }

    /**
     * Обновить блочные оверрайды.
     *
     * @OA\Put(
     *     path="/city-pages/{cityPageId}/blocks",
     *     summary="Обновить оверрайды блоков",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Обновлено")
     * )
     */
    public function updateBlocks(Request $request, int $cityPageId): JsonResponse
    {
        $cityPage = CityPage::findOrFail($cityPageId);

        $data = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.page_block_id' => 'nullable|integer|exists:page_blocks,id',
            'blocks.*.block_id' => 'nullable|integer|exists:blocks,id',
            'blocks.*.action' => 'required|string|in:override,hide,add',
            'blocks.*.data_override' => 'nullable|array',
            'blocks.*.order_override' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($cityPage, $data) {
            // Удаляем старые оверрайды
            $cityPage->blockOverrides()->delete();

            // Создаём новые
            foreach ($data['blocks'] as $blockData) {
                CityPageBlock::create(array_merge($blockData, [
                    'city_page_id' => $cityPage->id,
                ]));
            }
        });

        $this->logAction('update', 'city_page_blocks', $cityPage->id, [
            'blocks_count' => count($data['blocks']),
        ]);

        return $this->success(
            new CityPageResource($cityPage->fresh(['city', 'sourcePage', 'blockOverrides.block']))
        );
    }

    /**
     * Материализовать виртуальную страницу.
     *
     * @OA\Post(
     *     path="/pages/{pageId}/cities/{cityId}/materialize",
     *     summary="Материализовать городскую страницу",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Страница материализована")
     * )
     */
    public function materialize(int $pageId, int $cityId): JsonResponse
    {
        $sourcePage = Page::with('pageBlocks')->findOrFail($pageId);
        $city = City::findOrFail($cityId);

        $cityPage = CityPage::firstOrCreate(
            ['city_id' => $cityId, 'source_page_id' => $pageId],
        );

        if ($cityPage->is_materialized) {
            return $this->error('Страница уже материализована.', 422);
        }

        $materializedPage = DB::transaction(function () use ($sourcePage, $city, $cityPage) {
            // Применяем оверрайды для создания итоговых данных
            $overrides = $cityPage->applyOverrides($sourcePage);

            // Создаём реальную страницу
            $newPage = $sourcePage->replicate(['url', 'views']);
            $newPage->title = $overrides['title'];
            $newPage->bread_title = $overrides['bread_title'];
            $newPage->seo_data = $overrides['seo_data'];
            $newPage->social_data = $overrides['social_data'];
            $newPage->template_data = $overrides['template_data'];
            $newPage->city_scope = Page::CITY_SCOPE_MATERIALIZED;
            $newPage->city_id = $city->id;
            $newPage->url = '/' . $city->slug . $sourcePage->url;
            $newPage->alias = $city->slug . '-' . $sourcePage->alias;
            $newPage->save();

            // Копируем блоки
            $blockOverrides = $cityPage->blockOverrides()->get()->keyBy('page_block_id');

            foreach ($sourcePage->pageBlocks as $pb) {
                $override = $blockOverrides->get($pb->id);

                // Пропускаем скрытые блоки
                if ($override && $override->isHidden()) {
                    continue;
                }

                $newPb = $pb->replicate();
                $newPb->page_id = $newPage->id;

                // Применяем оверрайд данных
                if ($override && $override->isOverride() && $override->data_override) {
                    $newPb->data = array_merge($pb->data ?? [], $override->data_override);
                }
                if ($override && $override->order_override !== null) {
                    $newPb->order = $override->order_override;
                }

                $newPb->save();
            }

            // Добавляем добавленные блоки
            $addedBlocks = $cityPage->blockOverrides()->where('action', 'add')->get();
            foreach ($addedBlocks as $added) {
                PageBlock::create([
                    'page_id' => $newPage->id,
                    'block_id' => $added->block_id,
                    'data' => $added->data_override ?? [],
                    'order' => $added->order_override ?? 999,
                    'status' => \Templite\Cms\Enums\PageBlockStatus::Published,
                ]);
            }

            // Обновляем city_page
            $cityPage->update([
                'is_materialized' => true,
                'materialized_page_id' => $newPage->id,
            ]);

            return $newPage;
        });

        // Компилируем ассеты
        app(PageAssetCompiler::class)->compile($materializedPage);

        $this->logAction('materialize', 'city_page', $cityPage->id, [
            'city_id' => $cityId,
            'page_id' => $pageId,
            'materialized_page_id' => $materializedPage->id,
        ]);

        return $this->success(
            new PageResource($materializedPage->load(['pageType', 'image', 'screenshot'])),
            'Страница материализована.'
        );
    }

    /**
     * Демaterialизовать страницу.
     *
     * @OA\Post(
     *     path="/pages/{pageId}/cities/{cityId}/dematerialize",
     *     summary="Демaterialизовать городскую страницу",
     *     tags={"City Pages"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Страница демaterialизована")
     * )
     */
    public function dematerialize(int $pageId, int $cityId): JsonResponse
    {
        $cityPage = CityPage::where('source_page_id', $pageId)
            ->where('city_id', $cityId)
            ->firstOrFail();

        if (!$cityPage->is_materialized) {
            return $this->error('Страница не материализована.', 422);
        }

        DB::transaction(function () use ($cityPage) {
            // Удаляем материализованную страницу
            if ($cityPage->materialized_page_id) {
                Page::where('id', $cityPage->materialized_page_id)->delete();
            }

            $cityPage->update([
                'is_materialized' => false,
                'materialized_page_id' => null,
            ]);
        });

        $this->logAction('dematerialize', 'city_page', $cityPage->id, [
            'city_id' => $cityId,
            'page_id' => $pageId,
        ]);

        return $this->success(null, 'Страница возвращена к виртуальной.');
    }

    /**
     * Определить статус городской версии страницы.
     */
    protected function resolveCityPageStatus(?CityPage $cityPage): string
    {
        if (!$cityPage) {
            return 'virtual';
        }

        if ($cityPage->is_materialized) {
            return 'materialized';
        }

        if ($cityPage->status_override !== null && $cityPage->status_override !== 1) {
            return 'hidden';
        }

        if ($cityPage->hasOverrides()) {
            return 'overridden';
        }

        return 'virtual';
    }
}
