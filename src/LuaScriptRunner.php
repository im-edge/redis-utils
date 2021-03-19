<?php

namespace gipfl\RedisUtils;

use Clue\React\Redis\Client;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use RuntimeException;
use function array_key_exists;
use function array_merge;
use function call_user_func_array;
use function count;
use function file_exists;
use function file_get_contents;
use function preg_match;
use function preg_replace_callback;
use function React\Promise\reject;
use function sprintf;
use function strpos;

class LuaScriptRunner
{
    use LoggerAwareTrait;

    /** @var Client */
    protected $redis;

    /** @var string */
    protected $basedir;

    protected $files = [];

    protected $checkSums = [];

    protected $required = [];

    public function __construct(Client $redis, $luaScriptDir)
    {
        $this->redis = $redis;
        $this->basedir = $luaScriptDir;
        $this->setLogger(new NullLogger());
    }

    public function __call($funcName, $arguments)
    {
        $this->required = [];
        $keys = isset($arguments[0]) ? $arguments[0] : [];
        $args = isset($arguments[1]) ? $arguments[1] : [];

        return $this->runScript($funcName, $keys, $args);
    }

    protected function replaceRequire($matches)
    {
        $package = $matches[3];
        if (isset($this->required[$package])) {
            return '';
        }

        $script = $this->getScript($package);
        $this->required[$package] = true;

        return $script;
    }

    protected function loadFile($name)
    {
        // Well... lib-dir-Handling is hacky...
        $this->assertValidFileName($name);
        $candidates = [
            $this->basedir,
            $this->basedir . '/lib',
        ];
        foreach ($candidates as $dir) {
            $filename = "$dir/$name.lua";
            if (file_exists($filename)) {
                $this->logger->debug("Loading '$filename' for '$name'");
                return file_get_contents($filename);
            }
        }

        throw new RuntimeException(sprintf(
            'Cannot load lua script "%s"',
            $name
        ));
    }

    protected function assertValidFileName($name)
    {
        if (! preg_match('/^(?:lib\/)?[a-z0-9]+$/i', $name)) {
            throw new InvalidArgumentException(
                'Trying to access invalid lua script: %s',
                $name
            );
        }
    }

    protected function asyncRedisFunc($funcName, $params)
    {
        return call_user_func_array([$this->redis, $funcName], $params);
    }

    protected function getScript($name)
    {
        if (! array_key_exists($name, $this->files)) {
            $this->files[$name] = $this->loadScript($name);
        }

        return $this->files[$name];
    }

    protected function loadScript($name)
    {
        return preg_replace_callback(
            '/^(\s*require\(\s*)([\'"])((?:lib\/)?[a-z0-9]+)(\2\s*\);?)/mi',
            [$this, 'replaceRequire'],
            $this->loadFile($name)
        );
    }

    protected function getScriptChecksum($name)
    {
        if (! array_key_exists($name, $this->checkSums)) {
            $this->checkSums[$name] = sha1(
                $this->getScript($name)
            );

            $debug = false;
            // Debug for errors. Superseded by new error context logic:
            if ($debug) {
                $a = 0;
                $this->logger->debug(sprintf(
                    'Script %s (%s):',
                    $name,
                    $this->checkSums[$name]
                ));
                foreach (explode("\n", $this->getScript($name)) as $line) {
                    $this->logger->debug(sprintf('%d: %s', ++$a, $line));
                }
            }
        }

        return $this->checkSums[$name];
    }

    /**
     * @param $name
     * @param array $keys
     * @param array $args
     * @param bool $firstTime
     * @return \React\Promise\ExtendedPromiseInterface
     */
    public function runScript($name, $keys = [], $args = [], $firstTime = true)
    {
        try {
            $checksum = $this->getScriptChecksum($name);
            $params = [$checksum, count($keys)];
            $params = array_merge($params, array_merge($keys, $args));
        } catch (Exception $e) {
            return reject($e);
        }

        return $this->asyncRedisFunc('evalsha', $params)
            ->otherwise(function (Exception $e) use (
                $name,
                $checksum,
                $params,
                & $firstTime
            ) {
                if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                    if ($firstTime) {
                        $firstTime = false;
                    } else {
                        throw $e;
                    }
                    $this->logger->info(sprintf(
                        'No SCRIPT with SHA1 == %s, pushing %s.lua',
                        $checksum,
                        $name
                    ));

                    $params[0] = $this->getScript($name);

                    return $this->asyncRedisFunc('eval', $params);
                }

                return reject($this->eventuallyTransformScriptError($e));
            });
    }

    protected function eventuallyTransformScriptError(Exception $e)
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
                    $error = sprintf("$name:$line: $errorMessage\n");
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
}
