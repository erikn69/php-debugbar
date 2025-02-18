<?php

namespace DebugBar\DataCollector;

use DebugBar\DataCollector\PDO\PDOCollector;
use DebugBar\DataCollector\TimeDataCollector;

class QueryCollector extends PDOCollector
{
    protected $queryCount = 0;
    protected $transactionEventsCount = 0;
    protected $findSource = false;
    protected $renderSqlWithParams = false;
    protected $excludePaths = array();
    protected $backtraceExcludePaths = array();
    
    /**
     * @param TimeDataCollector $timeCollector
     */
    public function __construct(TimeDataCollector $timeCollector = null)
    {
        $this->timeCollector = $timeCollector;
    }
    
    /**
     * Reset the queries.
     */
    public function reset()
    {
        $this->connections = array();
        $this->queryCount = 0;
        $this->transactionEventsCount = 0 ;
    }

    /**
     * Renders the SQL of traced statements with params embedded
     *
     * @param boolean $enabled
     * @param string $quotationChar NOT USED
     */
    public function setRenderSqlWithParams($enabled = true, $quotationChar = "'")
    {
        $this->renderSqlWithParams = $enabled;
    }
    
    private function startConnection ($connectionName = 'default') {
        if (!isset($this->connections[$connectionName])) {
            $this->connections[$connectionName] = array();
        }
    }

    public function addQuery($query, $bindings = array(), $connectionName = 'default', $other = array())
    {
        $this->startConnection($connectionName);
        $this->queryCount++;
        
        
        $query = (string) $query;
        $time = (isset($other['time']) ? $other['time'] : 0) / 1000;
        $endTime = microtime(true);
        $startTime = $endTime - $time;
        
        $source = array();

        if ($this->findSource) {
            try {
                $source = $this->findSource();
            } catch (\Exception $e) {
            }
        }
        
        $this->connections[$connectionName][] = array_merge(array(
            'sql' => $query,
            'type' => 'query',
            'bindings' => $bindings,
            'start' => $startTime,
            'duration' => $time,
            'memory' => 0, //$this->lastMemoryUsage ? memory_get_usage(false) - $this->lastMemoryUsage : 0,
            'source' => $source,
            'connection' => $connectionName,
            'driver' => isset($other['driver']) ? $other['driver'] : '',
        ), $other);

        if ($this->timeCollector !== null) {
            $this->timeCollector->addMeasure(Str::limit($sql, 100), $startTime, $endTime, array(), 'db', 'Database Query');
        }
    }

    public function addTransactionEvent($event, $connectionName = 'default', $other = array())
    {        
        $this->startConnection($connectionName);
        $this->transactionEventsCount++;
        $source = array();

        if ($this->findSource) {
            try {
                $source = $this->findSource();
            } catch (\Exception $e) {
            }
        }

        $this->connections[$connectionName][] = array_merge(array(
            'sql' => $event,
            'type' => 'transaction',
            'start' => microtime(true),
            'duration' => 0,
            'memory' => 0,
            'source' => $source,
            'connection' => $connectionName,
            'driver' => isset($other['driver']) ? $other['driver'] : '',
        ), $other);
    }
    
    /**
     * Enable/disable finding the source
     *
     * @param bool|int $value
     */
    public function setFindSource($value)
    {
        $this->findSource = $value;
    }
    
    /**
     * Use a backtrace to search for the origins of the query.
     *
     * @return array
     */
    protected function findSource()
    {
        $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 40);

        $sources = array();

        foreach ($stack as $index => $trace) {
            $sources[] = $this->parseTrace($index, $trace);
        }

