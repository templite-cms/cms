<?php

namespace Templite\Cms\Services\Ai\Tools;

use Templite\Cms\Models\BlockPreset;
use Templite\Cms\Services\Ai\EditorContext;
use Templite\Cms\Services\Ai\Presets\PresetInspector;

class GetBlockPresetTool implements Tool
{
    public function __construct(private readonly PresetInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'get_block_preset';
    }

    public function definition(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'preset_id' => ['type' => 'integer', 'description' => 'ID пресета из list_block_presets или linked_preset.id из get_page'],
            ],
            'required' => ['preset_id'],
        ];
    }

    public function execute(array $args, ?EditorContext $ctx): array
    {
        $preset = BlockPreset::find((int) ($args['preset_id'] ?? 0));

        if (! $preset) {
            return ['error' => 'preset_not_found'];
        }

        $result = [
            'id' => $preset->id,
            'block_id' => $preset->block_id,
            'name' => $preset->name,
            'type' => $preset->type,
            'is_default' => (bool) $preset->is_default,
            'description' => $preset->description,
            'data' => $preset->data ?? [],
        ];

        if ($preset->type === 'global') {
            $result['used_on'] = $this->inspector->usedOn($preset);
        }

        return $result;
    }
}
