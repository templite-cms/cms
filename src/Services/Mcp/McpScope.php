<?php

namespace Templite\Cms\Services\Mcp;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Что разрешено MCP-клиенту: abilities токена, профиль набора инструментов и
 * режим показа. Права менеджера проверяют сами инструменты — scope только
 * сужает их сверху.
 */
final class McpScope
{
    /** Abilities токена без префикса `mcp:`. */
    public const ABILITIES = ['read', 'write', 'code', 'delete'];

    /** Профили от узкого к широкому: запрошенный клиентом профиль может только сузить токенный. */
    public const PROFILES = ['content', 'builder', 'full'];

    public const MODE_DIRECT = 'direct';
    public const MODE_DISCOVERY = 'discovery';

    /**
     * @param  array<int,string>  $abilities
     */
    public function __construct(
        public readonly array $abilities,
        public readonly string $profile,
        public readonly string $mode = self::MODE_DIRECT,
    ) {
    }

    public static function isMcpToken(PersonalAccessToken $token): bool
    {
        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with((string) $ability, 'mcp:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string|null  $requestedProfile  заголовок X-Templite-Profile
     * @param  string|null  $mode  заголовок X-Mcp-Mode
     */
    public static function fromToken(PersonalAccessToken $token, ?string $requestedProfile = null, ?string $mode = null): self
    {
        $abilities = [];
        $profile = 'content';

        foreach ($token->abilities ?? [] as $ability) {
            $ability = (string) $ability;

            if (str_starts_with($ability, 'mcp-profile:')) {
                $profile = substr($ability, strlen('mcp-profile:'));
            } elseif (str_starts_with($ability, 'mcp:')) {
                $abilities[] = substr($ability, strlen('mcp:'));
            }
        }

        if (! in_array($profile, self::PROFILES, true)) {
            $profile = 'content';
        }

        $requested = $requestedProfile !== null ? strtolower(trim($requestedProfile)) : null;
        if ($requested && in_array($requested, self::PROFILES, true)
            && array_search($requested, self::PROFILES, true) < array_search($profile, self::PROFILES, true)) {
            $profile = $requested;
        }

        return new self(
            array_values(array_intersect(self::ABILITIES, $abilities)),
            $profile,
            $mode === self::MODE_DISCOVERY ? self::MODE_DISCOVERY : self::MODE_DIRECT,
        );
    }

    /** Пускает ли scope инструмент с этой ability (и с этой отметкой профиля content). */
    public function allows(string $ability, bool $inContent): bool
    {
        if (! in_array($ability, $this->abilities, true)) {
            return false;
        }

        return $this->profile !== 'content' || $inContent;
    }
}
