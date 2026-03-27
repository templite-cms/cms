<?php

namespace Templite\Cms\Services;

use RuntimeException;

class AssetResolver
{
    private ?array $manifests = null;

    /**
     * Resolve an entry file to HTML script/link tags using Vite manifests.
     * In dev mode (hot file exists), falls back to Vite dev server.
     */
    public function resolve(string $entry): string
    {
        // Dev mode — use Vite dev server
        if ($this->isDevMode()) {
            return $this->renderDevTags($entry);
        }

        // Production — resolve from manifests
        foreach ($this->getManifests() as $basePath => $manifest) {
            if (isset($manifest[$entry])) {
                return $this->renderTags($basePath, $manifest[$entry]);
            }
        }

        throw new RuntimeException("Asset not found in any manifest: {$entry}");
    }

    /**
     * Render HTML tags for a production asset.
     */
    private function renderTags(string $basePath, array $asset): string
    {
        $html = '';

        // CSS files
        foreach ($asset['css'] ?? [] as $css) {
            $html .= '<link rel="stylesheet" href="' . asset($basePath . '/' . $css) . '">' . "\n";
        }

        // Shared chunks (imports)
        foreach ($asset['imports'] ?? [] as $chunk) {
            $html .= '<script type="module" src="' . asset($basePath . '/' . $chunk) . '"></script>' . "\n";
        }

        // Entry point
        $html .= '<script type="module" src="' . asset($basePath . '/' . $asset['file']) . '"></script>' . "\n";

        return $html;
    }

    /**
     * Render tags for Vite dev server.
     */
    private function renderDevTags(string $entry): string
    {
        $devServerUrl = $this->getDevServerUrl();
        return '<script type="module" src="' . $devServerUrl . '/' . $entry . '"></script>' . "\n";
    }

    /**
     * Collect manifests from all registered modules.
     */
    private function getManifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];
        $registry = app(ModuleRegistry::class);

        foreach ($registry->getModules() as $module) {
            $manifestPath = $module->getAssetManifest();
            if ($manifestPath && file_exists($manifestPath)) {
                $basePath = dirname($manifestPath);
                // Convert absolute path to relative from public/
                $relativePath = str_replace(public_path() . '/', '', $basePath);
                $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
                $this->manifests[$relativePath] = $manifest;
            }
        }

        return $this->manifests;
    }

    /**
     * Check if Vite dev server is running.
     */
    private function isDevMode(): bool
    {
        return app()->environment('local') && file_exists(public_path('hot'));
    }

    /**
     * Get Vite dev server URL from hot file.
     */
    private function getDevServerUrl(): string
    {
        return rtrim(file_get_contents(public_path('hot')));
    }
}
