<?php

namespace Templite\Cms\Services\Ai;

use Illuminate\Support\Collection;
use Templite\Cms\Models\AiKey;
use Templite\Cms\Models\Manager;

/**
 * Кто каким ключом вправе пользоваться.
 *
 * Владение ключом само по себе не открывает ассистента: нужно ещё право ai.use,
 * иначе выключить ассистента целому типу менеджеров можно было бы только
 * разбором доступов по одному.
 */
class AiKeyService
{
    public function __construct(private readonly AiSettingsService $settings)
    {
    }

    /**
     * Свои ключи и те, к которым выдали доступ. Админ видит все ключи сайта.
     * Свои идут раньше чужих — по ним менеджер платит сам.
     *
     * @return Collection<int,AiKey>
     */
    public function availableFor(Manager $manager): Collection
    {
        $query = AiKey::query()->with('owner:id,name');

        $keys = $manager->hasPermission('*')
            ? $query->get()
            : $query
                ->where('owner_manager_id', $manager->id)
                ->orWhereHas('sharedWith', fn ($q) => $q->where('managers.id', $manager->id))
                ->get();

        // Сортируем в памяти: ключей единицы, а CASE в SQL пришлось бы писать
        // по-разному под MySQL и PostgreSQL. Ключ сортировки — массив:
        // sortBy() с массивом замыканий трактовал бы их как компараторы.
        return $keys
            ->sortBy(fn (AiKey $key) => [
                $key->owner_manager_id === $manager->id ? 0 : 1,
                $key->id,
            ])
            ->values();
    }

    /**
     * Рабочий ключ менеджера. Если выбранный ключ удалили или доступ отозвали —
     * подставляем первый доступный и запоминаем выбор.
     */
    public function activeFor(Manager $manager): ?AiKey
    {
        $available = $this->availableFor($manager);

        if ($available->isEmpty()) {
            return null;
        }

        $active = $available->firstWhere('id', $manager->active_ai_key_id);

        if ($active) {
            return $active;
        }

        $first = $available->first();
        $manager->forceFill(['active_ai_key_id' => $first->id])->save();

        return $first;
    }

    public function canUse(Manager $manager): bool
    {
        return $this->settings->isEnabled()
            && $manager->hasPermission('ai.use')
            && $this->availableFor($manager)->isNotEmpty();
    }

    /** Править и шарить ключ вправе владелец и админ. */
    public function canEdit(Manager $manager, AiKey $key): bool
    {
        return $key->owner_manager_id === $manager->id || $manager->hasPermission('*');
    }

    /**
     * Отозвать доступ. Ключ мог быть у менеджера рабочим — обнуляем выбор,
     * иначе в настройках остался бы чужой ключ, которым уже нельзя пользоваться.
     */
    public function revokeAccess(AiKey $key, int $managerId): void
    {
        $key->sharedWith()->detach($managerId);

        Manager::where('id', $managerId)
            ->where('active_ai_key_id', $key->id)
            ->update(['active_ai_key_id' => null]);
    }
}
