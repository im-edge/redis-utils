<?php

namespace IMEdge\RedisUtils;

use InvalidArgumentException;

class RedisInfo
{
    /**
     * @param array<string,array<string,string>> $info
     */
    public function __construct(public readonly array $info)
    {
    }

    /**
     * @return array<string,string>
     */
    public function getSection(string $name): array
    {
        return $this->info[$name] ?? [];
    }

    /**
     * @return array<string,string>
     */
    public function requireSection(string $name): array
    {
        if (isset($this->info[$name])) {
            return $this->info[$name];
        }

        throw new InvalidArgumentException("There is no '$name' Redis INFO section'");
    }

    public function get(string $sectionName, string $key, ?string $default = null): ?string
    {
        return $this->getSection($sectionName)[$key] ?? $default;
    }

    /**
     * @param string $sectionName
     * @param string[] $properties
     * @return array<string,?string>
     */
    public function getSectionProperties(string $sectionName, array $properties): array
    {
        $result = [];
        $section = $this->getSection($sectionName);
        foreach ($properties as $key) {
            if (array_key_exists($key, $section)) {
                $result[$key] = $section[$key];
            } else {
                $result[$key] = null;
            }
        }

        return $result;
    }
}
