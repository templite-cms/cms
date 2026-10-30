<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Mcp\McpScope;

class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    public function __construct(
        private readonly ToolCatalog $catalog,
        ListPagesTool $listPages,
        GetPageTool $getPage,
        ListBlockTypesTool $listBlockTypes,
        CreatePageTool $createPage,
        AddBlockTool $addBlock,
        UpdateBlockTool $updateBlock,
        GetBlockCodeTool $getBlockCode,
        PreviewBlockTool $previewBlock,
        CreateBlockTypeTool $createBlockType,
        AddBlockFieldTool $addBlockField,
        UpdateBlockFieldTool $updateBlockField,
        UpdateBlockCodeTool $updateBlockCode,
        RestoreBlockCodeVersionTool $restoreBlockCodeVersion,
        ListMediaTool $listMedia,
        ListMediaFoldersTool $listMediaFolders,
        UploadMediaFromUrlTool $uploadMediaFromUrl,
        CreateMediaFolderTool $createMediaFolder,
        UpdateMediaFileTool $updateMediaFile,
        GenerateImageTool $generateImage,
        SearchCdnLibrariesTool $searchCdnLibraries,
        AddCdnLibraryTool $addCdnLibrary,
        ListLibrariesTool $listLibraries,
        ListTemplatesTool $listTemplates,
        AttachLibraryTool $attachLibrary,
        GetActionGuideTool $getActionGuide,
        ListActionsTool $listActions,
        GetActionCodeTool $getActionCode,
        CreateActionTool $createAction,
        UpdateActionCodeTool $updateActionCode,
        AttachActionTool $attachAction,
        SetActionParamsTool $setActionParams,
        SetBlockCacheTool $setBlockCache,
        TestActionTool $testAction,
        CreateTemplateTool $createTemplate,
        GetTemplateCodeTool $getTemplateCode,
        UpdateTemplateCodeTool $updateTemplateCode,
        PreviewTemplateTool $previewTemplate,
        AddTemplateFieldTool $addTemplateField,
        UpdatePageTool $updatePage,
        ListComponentsTool $listComponents,
        GetComponentCodeTool $getComponentCode,
        CreateComponentTool $createComponent,
        UpdateComponentCodeTool $updateComponentCode,
        PreviewComponentTool $previewComponent,
        GetBlockGuideTool $getBlockGuide,
        GetTemplateGuideTool $getTemplateGuide,
        GetComponentGuideTool $getComponentGuide,
        GetMediaGuideTool $getMediaGuide,
        GetAssetsGuideTool $getAssetsGuide,
        ListGlobalFieldsTool $listGlobalFields,
        GetSettingsGuideTool $getSettingsGuide,
        ApplySettingsLayoutTool $applySettingsLayout,
        UpdateSettingsFieldTool $updateSettingsField,
        DeleteSettingsNodeTool $deleteSettingsNode,
        SetGlobalValuesTool $setGlobalValues,
        ListBlockPresetsTool $listBlockPresets,
        GetBlockPresetTool $getBlockPreset,
        CreateBlockPresetTool $createBlockPreset,
        UpdateBlockPresetTool $updateBlockPreset,
        ApplyPresetToBlockTool $applyPresetToBlock,
    ) {
        foreach ([
            $listPages, $getPage, $listBlockTypes, $createPage, $addBlock, $updateBlock,
            $getBlockCode, $previewBlock, $createBlockType, $addBlockField,
            $updateBlockField, $updateBlockCode, $restoreBlockCodeVersion,
            $listMedia, $listMediaFolders, $uploadMediaFromUrl,
            $createMediaFolder, $updateMediaFile, $generateImage,
            $searchCdnLibraries, $addCdnLibrary,
            $listLibraries, $listTemplates, $attachLibrary,
            $getActionGuide, $listActions, $getActionCode,
            $createAction, $updateActionCode, $attachAction, $setActionParams, $setBlockCache, $testAction,
            $createTemplate, $getTemplateCode, $updateTemplateCode, $previewTemplate,
            $addTemplateField, $updatePage,
            $listComponents, $getComponentCode, $createComponent,
            $updateComponentCode, $previewComponent,
            $getBlockGuide, $getTemplateGuide, $getComponentGuide,
            $getMediaGuide, $getAssetsGuide,
            $listGlobalFields, $getSettingsGuide, $applySettingsLayout, $updateSettingsField,
            $deleteSettingsNode, $setGlobalValues,
            $listBlockPresets, $getBlockPreset, $createBlockPreset, $updateBlockPreset,
            $applyPresetToBlock,
        ] as $tool) {
            if (! $this->catalog->has($tool->name())) {
                throw new \LogicException("Инструмент {$tool->name()} не описан в ToolCatalog.");
            }

            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return array<int,array<string,mixed>> function-calling `tools` для модели ассистента */
    public function schemas(): array
    {
        return array_map(fn (array $d) => [
            'type' => 'function',
            'function' => $d,
        ], $this->definitions(ToolCatalog::CHANNEL_ASSISTANT));
    }

    /**
     * Доступные инструменты канала: имя, описание, JSON-схема параметров.
     *
     * @return array<int,array{name:string,description:string,parameters:array<string,mixed>}>
     */
    public function definitions(string $channel, ?McpScope $scope = null): array
    {
        $result = [];

        foreach ($this->tools as $name => $tool) {
            if (! $this->allows($name, $channel, $scope)) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'description' => $this->catalog->description($name),
                'parameters' => $tool->definition(),
            ];
        }

        return $result;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function allows(string $name, string $channel, ?McpScope $scope = null): bool
    {
        if (! $this->has($name) || ! $this->catalog->inChannel($name, $channel)) {
            return false;
        }

        return $scope === null
            || $scope->allows($this->catalog->ability($name), $this->catalog->inContent($name));
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function execute(string $name, array $args, ?EditorContext $ctx, string $channel = ToolCatalog::CHANNEL_ASSISTANT): array
    {
        // Инструмент чужого канала для модели не существует — как и неизвестный.
        if (! $this->has($name) || ! $this->catalog->inChannel($name, $channel)) {
            return ['error' => 'unknown_tool', 'tool' => $name];
        }

        try {
            return $this->tools[$name]->execute($args, $ctx);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Аргументы не прошли валидацию модели/контроллера — агент может исправиться.
            return ['error' => 'validation_failed', 'tool' => $name, 'details' => $e->errors()];
        } catch (\Throwable $e) {
            // Исключение инструмента (например, findOrFail на чужом id) раньше рвало
            // SSE-поток посреди ответа: клиент не получал done и висел с индикатором.
            // Возвращаем ошибку агенту как результат — он сообщит о ней или повторит иначе.
            report($e);

            return ['error' => 'tool_failed', 'tool' => $name, 'message' => $e->getMessage()];
        }
    }

    /**
     * Результат инструмента для модели. Длинный обрезается: один list_* мог
     * занять всё окно модели. Код и справки не режутся — см. isUnbounded().
     *
     * @param  array<string,mixed>  $result
     */
    public function encodeResult(string $name, array $result): string
    {
        // Битый UTF-8 из файлов или внешних API не должен превращать результат в пустую строку.
        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($this->isUnbounded($name)) {
            return $json;
        }

        $limit = \Templite\Cms\Services\Ai\Runs\RunSettings::toolResultLimit();
        $length = mb_strlen($json);

        if ($length <= $limit) {
            return $json;
        }

        return mb_substr($json, 0, $limit).'…[обрезано '.($length - $limit).' символов; запроси точечнее]';
    }

    /**
     * Чтение кода (get_*_code) и справок (get_*_guide) лимитом не режем:
     * модель правит код целиком, и обрезанная копия с последующим
     * update_*_code молча потеряет хвост файла. Справка без хвоста — это
     * неполные правила, по которым валидатор потом отклонит правку.
     */
    private function isUnbounded(string $name): bool
    {
        return str_starts_with($name, 'get_')
            && (str_ends_with($name, '_code') || str_ends_with($name, '_guide'));
    }
}
