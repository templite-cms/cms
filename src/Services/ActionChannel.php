<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\AiEditorOp;

/**
 * Откуда пришло действие: админка, встроенный ассистент или MCP-шлюз.
 * Ассистент работает в очереди, где request() — не его запрос, поэтому
 * канал хранится здесь, а не в атрибутах запроса.
 *
 * Третий случай — правка, которую ассистент предложил открытому редактору
 * (AiEditorOp), а сохранил человек обычным запросом админки. Функция сохранения
 * редактора шлёт id применённых правок заголовком X-Ai-Editor-Ops; если среди
 * них есть свежая правка этого же менеджера в статусе applied, нацеленная на
 * сохраняемую сущность, запись журнала считается ходом ассистента (forEntity).
 */
class ActionChannel
{
    public const ADMIN = 'admin';

    public const ASSISTANT = 'assistant';

    public const MCP = 'mcp';

    public const EDITOR_OPS_HEADER = 'X-Ai-Editor-Ops';

    /** Больше id в заголовке не разбираем: столько правок за одно сохранение не бывает */
    private const EDITOR_OPS_LIMIT = 50;

    /** Правки, применённые раньше, сохранением ассистента не считаются */
    private const EDITOR_OPS_TTL_HOURS = 24;

    /**
     * Какие записи журнала сохраняют цель правки. Ключ — target_type правки
     * (EditorOpStore::targetOf), значение — entity_type журнала => откуда брать
     * id цели: 'id' — entity_id, иначе ключ в data. null — любой id.
     *
     * Карта покрывает только сущности, которые сохраняют редакторы, умеющие
     * нести метку правок ассистента (X-Ai-Editor-Ops). block_field, block_action,
     * block_preset и template_field сохраняют отдельные контроллеры, вызываемые
     * запросами без этого заголовка, — их записи журнала не могут быть помечены.
     */
    private const EDITOR_OP_ENTITIES = [
        'block' => ['block' => 'id'],
        'template' => ['template' => 'id'],
        'action' => ['action' => 'id'],
        // page_block — данные блоков и их переводы, включая немедленные правки
        // (add_block/apply_preset/link_preset/unlink_preset) из открытого редактора;
        // переводы страницы пишутся как page
        'page' => ['page' => 'id', 'page_block' => 'page_id'],
        // id цели — экран настроек (values/structure), а не запись
        'settings' => ['global_settings' => null],
    ];

    private ?string $channel = null;

    private ?int $runId = null;

    /**
     * Применённые правки из X-Ai-Editor-Ops, прошедшие проверку (свои, applied,
     * свежие), от новых к старым. null — ещё не загружали. Scoped-сервис живёт
     * один запрос, поэтому запрос в БД — не больше одного на запрос.
     *
     * @var Collection<int,AiEditorOp>|null
     */
    private ?Collection $editorOps = null;

    public function enter(string $channel, ?int $runId = null): void
    {
        $this->channel = $channel;
        $this->runId = $runId;
    }

    public function leave(): void
    {
        $this->channel = null;
        $this->runId = null;
    }

    /** Канал без учёта правок из редактора: enter() или MCP, иначе admin */
    public function channel(): string
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        // Шлюз помечает свой HTTP-запрос в EnsureMcpToken
        return request()->attributes->get('cms.channel') === self::MCP ? self::MCP : self::ADMIN;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }

    /**
     * Метка записи журнала о сущности. enter() и MCP важнее правок из редактора.
     *
     * @return array{via: string, run_id: ?int, via_editor: bool}
     */
    public function forEntity(string $entityType, ?int $entityId, ?array $data): array
    {
        $channel = $this->channel();

        if ($channel !== self::ADMIN) {
            return ['via' => $channel, 'run_id' => $this->runId(), 'via_editor' => false];
        }

        $op = $this->editorOps()->first(fn (AiEditorOp $op) => self::targets($op, $entityType, $entityId, $data ?? []));

        return $op
            ? ['via' => self::ASSISTANT, 'run_id' => $op->run_id !== null ? (int) $op->run_id : null, 'via_editor' => true]
            : ['via' => self::ADMIN, 'run_id' => null, 'via_editor' => false];
    }

    private static function targets(AiEditorOp $op, string $entityType, ?int $entityId, array $data): bool
    {
        $entities = self::EDITOR_OP_ENTITIES[$op->target_type] ?? [];

        if (! array_key_exists($entityType, $entities)) {
            return false;
        }

        $source = $entities[$entityType];

        if ($source === null) {
            return true;
        }

        $id = $source === 'id' ? $entityId : ($data[$source] ?? null);

        return $id !== null && (string) $id === (string) $op->target_id;
    }

    /**
     * Чужие, несуществующие, неприменённые и устаревшие id молча игнорируются.
     *
     * @return Collection<int,AiEditorOp>
     */
    private function editorOps(): Collection
    {
        if ($this->editorOps !== null) {
            return $this->editorOps;
        }

        $ids = [];
        $header = (string) request()->header(self::EDITOR_OPS_HEADER, '');

        foreach (array_slice(explode(',', $header), 0, self::EDITOR_OPS_LIMIT) as $part) {
            $part = trim($part);
            if (ctype_digit($part) && strlen($part) <= 18 && (int) $part > 0) {
                $ids[(int) $part] = true;
            }
        }

        if ($ids === []) {
            return $this->editorOps = new Collection();
        }

        // Без менеджера правку не к кому привязать. Не кэшируем: после
        // аутентификации в том же запросе проверим заново.
        $manager = auth()->user();
        if (! $manager) {
            return new Collection();
        }

        return $this->editorOps = AiEditorOp::query()
            ->whereIn('id', array_keys($ids))
            ->where('manager_id', $manager->getAuthIdentifier())
            ->where('status', AiEditorOp::APPLIED)
            ->where('applied_at', '>=', now()->subHours(self::EDITOR_OPS_TTL_HOURS))
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->get(['id', 'run_id', 'target_type', 'target_id'])
            ->collect();
    }
}