        return array_slice(array_filter($sources), 0, is_int($this->findSource) ? $this->findSource : 5);
    }

    /**
     * Parse a trace element from the backtrace stack.
     *
     * @param  int    $index
     * @param  array  $trace
     * @return object|bool
     */
    protected function parseTrace($index, array $trace)
    {
        $frame = array(
            'index' => $index,
            'namespace' => null,
            'name' => null,
            'file' => null,
            'line' => !isset($trace['line']) || empty($trace['line']) ? '1' : $trace['line'],
        );

        if (isset($trace['function'])) {
            $fram['name'] = 'function';

            return $frame;
        }

        if (
            isset($trace['class']) &&
            isset($trace['file']) &&
            !$this->fileIsInExcludedPath($trace['file'])
        ) {
            $frame['file'] = $trace['file'];
            $frame['name'] = $this->normalizeFilePath($frame['file']);

            return $frame;
        }


        return false;
    }
    
    /**
     * Check if the given file is to be excluded from analysis
     *
     * @param string $file
     * @return bool
     */
    protected function fileIsInExcludedPath($file)
    {
        $normalizedPath = str_replace('\\', '/', $file);

        foreach ($this->backtraceExcludePaths as $excludedPath) {
            if (strpos($normalizedPath, $excludedPath) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getSqlQueryToDisplay(array $query)
    {
        $sql = $query['sql'];

        if ($query['type'] === 'query' && $this->renderSqlWithParams) {
            $bindings = $this->getDataFormatter()->checkBindings($query['bindings']);
            if (!empty($bindings)) {
                foreach ($bindings as $key => $binding) {
                    // This regex matches placeholders only, not the question marks,
                    // nested in quotes, while we iterate through the bindings
                    // and substitute placeholders by suitable values.
                    $regex = is_numeric($key)
                        ? "/(?<!\?)\?(?=(?:[^'\\\']*'[^'\\']*')*[^'\\\']*$)(?!\?)/"
                        : "/:{$key}(?=(?:[^'\\\']*'[^'\\\']*')*[^'\\\']*$)/";

                    // Mimic bindValue and only quote non-integer and non-float data types
                    if (!is_int($binding) && !is_float($binding)) {                        
                        $binding = $this->getDataFormatter()->emulateQuote($binding);
                    }

                    $sql = preg_replace($regex, addcslashes($binding, '$'), $sql, 1);
                }
            }
        }

        return $this->getDataFormatter()->formatSql($sql);
    }

    public function collect()
    {   
        $totalTime = 0;
        $totalMemory = 0;
        $statements = array();

        foreach ($this->connections as $name => $queries) {
            foreach ($queries as $query) {
                $source = reset($query['source']);
                $normalizedPath = is_array($source) ? $this->normalizeFilePath($source['file'] ?: '') : '';
                if ($query['type'] != 'transaction' && $normalizedPath && $this->fileIsInExcludedPath($normalizedPath)) {
                    continue;
                }

                $totalTime += $query['duration'];
                $totalMemory += $query['memory'];
                
                $statements[] = array_merge($query, array(
                    'sql' => $this->getSqlQueryToDisplay($query),
                    //'backtrace' => array_values($query['source']),
                    'duration_str' => ($query['type'] == 'transaction') ? '' : $this->formatDuration($query['duration']),
                    'memory_str' => $query['memory'] ? $this->getDataFormatter()->formatBytes($query['memory']) : null,
                    //'filename' => $this->getDataFormatter()->formatSource($source, true),
                    //'source' => $source,
                    //'xdebug_link' => is_object($source) ? $this->getXdebugLink($source->file ?: '', $source->line) : null,
                ));
            }
        }

        $data = array(
            'nb_statements' => $this->queryCount,
            'nb_visible_statements' => count($statements),
            'nb_excluded_statements' => $this->queryCount + $this->transactionEventsCount,
            'nb_failed_statements' => 0,
            'accumulated_duration' => $totalTime,
            'accumulated_duration_str' => $this->formatDuration($totalTime),
            'memory_usage' => $totalMemory,
            'memory_usage_str' => $totalMemory ? $this->getDataFormatter()->formatBytes($totalMemory) : null,
            'statements' => $statements
        );

        return $data;
    }

    public function getName()
    {
        return 'queries';
    }
}
