<?php

namespace IMEdge\RedisUtils;

use Amp\Redis\RedisClient;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_key_exists;
use function array_merge;
use function count;
use function file_exists;
use function file_get_contents;
use function preg_match;
use function preg_replace_callback;
use function sha1;
use function sprintf;
use function str_contains;

class LuaScriptRunner
{
    /** @var string[] */
    protected array $files = [];
    /** @var string[] */
    protected array $checkSums = [];
    /** @var array<string,bool> */
    protected array $required = [];

    public function __construct(
        protected readonly RedisClient $redis,
        protected readonly string $scriptDir,
        protected readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @param array{0: ?array<int, string|int>, 1: ?array<int, string|int>} $arguments
     */
    public function __call(string $funcName, array $arguments): mixed
    {
        return $this->runScript($funcName, $arguments[0] ?? [], $arguments[1] ?? []);
    }

    protected function loadFile(string $name): string
    {
        // Well... lib-dir-Handling is hacky...
        self::assertValidFileName($name);
        $candidates = [
            $this->scriptDir,
            $this->scriptDir . '/lib',
        ];
        foreach ($candidates as $dir) {
            $filename = "$dir/$name.lua";
            if (file_exists($filename)) {
                $logFileName = substr($filename, strlen($this->scriptDir) + 1);
                $this->logger->debug("Loading '$logFileName' for '$name'");
                $content = file_get_contents($filename);
                if ($content === false) {
                    throw new RuntimeException("Could not load $filename");
                }

                return $content;
            }
        }

        throw new RuntimeException(sprintf('Cannot load lua script "%s"', $name));
    }

    protected function getScript(string $name): string
    {
        if (! array_key_exists($name, $this->files)) {
            $this->files[$name] = $this->loadScript($name);
        }

        return $this->files[$name];
    }

    protected function loadScript(string $name): string
    {
        $result = preg_replace_callback(
            '/^(\s*require\(\s*)([\'"])((?:lib\/)?[a-z0-9]+)(\2\s*\);?)/mi',
            $this->replaceRequire(...),
            $this->loadFile($name)
        );

        if (is_string($result)) {
            return $result;
        }

        throw new RuntimeException('Failed to load required script');
    }

    /**
     * @param string[] $matches
     */
    protected function replaceRequire(array $matches): string
    {
        $package = $matches[3];
        if (isset($this->required[$package])) {
            return '';
        }

        $script = $this->getScript($package);
        $this->required[$package] = true;

        return $script;
    }

    protected function getScriptChecksum(string $name): string
    {
        return $this->checkSums[$name] ??= sha1($this->getScript($name));
    }

    /**
     * @param array<int, string|int> $keys
     * @param array<int, string|int> $args
     */
    public function runScript(string $name, array $keys = [], array $args = []): mixed
    {
        $this->required = [];
        $checksum = $this->getScriptChecksum($name);
        $params = [$checksum, count($keys)];
        $params = array_merge($params, array_merge($keys, $args));

        try {
            return $this->redis->execute('EVALSHA', ...$params);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'NOSCRIPT')) {
                try {
                    // Replacing checksum with script
                    $params[0] = $this->getScript($name);
                    return $this->sendScript($name, $checksum, $params);
                } catch (Exception $e) {
                    throw $this->niceScriptError($e);
                }
            }

            throw $this->niceScriptError($e);
        }
    }

    /**
     * @param array<int, string|int> $params
     */
    protected function sendScript(string $name, string $checksum, array $params): mixed
    {
        $this->logger->info(sprintf(
            'No SCRIPT with SHA1 == %s, pushing %s.lua',
            $checksum,
            $name
        ));

        return $this->redis->execute('EVAL', ...$params);
    }

    protected function niceScriptError(Exception $e): Exception
    {
        $expression = '/call to f_([a-f0-9]{40})\): @user_script:(\d+): (?:user_script:\d+: )?(.+?)$/';
        if (preg_match($expression, $e->getMessage(), $match)) {
            $checksum = $match[1];
            $line = (int) $match[2];
            $errorMessage = $match[3];
            foreach ($this->checkSums as $name => $sum) {
                if ($sum === $checksum) {
                    $scriptLines = explode("\n", $this->getScript($name));
                    $start = max(0, $line - 4);
                    $end = min(count($scriptLines), $line + 2);
                    $errorContext = array_slice($scriptLines, $start, $end - $start, true);
                    $error = "$name:$line: $errorMessage\n";
                    foreach ($errorContext as $lineNumber => $lineSource) {
                        $realLineNumber = $lineNumber + 1;
                        $error .= "    $realLineNumber: $lineSource\n";
                    }

                    return new RuntimeException(rtrim($error, "\n"));
                }
            }
        }

        return $e;
    }

    protected static function assertValidFileName(string $name): void
    {
        if (! preg_match('/^(?:lib\/)?[a-z0-9]+$/i', $name)) {
            throw new InvalidArgumentException("Trying to access invalid lua script: $name");
        }
    }
}
