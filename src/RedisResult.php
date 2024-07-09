<?php

namespace IMEdge\RedisUtils;

use RuntimeException;
use stdClass;

class RedisResult
{
    /**
     * Transform [key1, val1, key2, val2] into {key1 => val1, key2 => val2}
     * @param array<int,mixed>|null $data
     */
    public static function toHash(?array $data): ?stdClass
    {
        if ($data === null) {
            return null;
        }

        return (object) static::toArray($data);
    }

    /**
     * Transform [key1, val1, key2, val2] into [key1 => val1, key2 => val2]
     * @param array<int,mixed>|null $data
     * @return array<string|int,mixed>
     */
    public static function toArray(?array $data): array
    {
        if ($data === null) {
            return [];
        }
        $array = [];
        $count = count($data);
        for ($i = 0; $i < $count; $i += 2) {
            $array[$data[$i]] = $data[$i + 1];
        }

        return $array;
    }

    public static function parseInfo(string $infoResultString): RedisInfo
    {
        $info = [];
        $section = null;
        $sectionKey = null;

        $lines = preg_split('/\r?\n/', $infoResultString, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($lines)) {
            throw new RuntimeException('Got invalid Redis INFO string');
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($line[0] === '#') {
                if ($section !== null) {
                    $info[$sectionKey] = $section;
                }
                $sectionKey = substr($line, 2);
                $section = [];
            } else {
                if (str_contains($line, ':')) {
                    list($key, $val) = explode(':', $line, 2);
                    $section[$key] = $val;
                } else {
                    throw new RuntimeException('Got invalid Redis INFO line: ' . $line);
                }
            }
        }

        return new RedisInfo($info);
    }
}
