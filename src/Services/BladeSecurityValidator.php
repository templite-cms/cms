<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

/**
 * Проверка Blade-кода блоков, шаблонов и компонентов.
 *
 * НЕ песочница. Право на код — это доверие: выражения Blade исполняются как PHP,
 * и через app(), фасады и модели доступен весь сервер (см. DangerousCodeScanner).
 * Проверка делает две вещи:
 * 1. Директивы — по белому списку: держит вёрстку в предсказуемых рамках
 *    (без @include произвольных файлов, @extends, @push и т.п.).
 * 2. «Жёсткие» возможности — запуск процессов, исполнение строки как кода,
 *    файлы, сеть, окружение — ищутся в скомпилированном PHP: так проверяются и
 *    {{ }}, и аргументы @if/@foreach, и блоки @php.
 */
class BladeSecurityValidator
{
    /**
     * Разрешённые Blade-директивы (whitelist).
     */
    protected array $allowedDirectives = [
        'if',
        'else',
        'elseif',
        'endif',
        'foreach',
        'endforeach',
        'for',
        'endfor',
        'while',
        'endwhile',
        'empty',
        'endempty',
        'isset',
        'endisset',
        'unless',
        'endunless',
        'switch',
        'case',
        'break',
        'default',
        'endswitch',
        'yield',
        'section',
        'endsection',
        'show',
        // Вычисления в шаблоне — тот же PHP, что и в {{ }}; проверяется сканером
        'php',
        'endphp',
    ];

    /**
     * Директивы вне whitelist с пояснением, почему их нет.
     */
    protected array $explicitlyBlockedDirectives = [
        'includeIf'      => '@includeIf directive (dynamic file inclusion)',
        'includeWhen'    => '@includeWhen directive (dynamic file inclusion)',
        'includeUnless'  => '@includeUnless directive (dynamic file inclusion)',
        'includeFirst'   => '@includeFirst directive (dynamic file inclusion)',
        'each'           => '@each directive (dynamic file inclusion)',
        'component'      => '@component directive (arbitrary component loading)',
        'slot'           => '@slot directive',
        'extends'        => '@extends directive (layout inheritance)',
        'livewire'       => '@livewire directive (Livewire component loading)',
        'verbatim'       => '@verbatim directive',
        'inject'         => '@inject directive (service container injection)',
        'eval'           => '@eval directive',
    ];

    /**
     * Запрещённые паттерны в исходнике (не директивы).
     */
    protected array $forbiddenPatterns = [
        '/<\?php/'              => '<?php tag (используйте @php … @endphp)',
        '/<\?=/'                => '<?= tag',
    ];

    public function __construct(private ?DangerousCodeScanner $scanner = null)
    {
        $this->scanner ??= new DangerousCodeScanner();
    }

    /**
     * Проверить Blade-шаблон.
     *
     * Результат кэшируется по содержимому: проверка вызывается и при рендере
     * каждого блока, а компиляция Blade на каждый запрос дорога.
     *
     * @return array<int,string> Список нарушений (пустой массив = всё ок)
     */
    public function validate(string $content): array
    {
        $key = 'cms:blade-guard:'.DangerousCodeScanner::RULES_VERSION.':'.sha1($content);

        return Cache::rememberForever($key, fn () => $this->check($content));
    }

    /** @return array<int,string> */
    protected function check(string $content): array
    {
        $violations = [];

        foreach ($this->forbiddenPatterns as $pattern => $description) {
            if (preg_match($pattern, $content)) {
                $violations[] = $description;
            }
        }

        $violations = array_merge($violations, $this->validateDirectives($content));
        $violations = array_merge($violations, $this->validateCompiled($content));

        return array_values(array_unique($violations));
    }

    public function isValid(string $content): bool
    {
        return empty($this->validate($content));
    }

    /**
     * @throws \Templite\Cms\Exceptions\UnsafeTemplateException
     */
    public function validateOrFail(string $content): void
    {
        $violations = $this->validate($content);

        if (!empty($violations)) {
            throw new \Templite\Cms\Exceptions\UnsafeTemplateException(
                'Шаблон содержит запрещённые конструкции: ' . implode(', ', $violations),
                $violations
            );
        }
    }

    /**
     * @throws \Templite\Cms\Exceptions\UnsafeTemplateException
     */
    public static function assertSafe(string $content): void
    {
        (new static())->validateOrFail($content);
    }

    /** @return array<string, string> */
    public function getForbiddenPatterns(): array
    {
        return $this->forbiddenPatterns;
    }

    /** @return array<int, string> */
    public function getAllowedDirectives(): array
    {
        return $this->allowedDirectives;
    }

    /**
     * Проверить Blade-директивы по whitelist.
     *
     * @return array<int,string>
     */
    protected function validateDirectives(string $content): array
    {
        $violations = [];

        // Директива — @word не внутри email и не CSS at-rule
        if (preg_match_all('/(?<![a-zA-Z0-9._-])@([a-zA-Z][a-zA-Z0-9_]*)/', $content, $matches)) {
            $cssAtRules = [
                'media', 'keyframes', 'import', 'font-face', 'charset',
                'supports', 'layer', 'page', 'property', 'counter-style',
                'namespace', 'container', 'scope', 'starting-style',
            ];

            foreach ($matches[1] as $directive) {
                if (in_array(strtolower($directive), $cssAtRules, true)) {
                    continue;
                }

                if (!in_array($directive, $this->allowedDirectives, true)) {
                    $violations[] = $this->explicitlyBlockedDirectives[$directive]
                        ?? "@{$directive} directive is not allowed";
                }
            }
        }

        return $violations;
    }

    /**
     * Скомпилировать шаблон в PHP и поискать жёсткие возможности.
     *
     * Теги <x-…> заменяются их выражениями: компилятор компонентов ищет класс
     * компонента уже при компиляции, а шаблон законно ссылается на компонент,
     * которого ещё нет (шапку создадут следующим шагом).
     *
     * @return array<int,string>
     */
    protected function validateCompiled(string $content): array
    {
        $withoutComponents = preg_replace_callback(
            '/<\/?x-[^>]*>/s',
            fn (array $m) => $this->componentTagExpressions($m[0]),
            $content
        );

        try {
            $php = Blade::compileString($withoutComponents);
        } catch (\Throwable $e) {
            return ['Шаблон не компилируется: '.$e->getMessage()];
        }

        return $this->scanner->scan($php);
    }

    /**
     * PHP-выражения из тега компонента: связанные атрибуты (:file="…") и {{ }}
     * внутри значений — то, что компонент получит вычисленным.
     */
    protected function componentTagExpressions(string $tag): string
    {
        $out = '';

        if (preg_match_all('/(?<![\w-]):[\w.-]+\s*=\s*"([^"]*)"|(?<![\w-]):[\w.-]+\s*=\s*\'([^\']*)\'/s', $tag, $m, PREG_SET_ORDER)) {
            foreach ($m as $attr) {
                $expr = $attr[1] !== '' ? $attr[1] : ($attr[2] ?? '');
                if (trim($expr) !== '') {
                    $out .= '@php(' . $expr . ')';
                }
            }
        }

        if (preg_match_all('/\{\{.*?\}\}|\{!!.*?!!\}/s', $tag, $m)) {
            $out .= implode('', $m[0]);
        }

        return $out;
    }
}
