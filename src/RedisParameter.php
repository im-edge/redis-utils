<?php

namespace IMEdge\RedisUtils;

use RuntimeException;
use stdClass;

class RedisParameter
{
    /**
     * @param array<string|int,mixed> $array
     * @return array<int,mixed>
     */
    public static function table(array $array): array
    {
        $result = [];
        foreach ($array as $k => $v) {
            $result[] = $k;
            $result[] = $v;
        }

        return $result;
    }
}
