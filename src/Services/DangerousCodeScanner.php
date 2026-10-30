<?php

namespace Templite\Cms\Services;

/**
 * Поиск «жёстких» возможностей в PHP-коде: запуск процессов, исполнение строки
 * как кода, вызов функции по имени из строки или переменной, прямая работа с
 * файлами и сетью, подмена окружения.
 *
 * Это НЕ песочница. Право на код блоков, шаблонов, компонентов и actions — это
 * доверие к человеку или клиенту: через app(), фасады и Eloquent доступен весь
 * сервер, и actions без этого не работают. Сканер отсекает очевидные и
 * случайные инъекции вроде `rm -rf` в шаблоне или чтения .env, а не
 * целенаправленный обход.
 *
 * Общий для Blade (по скомпилированному PHP) и actions.
 */
class DangerousCodeScanner
{
    /** Меняется вместе со списками — сбрасывает кэш проверок шаблонов. */
    public const RULES_VERSION = 1;

    /** @var array<string,string> функция => что она даёт */
    private const FUNCTIONS = [
        // Процессы и shell
        'exec' => 'запуск процессов', 'system' => 'запуск процессов', 'shell_exec' => 'запуск процессов',
        'passthru' => 'запуск процессов', 'proc_open' => 'запуск процессов', 'popen' => 'запуск процессов',
        'pcntl_exec' => 'запуск процессов', 'pcntl_fork' => 'запуск процессов',
        // Строка как код и вызов по имени
        'assert' => 'исполнение строки как кода', 'create_function' => 'исполнение строки как кода',
        'unserialize' => 'исполнение кода через десериализацию',
        'call_user_func' => 'вызов функции по имени', 'call_user_func_array' => 'вызов функции по имени',
        'forward_static_call' => 'вызов функции по имени', 'forward_static_call_array' => 'вызов функции по имени',
        'register_shutdown_function' => 'вызов функции по имени', 'register_tick_function' => 'вызов функции по имени',
        // Файлы
        'file_get_contents' => 'работа с файлами', 'file_put_contents' => 'работа с файлами', 'file' => 'работа с файлами',
        'readfile' => 'работа с файлами', 'fopen' => 'работа с файлами', 'fwrite' => 'работа с файлами',
        'fputs' => 'работа с файлами', 'unlink' => 'работа с файлами', 'rename' => 'работа с файлами',
        'copy' => 'работа с файлами', 'chmod' => 'работа с файлами', 'chown' => 'работа с файлами',
        'mkdir' => 'работа с файлами', 'rmdir' => 'работа с файлами', 'touch' => 'работа с файлами',
        'symlink' => 'работа с файлами', 'link' => 'работа с файлами', 'tempnam' => 'работа с файлами',
        'glob' => 'работа с файлами', 'scandir' => 'работа с файлами', 'opendir' => 'работа с файлами',
        'highlight_file' => 'работа с файлами', 'show_source' => 'работа с файлами',
        'parse_ini_file' => 'работа с файлами', 'move_uploaded_file' => 'работа с файлами',
        // Сеть
        'fsockopen' => 'сетевые соединения', 'pfsockopen' => 'сетевые соединения',
        'stream_socket_client' => 'сетевые соединения', 'stream_socket_server' => 'сетевые соединения',
        'curl_exec' => 'сетевые соединения', 'curl_multi_exec' => 'сетевые соединения', 'mail' => 'отправка почты',
        // Окружение
        'ini_set' => 'подмена окружения', 'ini_alter' => 'подмена окружения', 'putenv' => 'подмена окружения',
        'set_include_path' => 'подмена окружения', 'dl' => 'подмена окружения', 'apache_setenv' => 'подмена окружения',
    ];

    /**
     * Имена, которые ловим и в строке-callback (array_map('system', ...)).
     * Только однозначные: 'file', 'link', 'copy', 'mail' — обычные значения
     * (тип поля, ключ массива), их в строке не трогаем.
     */
    private const CALLBACK_NAMES = [
        'exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'assert', 'create_function', 'unserialize',
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
        'file_get_contents', 'file_put_contents', 'readfile', 'unlink', 'symlink',
        'highlight_file', 'show_source', 'fsockopen', 'pfsockopen', 'stream_socket_client',
        'curl_exec', 'putenv', 'ini_set',
    ];

    /** @var array<int,string> */
    private const TOKENS = [
        T_EVAL => 'eval — исполнение строки как кода',
        T_INCLUDE => 'include — подключение файлов',
        T_INCLUDE_ONCE => 'include_once — подключение файлов',
        T_REQUIRE => 'require — подключение файлов',
        T_REQUIRE_ONCE => 'require_once — подключение файлов',
        T_HALT_COMPILER => '__halt_compiler',
    ];

    /**
     * @return array<int,string> нарушения (пусто — жёстких возможностей нет)
     */
    public function scan(string $php): array
    {
        $tokens = @token_get_all($php);
        $violations = [];

        foreach ($tokens as $i => $token) {
            if ($token === '`') {
                $violations[] = 'обратные кавычки — запуск shell-команд';
                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if (isset(self::TOKENS[$id])) {
                $violations[] = self::TOKENS[$id];
                continue;
            }

            $next = $this->next($tokens, $i);
            $callsNext = $next !== null && $tokens[$next] === '(';

            // Вызов функции: exec(...), \exec(...). Методы ->exec() и ::exec() — не функции.
            if (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) && $callsNext && ! $this->isMemberAccess($tokens, $i)) {
                $name = strtolower(ltrim($text, '\\'));
                if (isset(self::FUNCTIONS[$name])) {
                    $violations[] = "{$name}() — ".self::FUNCTIONS[$name];
                }
                continue;
            }

            // $f(...) и 'system'(...) — имя функции вычисляется на лету
            if (($id === T_VARIABLE || $id === T_CONSTANT_ENCAPSED_STRING) && $callsNext && ! $this->isMemberAccess($tokens, $i)) {
                $violations[] = "{$text}() — вызов функции по имени из переменной или строки";
                continue;
            }

            // Строка с именем опасной функции как callback: array_map('system', ...)
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = strtolower(ltrim(trim($text, '\'"'), '\\'));
                if (in_array($value, self::CALLBACK_NAMES, true)) {
                    $violations[] = "'{$value}' — имя опасной функции в строке (callback)";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param  array<int,mixed>  $tokens */
    private function isMemberAccess(array $tokens, int $i): bool
    {
        $prev = $this->prev($tokens, $i);

        return $prev !== null && is_array($tokens[$prev])
            && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
    }

    /** @param  array<int,mixed>  $tokens */
    private function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param  array<int,mixed>  $tokens */
    private function prev(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }
}
