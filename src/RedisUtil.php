<?php

namespace gipfl\RedisUtils;

use function array_shift;

class RedisUtil
{
    /**
     * Transform [key1, val1, key2, val2] into {key1 => val1, key2 => val2}
     *
     * @param $data
     * @return object
     */
    public static function makeHash($data)
    {
        $hash = (object) [];
        while (null !== ($key = array_shift($data))) {
            $hash->$key = array_shift($data);
        }
/*
$count = count($data);
$hash = (object) [];
for ($i = 0; $i < $count; $i += 2) {
    $hash->{$data[$i]} = $data[$i + 1];
}
 */
        return $hash;
    }
}
