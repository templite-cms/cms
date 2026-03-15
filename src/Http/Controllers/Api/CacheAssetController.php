<?php

namespace Templite\Cms\Http\Controllers\Api;

use Illuminate\Http\Request;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\CacheManager;
use Templite\Cms\Services\PageAssetCompiler;

class CacheAssetController extends Controller
{
    public function __construct(
        protected CacheManager $cacheManager,
        protected PageAssetCompiler $compiler,
    ) {}

    /**
     * Clear CMS cache.
     *
     * @OA\Post(
     *     path="/cache/clear",
     *     tags={"Cache & Assets"},
     *     summary="Clear CMS cache",
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="scope", type="string", enum={"all", "blocks", "global", "scss"}, description="What to clear (default: all)")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Cache cleared")
     * )
     */
    public function clearCache(Request $request)
    {
        $request->validate([
            'scope' => 'sometimes|string|in:all,blocks,global,scss',
        ]);

        $scope = $request->input('scope', 'all');
        $details = [];

        if ($scope === 'all') {
            $details = $this->cacheManager->clearAll();
        } elseif ($scope === 'blocks') {
            $details = $this->cacheManager->clearBlocks();
        } elseif ($scope === 'global') {
            $details = $this->cacheManager->invalidateGlobalFields();
        } elseif ($scope === 'scss') {
            $details = $this->cacheManager->clearScss();
        }

        $this->logAction('clear_cache', 'cache', null, ['scope' => $scope, 'details' => $details]);

        return $this->success([
            'scope' => $scope,
            'details' => $details,
        ], 'Cache cleared successfully');
    }

    /**
     * Compile assets for a specific page.
     *
     * @OA\Post(
     *     path="/assets/compile/{pageId}",
     *     tags={"Cache & Assets"},
     *     summary="Compile CSS/JS assets for a specific page",
     *     @OA\Parameter(name="pageId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Assets compiled")
     * )
     */
    public function compilePage(int $pageId)
    {
        $page = Page::findOrFail($pageId);
        $asset = $this->compiler->compile($page);

        $this->logAction('compile', 'cache', $page->id, ['title' => $page->title]);

        return $this->success([
            'page_id' => $page->id,
            'css_path' => $asset->css_path,
            'js_path' => $asset->js_path,
            'hash' => $asset->hash,
        ], 'Page assets compiled successfully');
    }

    /**
     * Compile assets for all published pages.
     *
     * @OA\Post(
     *     path="/assets/compile-all",
     *     tags={"Cache & Assets"},
     *     summary="Compile CSS/JS assets for all published pages",
     *     @OA\Response(response=200, description="All assets compiled")
     * )
     */
    public function compileAll()
    {
        $result = $this->compiler->compileAll();

        $this->logAction('compile', 'cache', null, ['scope' => 'all', 'pages_compiled' => $result['compiled'], 'errors' => $result['errors']]);

        return $this->success([
            'pages_compiled' => $result['compiled'],
            'errors' => $result['errors'],
        ], "Assets compiled for {$result['compiled']} pages");
    }

    /**
     * Clean all compiled assets and recompile.
     *
     * @OA\Post(
     *     path="/assets/rebuild",
     *     tags={"Cache & Assets"},
     *     summary="Delete all compiled assets, clear cache, and recompile everything",
     *     @OA\Response(response=200, description="Assets rebuilt")
     * )
     */
    public function rebuild()
    {
        // 1. Clear all cache (returns detailed stats)
        $cacheStats = $this->cacheManager->clearAll();

        // 2. Delete all compiled assets
        $this->compiler->cleanAll();

        // 3. Recompile all
        $compileResult = $this->compiler->compileAll();

        $this->logAction('rebuild', 'cache', null, [
            'cache' => $cacheStats,
            'compiled' => $compileResult,
        ]);

        return $this->success([
            'cache' => $cacheStats,
            'pages_compiled' => $compileResult['compiled'],
            'errors' => $compileResult['errors'],
        ], "Full rebuild completed for {$compileResult['compiled']} pages");
    }
}
