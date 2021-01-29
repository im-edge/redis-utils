<?php

namespace gipfl\RedisUtils;

use InvalidArgumentException;
use RuntimeException;
use function explode;
use function preg_split;
use function property_exists;
use function strpos;
use function substr;
use function trim;

class RedisInfo
{
    /** @var object */
    protected $info;

    protected function __construct()
    {
    }

    /**
     * @param $name
     * @return object
     */
    public function getSection($name)
    {
        if (isset($this->info->$name)) {
            return $this->info->$name;
        }

        throw new InvalidArgumentException("There is no '$name' Redis INFO section'");
    }

    /**
     * @param string $sectionName
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public function get($sectionName, $key, $default = null)
    {
        $section = $this->getSection($sectionName);
        if (property_exists($section, $key)) {
            return $section->$key;
        }

        return $default;
    }

    /**
     * @return object
     */
    public function getInfo()
    {
        return $this->info;
    }

    /**
     * @param string $section
     * @param array $properties
     * @return object
     */
    public function getSectionProperties($sectionName, array $properties)
    {
        $result = (object) [];
        $section = $this->getSection($sectionName);
        foreach ($properties as $key) {
            if (property_exists($section, $key)) {
                $result->$key = $section->$key;
            } else {
                $result->$key = null;
            }
        }

        return $result;
    }

    /**
     * @param $infoString
     * @return RedisInfo
     */
    public static function parse($infoString)
    {
        $info = (object) [];
        $section = null;
        $sectionKey = null;

        foreach (preg_split('/\r?\n/', $infoString, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if ($line[0] === '#') {
                if ($section !== null) {
                    $info->$sectionKey = $section;
                }
                $sectionKey = substr($line, 2);
                $section = (object) [];
            } else {
                if (strpos($line, ':') === false) {
                    throw new RuntimeException('Got invalid Redis INFO line: ' . $line);
                }
                list($key, $val) = explode(':', $line, 2);
                $section->$key = $val;
            }
        }

        $self = new static();
        $self->info = $info;

        return $self;
    }
}
