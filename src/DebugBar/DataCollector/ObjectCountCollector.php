<?php

namespace DebugBar\DataCollector;

use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\DataCollectorInterface;
use DebugBar\DataCollector\Renderable;

/**
 * Collector for hit counts.
 */
class ObjectCountCollector extends DataCollector implements DataCollectorInterface, Renderable
{
    /** @var string */
    private $name;
    /** @var string */
    private $icon;
    /** @var int */
    protected $classCount = 0;
    /** @var array */
    protected $classList = array();

    /**
     * @param string $name
     * @param string $icon
     */
    public function __construct($name = 'counter', $icon = 'cubes')
    {
        $this->name = $name;
        $this->icon = $icon;
    }

    /**
     * @param string|mixed $class
     * @param int $count
     */
    public function countClass($class, $count = 1) {
        if (! is_string($class)) {
            $class = get_class($class);
        }

        $this->classList[$class] = (isset($this->classList[$class])?$this->classList[$class]:0) + $count;
        $this->classCount += $count;
    }

    /**
     * {@inheritDoc}
     */
    public function collect()
    {
        arsort($this->classList, SORT_NUMERIC);

        if (! $this->getXdebugLinkTemplate()) {
            return array('data' => $this->classList, 'count' => $this->classCount, 'is_counter' => true);
        }

        $data = array();
        foreach ($this->classList as $class => $count) {
            $reflector = class_exists($class) ? new \ReflectionClass($class) : null;

            if ($reflector && $link = $this->getXdebugLink($reflector->getFileName())) {
                $data[$class] = array(
                    'value' => $count,
                    'xdebug_link' => $link,
                );
            } else {
                $data[$class] = $count;
            }
        }

        return array('data' => $data, 'count' => $this->classCount, 'is_counter' => true);
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getWidgets()
    {
        $name = $this->getName();

        return array(
            "$name" => array(
                'icon' => $this->icon,
                'widget' => 'PhpDebugBar.Widgets.HtmlVariableListWidget',
                'map' => "$name.data",
                'default' => '{}'
            ),
            "$name:badge" => array(
                'map' => "$name.count",
                'default' => 0
            )
        );
    }
}
