<?php
/*
 * This file is part of the DebugBar package.
 *
 * (c) 2013 Maxime Bouroumeau-Fuseau
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DebugBar\DataFormatter;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class DataFormatter implements DataFormatterInterface
{
    public $cloner;

    public $dumper;

    /**
     * DataFormatter constructor.
     */
    public function __construct()
    {
        $this->cloner = new VarCloner();
        $this->dumper = new CliDumper();
    }

    /**
     * @param $data
     * @return string
     */
    public function formatVar($data)
    {
        $output = '';

        $this->dumper->dump(
            $this->cloner->cloneVar($data),
            function ($line, $depth) use (&$output) {
                // A negative depth means "end of dump"
                if ($depth >= 0) {
                    // Adds a two spaces indentation to the line
                    $output .= str_repeat('  ', $depth).$line."\n";
                }
            }
        );

        return trim($output);
    }

    /**
     * @param float $seconds
     * @return string
     */
    public function formatDuration($seconds)
    {
        if ($seconds < 0.001) {
            return round($seconds * 1000000) . 'μs';
        } elseif ($seconds < 0.1) {
            return round($seconds * 1000, 2) . 'ms';
        } elseif ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        }
        return round($seconds, 2) . 's';
    }

    /**
     * @param string $size
     * @param int $precision
     * @return string
     */
    public function formatBytes($size, $precision = 2)
    {
        if ($size === 0 || $size === null) {
            return "0B";
        }

        $sign = $size < 0 ? '-' : '';
        $size = abs($size);

        $base = log($size) / log(1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return $sign . round(pow(1024, $base - floor($base)), $precision) . $suffixes[(int) floor($base)];
    }

    /**
     * @param object $object
     * @return string
     */
    public function formatClassName($object)
    {
        $class = \get_class($object);

        if (false === ($pos = \strpos($class, "@anonymous\0"))) {
            return $class;
        }

        if (false === ($parent = \get_parent_class($class))) {
            return \substr($class, 0, $pos + 10);
        }

        return $parent . '@anonymous';
    }
    
    /**
     * Removes extra spaces at the beginning and end of the SQL query and its lines.
     *
     * @param  string $sql
     * @return string
     */
    public function formatSql($sql)
    {
        $sql = preg_replace("/\?(?=(?:[^'\\\']*'[^'\\']*')*[^'\\\']*$)(?:\?)/", '?', $sql);
        $sql = trim(preg_replace("/\s*\n\s*/", "\n", $sql));

        return $sql;
    }

    /**
     * Check bindings for illegal (non UTF-8) strings, like Binary data.
     *
     * @param $bindings
     * @return mixed
     */
    public function checkBindings($bindings)
    {
        foreach ($bindings as &$binding) {
            if (is_string($binding) && !mb_check_encoding($binding, 'UTF-8')) {
                $binding = '[BINARY DATA]';
            }

            if (is_array($binding)) {
                $binding = $this->checkBindings($binding);
                $binding = '[' . implode(',', $binding) . ']';
            }

            if (is_object($binding)) {
                $binding =  json_encode($binding);
            }
        }

        return $bindings;
    }

    /**
     * Format a source object.
     *
     * @param  array|null  $source  If the backtrace is disabled, the $source will be null.
     * @return string
     */
    public function formatSource($source, $short = false)
    {
        if (! is_array($source)) {
            return '';
        }

        $parts = array();

        if (!$short && isset($source['namespace'])) {
            $parts['namespace'] = $source['namespace'] . '::';
        }

        $name = isset($source['name']) ? $source['name'] : (isset($source['file']) ? $source['file'] : '');
        $parts['name'] = $short ? basename($name) : $name;
        $parts['line'] = ':' . (isset($source['line']) ? $source['line'] : '1');

        return implode($parts);
    }

    /**
     * Mimic mysql_real_escape_string
     *
     * @param string $value
     * @return string
     */
    public function emulateQuote($value)
    {
        $search = array("\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a");
        $replace = array("\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z");

        return "'" . str_replace($search, $replace, (string) $value) . "'";
    }
}
