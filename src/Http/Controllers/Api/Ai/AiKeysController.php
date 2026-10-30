<?php

namespace Templite\Cms\Http\Controllers\Api\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Templite\Cms\Http\Controllers\Api\Controller;
use Templite\Cms\Models\AiKey;
use Templite\Cms\Models\Manager;
use Templite\Cms\Services\Ai\AiKeyService;

/**
 * Ключи провайдеров AI и доступы к ним.
 *
 * Право ai.keys.manage даёт завести свой ключ; править и шарить ключ может
 * владелец либо админ — это зависит от записи, поэтому проверка живёт здесь,
 * а не в middleware маршрута.
 */
class AiKeysController extends Controller
{
    public function __construct(private readonly AiKeyService $keys)
    {
    }

    public function index(): JsonResponse
    {
        $manager = $this->manager();

        $items = $this->keys->availableFor($manager)->map(fn (AiKey $key) => $this->present($key, $manager));

        return $this->success([
            'items' => $items->values(),
            'active_key_id' => $this->keys->activeFor($manager)?->id,
            'can_manage' => $manager->hasPermission('ai.keys.manage'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->manager();
        $data = $this->validated($request, keyRequired: true);

        $key = AiKey::create($data + ['owner_manager_id' => $manager->id]);

        // Первый ключ сразу становится рабочим: иначе после добавления
        // ассистент всё ещё отвечал бы «ключ не выбран».
        if (! $manager->active_ai_key_id) {
            $manager->forceFill(['active_ai_key_id' => $key->id])->save();
        }

        $this->logAction('create', 'ai_key', $key->id, ['provider' => $key->provider]);

        return $this->success($this->present($key, $manager), 'Ключ добавлен.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $manager = $this->manager();
        $key = AiKey::find($id);

        if (! $key || ! $this->keys->canEdit($manager, $key)) {
            return $this->error('Ключ не найден.', 404);
        }

        $data = $this->validated($request, keyRequired: false);

        // Провайдер сменили — имена моделей от прежнего провайдера больше не годятся.
        if (($data['provider'] ?? $key->provider) !== $key->provider) {
            $data['model'] = null;
            $data['image_model'] = null;
        }

        $key->update($data);

        $this->logAction('update', 'ai_key', $key->id, ['provider' => $key->provider]);

        return $this->success($this->present($key->fresh(), $manager), 'Ключ сохранён.');
    }

    public function destroy(int $id): JsonResponse
    {
        $manager = $this->manager();
        $key = AiKey::find($id);

        if (! $key || ! $this->keys->canEdit($manager, $key)) {
            return $this->error('Ключ не найден.', 404);
        }

        $key->delete();

        $this->logAction('delete', 'ai_key', $id);

        return $this->success(null, 'Ключ удалён.');
    }

    /** Кому выдан доступ к ключу. */
    public function access(int $id): JsonResponse
    {
        $manager = $this->manager();
        $key = AiKey::find($id);

        if (! $key || ! $this->keys->canEdit($manager, $key)) {
            return $this->error('Ключ не найден.', 404);
        }

        return $this->success(
            $key->sharedWith()->get(['managers.id', 'managers.name', 'managers.login'])
                ->map(fn (Manager $m) => ['id' => $m->id, 'name' => $m->name, 'login' => $m->login])
                ->values()
        );
    }

    public function grant(Request $request, int $id): JsonResponse
    {
        $manager = $this->manager();
        $key = AiKey::find($id);

        if (! $key || ! $this->keys->canEdit($manager, $key)) {
            return $this->error('Ключ не найден.', 404);
        }

        // Только активные менеджеры: /ai/managers отдаёт такой же список,
        // иначе доступ можно было бы выдать заблокированному.
        $data = $request->validate([
            'manager_id' => ['required', 'integer', Rule::exists('managers', 'id')->where('is_active', true)],
        ]);

        if ((int) $data['manager_id'] === $key->owner_manager_id) {
            return $this->error('Владельцу доступ не нужен.', 422);
        }

        $key->sharedWith()->syncWithoutDetaching([$data['manager_id']]);

        $this->logAction('grant', 'ai_key', $key->id, ['manager_id' => $data['manager_id']]);

        return $this->success(null, 'Доступ выдан.');
    }

    public function revoke(int $id, int $managerId): JsonResponse
    {
        $manager = $this->manager();
        $key = AiKey::find($id);

        if (! $key || ! $this->keys->canEdit($manager, $key)) {
            return $this->error('Ключ не найден.', 404);
        }

        // Без этой проверки отзыв несуществующего доступа всё равно обнулял бы
        // менеджеру рабочий ключ — по произвольному id из URL.
        if (! $key->sharedWith()->where('managers.id', $managerId)->exists()) {
            return $this->error('Доступ не найден.', 404);
        }

        $this->keys->revokeAccess($key, $managerId);

        $this->logAction('revoke', 'ai_key', $key->id, ['manager_id' => $managerId]);

        return $this->success(null, 'Доступ отозван.');
    }

    /** Рабочий ключ менеджера — только из доступных ему. */
    public function setActive(Request $request): JsonResponse
    {
        $manager = $this->manager();

        $data = $request->validate([
            'key_id' => ['required', 'integer'],
        ]);

        $key = $this->keys->availableFor($manager)->firstWhere('id', (int) $data['key_id']);

        if (! $key) {
            return $this->error('Ключ недоступен.', 404);
        }

        $manager->forceFill(['active_ai_key_id' => $key->id])->save();

        return $this->success(['active_key_id' => $key->id], 'Рабочий ключ выбран.');
    }

    /**
     * Кандидаты на выдачу доступа. Полный список менеджеров закрыт правом
     * managers.view, которого владельцу ключа иметь не обязательно.
     */
    public function managers(): JsonResponse
    {
        return $this->success(
            Manager::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'login'])
                ->map(fn (Manager $m) => ['id' => $m->id, 'name' => $m->name, 'login' => $m->login])
                ->values()
        );
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $keyRequired): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', Rule::in(AiKey::PROVIDERS)],
            'api_key' => [$keyRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:120'],
            'image_model' => ['nullable', 'string', 'max:120'],
            'max_steps' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_images' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        // Пустое поле означает «ключ не меняли». Маску, вернувшуюся из формы,
        // тоже игнорируем: иначе она перезапишет рабочий ключ, а исходник есть
        // только у пользователя.
        $incoming = trim((string) ($data['api_key'] ?? ''));

        if ($incoming === '' || str_starts_with($incoming, '***')) {
            // При создании ключа «не меняли» невозможно: маска здесь — ошибка
            // ввода, и без неё запись упала бы на NOT NULL пятисоткой.
            if ($keyRequired) {
                throw ValidationException::withMessages([
                    'api_key' => 'Укажите ключ провайдера, а не его маску.',
                ]);
            }

            unset($data['api_key']);
        } else {
            $data['api_key'] = $incoming;
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function present(AiKey $key, Manager $manager): array
    {
        $masked = $key->maskedKey();

        return [
            'id' => $key->id,
            'title' => $key->title,
            'provider' => $key->provider,
            'key_masked' => $masked,
            // Пустая маска у сохранённого ключа значит, что шифротекст не читается
            // (сменился APP_KEY) — форма подскажет ввести ключ заново.
            'key_broken' => $masked === '',
            'model' => $key->model,
            'image_model' => $key->image_model,
            'max_steps' => $key->max_steps,
            'max_images' => $key->max_images,
            'is_owner' => $key->owner_manager_id === $manager->id,
            'owner_id' => $key->owner_manager_id,
            'owner_name' => $key->owner?->name,
            'can_edit' => $this->keys->canEdit($manager, $key),
        ];
    }

    private function manager(): Manager
    {
        return auth()->user() ?? auth('manager')->user();
    }
}
