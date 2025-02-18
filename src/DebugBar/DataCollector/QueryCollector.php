<?php

namespace DebugBar\DataCollector;

use DebugBar\DataCollector\PDO\PDOCollector;
use DebugBar\DataCollector\TimeDataCollector;

class QueryCollector extends PDOCollector
{
    protected $driver = 0;
    protected $queryCount = 0;
    protected $transactionEventsCount = 0;
    protected $lastMemoryUsage = 0;
    protected $lastTimeStart = 0;
    protected $findSource = false;
    protected $renderSqlWithParams = false;
    protected $excludePaths = array();
    protected $backtraceExcludePaths = array(
        '/debugbar/src/DebugBar/DataCollector',
        '/DebugHelper.php',
        '/DebugBar.php',
        '/DATA/'
    );

    /**
     * @param TimeDataCollector $timeCollector
     */
    public function __construct(TimeDataCollector $timeCollector = null, $driver = 'MySQL')
    {
        $this->timeCollector = $timeCollector;
        $this->driver = $driver;
    }

    public function setTimeline(TimeDataCollector $timeCollector = null)
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
        $this->lastMemoryUsage = 0;
        $this->lastTimeStart = 0;
    }

    public function startQueryMeasure()
    {
        $this->lastMemoryUsage = memory_get_usage(false);
        $this->lastTimeStart = microtime(true);
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

    public function addQuery($query, $other = array())
    {
        $connectionName = isset($other['connection']) ? $other['connection'] : (isset($other['db']) ? $other['db'] : 'default');
        $this->startConnection($connectionName);
        $this->queryCount++;


        $query = implode(' ', array_map(function ($line) {
            return preg_match('/^\s*--/', $line) ? "/* ".ltrim(trim($line), ' -')." */" : trim($line);
        }, explode("\n", rtrim((string) $query, " ;\n\r") . ';')));
        $endTime = microtime(true);
        $time = isset($other['time']) ? $other['time'] : ($this->lastTimeStart ? $endTime - $this->lastTimeStart : 0);
        $startTime = $endTime - $time;

        $source = array();

        if ($this->findSource) {
            try {
                $source = $this->findSource();
            } catch (\Exception $e) {
            }
        }

        $memoryUsage = $this->lastMemoryUsage ? memory_get_usage(false) - $this->lastMemoryUsage : 0;
        $this->connections[$connectionName][] = array_merge(array(
            'sql' => $query,
            'type' => 'query',
            'start' => $startTime,
            'duration' => $time,
            'memory' => $this->lastMemoryUsage ? memory_get_usage(false) - $this->lastMemoryUsage : 0,
            'backtrace' => $source,
            'connection' => $connectionName,
            'driver' => isset($other['driver']) ? $other['driver'] : '',
        ), $other);

        $this->lastMemoryUsage = 0;
        $this->lastTimeStart = 0;

        if ($this->timeCollector !== null) {
            $this->timeCollector->addMeasure(substr($query, 0, 100), $startTime, $endTime, array('memoryUsage' => $memoryUsage), 'db', 'Database Query');
        }
    }

    public function addTransactionEvent($event, $other = array())
    {
        $connectionName = isset($other['connection']) ? $other['connection'] : (isset($other['db']) ? $other['db'] : 'default');
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
            'backtrace' => $source,
            'connection' => $connectionName,
            'driver' => isset($other['driver']) ? $other['driver'] : '',
        ), $other);
    }

    public function addComment($comment, $other = array())
    {
        $this->addTransactionEvent('-- '.$comment, array_merge(array('comment' => true), $other));
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
        $stack = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT), 0, 40);

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
                $sources = array_values($query['backtrace']);
                $normalizedPath = isset($sources[0]['file']) ? $this->normalizeFilePath($sources[0]['file'] ?: '') : '';
                if ($query['type'] != 'transaction' && $normalizedPath && $this->fileIsInExcludedPath($normalizedPath)) {
                    continue;
                }

                $totalTime += $query['duration'];
                $totalMemory += $query['memory'];

                $statements[] = array_merge($query, array(
                    'sql' => $this->getSqlQueryToDisplay($query),
                    'backtrace' => $sources,
                    'duration_str' => ($query['type'] == 'transaction') ? '' : $this->getDataFormatter()->formatDuration($query['duration']),
                    'memory_str' => $query['memory'] ? $this->getDataFormatter()->formatBytes($query['memory']) : null,
                    'filename' => isset($sources[0]) ? basename($this->getDataFormatter()->formatSource($sources[0])) : null,
                    'xdebug_link' => isset($sources[0]['file']) ? $this->getXdebugLink($sources[0]['file'] ?: '', $sources[0]['line']) : null,
                ));
            }
        }

        if (empty($statements)) return array();

        if ($this->durationBackground && $totalTime > 0) {
            // For showing background measure on Queries tab
            $start_percent = 0;
            foreach ($statements as $i => $stmt) {
                if (!isset($stmt['duration']) || empty($stmt['duration'])) {
                    continue;
                }

                $width_percent = $stmt['duration'] / $totalTime * 100;
                $statements[$i] = array_merge($stmt, array(
                    'start_percent' => round($start_percent, 3),
                    'width_percent' => round($width_percent, 3),
                ));
                $start_percent += $width_percent;
            }
        }

        $data = array(
            'nb_statements' => $this->queryCount,
            'nb_visible_statements' => count($statements),
            'nb_excluded_statements' => $this->queryCount + $this->transactionEventsCount,
            'nb_failed_statements' => 0,
            'accumulated_duration' => $totalTime,
            'accumulated_duration_str' => $this->getDataFormatter()->formatDuration($totalTime),
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

    protected $xdebugLinkTemplate = '';
    protected $xdebugShouldUseAjax = false;
    protected $xdebugReplacements = array();

    /**
     * Shorten the file path by removing the xdebug path replacements
     *
     * @param string $file
     * @return string
     */
    public function normalizeFilePath($file)
    {
        if (empty($file)) {
            return '';
        }

        if (@file_exists($file)) {
            $file = realpath($file);
        }

        foreach (array_keys($this->xdebugReplacements) as $path) {
            if (strpos($file, $path) === 0) {
                $file = substr($file, strlen($path));
                break;
            }
        }

        return ltrim(str_replace('\\', '/', $file), '/');
    }

    /**
     * Get an Xdebug Link to a file
     *
     * @param string $file
     * @param int|null $line
     *
     * @return array {
     * @var string   $url
     * @var bool     $ajax should be used to open the url instead of a normal links
     * }
     */
    public function getXdebugLink($file, $line = null)
    {
        if (empty($file)) {
            return null;
        }

        if (@file_exists($file)) {
            $file = realpath($file);
        }

        foreach ($this->xdebugReplacements as $path => $replacement) {
            if (strpos($file, $path) === 0) {
                $file = $replacement . substr($file, strlen($path));
                break;
            }
        }

        $url = strtr($this->getXdebugLinkTemplate(), array(
            '%f' => rawurlencode(str_replace('\\', '/', $file)),
            '%l' => rawurlencode((string) $line ?: 1),
        ));
        if ($url) {
            return array(
                'url' => $url,
                'ajax' => $this->getXdebugShouldUseAjax(),
                'filename' => basename($file),
                'line' => (string) $line ?: '?'
            );
        }
    }

    /**
     * @return string
     */
    public function getXdebugLinkTemplate()
    {
        if (empty($this->xdebugLinkTemplate)) {
            $ini = ini_get('xdebug.file_link_format');
            if (!empty($ini))
                $this->xdebugLinkTemplate = ini_get('xdebug.file_link_format');
        }

        return $this->xdebugLinkTemplate;
    }

    /**
     * @param string $editor
     */
    public function setEditorLinkTemplate($editor)
    {
        $editorLinkTemplates = array(
            'sublime' => 'subl://open?url=file://%f&line=%l',
            'textmate' => 'txmt://open?url=file://%f&line=%l',
            'emacs' => 'emacs://open?url=file://%f&line=%l',
            'macvim' => 'mvim://open/?url=file://%f&line=%l',
            'codelite' => 'codelite://open?file=%f&line=%l',
            'phpstorm' => 'phpstorm://open?file=%f&line=%l',
            'phpstorm-remote' => 'javascript:(()=>{let r=new XMLHttpRequest;' .
                'r.open(\'get\',\'http://localhost:63342/api/file/%f:%l\');r.send();})()',
            'idea' => 'idea://open?file=%f&line=%l',
            'idea-remote' => 'javascript:(()=>{let r=new XMLHttpRequest;' .
                'r.open(\'get\',\'http://localhost:63342/api/file/?file=%f&line=%l\');r.send();})()',
            'vscode' => 'vscode://file/%f:%l',
            'vscode-insiders' => 'vscode-insiders://file/%f:%l',
            'vscode-remote' => 'vscode://vscode-remote/%f:%l',
            'vscode-insiders-remote' => 'vscode-insiders://vscode-remote/%f:%l',
            'vscodium' => 'vscodium://file/%f:%l',
            'nova' => 'nova://open?path=%f&line=%l',
            'xdebug' => 'xdebug://%f@%l',
            'atom' => 'atom://core/open/file?filename=%f&line=%l',
            'espresso' => 'x-espresso://open?filepath=%f&lines=%l',
            'netbeans' => 'netbeans://open/?f=%f:%l',
            'cursor' => 'cursor://file/%f:%l',
        );

        if (is_string($editor) && isset($editorLinkTemplates[$editor])) {
            $this->setXdebugLinkTemplate($editorLinkTemplates[$editor]);
        }
    }

    /**
     * @param string $xdebugLinkTemplate
     * @param bool $shouldUseAjax
     */
    public function setXdebugLinkTemplate($xdebugLinkTemplate, $shouldUseAjax = false)
    {
        if ($xdebugLinkTemplate === 'idea') {
            $this->xdebugLinkTemplate  = 'http://localhost:63342/api/file/?file=%f&line=%l';
            $this->xdebugShouldUseAjax = true;
        } else {
            $this->xdebugLinkTemplate  = $xdebugLinkTemplate;
            $this->xdebugShouldUseAjax = $shouldUseAjax;
        }
    }

    /**
     * @return bool
     */
    public function getXdebugShouldUseAjax()
    {
        return $this->xdebugShouldUseAjax;
    }

    /**
     * returns an array of filename-replacements
     *
     * this is useful f.e. when using vagrant or remote servers,
     * where the path of the file is different between server and
     * development environment
     *
     * @return array key-value-pairs of replacements, key = path on server, value = replacement
     */
    public function getXdebugReplacements()
    {
        return $this->xdebugReplacements;
    }

    /**
     * @param array $xdebugReplacements
     */
    public function addXdebugReplacements($xdebugReplacements)
    {
        foreach ($xdebugReplacements as $serverPath => $replacement) {
            $this->setXdebugReplacement($serverPath, $replacement);
        }
    }

    /**
     * @param array $xdebugReplacements
     */
    public function setXdebugReplacements($xdebugReplacements)
    {
        $this->xdebugReplacements = $xdebugReplacements;
    }

    /**
     * @param string $serverPath
     * @param string $replacement
     */
    public function setXdebugReplacement($serverPath, $replacement)
    {
        $this->xdebugReplacements[$serverPath] = $replacement;
    }
}
