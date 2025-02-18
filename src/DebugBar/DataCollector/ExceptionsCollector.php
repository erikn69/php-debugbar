<?php
/*
 * This file is part of the DebugBar package.
 *
 * (c) 2013 Maxime Bouroumeau-Fuseau
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DebugBar\DataCollector;

use Exception;
use Symfony\Component\Debug\Exception\FatalThrowableError;

/**
 * Collects info about exceptions
 */
class ExceptionsCollector extends DataCollector implements Renderable
{
    public $exceptions = array();
    protected $chainExceptions = false;

    /**
     * Start collecting warnings, notices and deprecations
     */
    public function collectWarnings() {
        $self = $this;
        $errorTypes = array(
            1    => 'E_ERROR',
            2    => 'E_WARNING',
            4    => 'E_PARSE',
            8    => 'E_NOTICE',
            16   => 'E_CORE_ERROR',
            32   => 'E_CORE_WARNING',
            64   => 'E_COMPILE_ERROR',
            128  => 'E_COMPILE_WARNING',
            256  => 'E_USER_ERROR',
            512  => 'E_USER_WARNING',
            1024 => 'E_USER_NOTICE',
            2048 => 'E_STRICT',
            4096 => 'E_RECOVERABLE_ERROR',
            8192 => 'E_DEPRECATED',
            16384 => 'E_USER_DEPRECATED'
        );

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($self, $errorTypes) {
            $self->exceptions[] = array(
                'type' => isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'UNKNOWN',
                'message' => $errstr,
                'code' => $errno,
                'file' => $self->normalizeFilePath($errfile),
                'line' => $errline,
                'stack_trace' => null,
                'stack_trace_html' => null,
                'surrounding_lines' => null,
                'xdebug_link' => empty($errfile) ? null : $self->getXdebugLink($errfile, $errline)
            );
        });
    }

    /**
     * Adds an exception to be profiled in the debug bar
     *
     * @param Exception $e
     * @deprecated in favor on addThrowable
     */
    public function addException(Exception $e)
    {
        $this->addThrowable($e);
    }

    /**
     * Adds a Throwable to be profiled in the debug bar
     *
     * @param \Throwable $e
     */
    public function addThrowable($e)
    {
        $this->exceptions[] = $e;
        if ($this->chainExceptions && $previous = $e->getPrevious()) {
            $this->addThrowable($previous);
        }
    }

    /**
     * Configure whether or not all chained exceptions should be shown.
     *
     * @param bool $chainExceptions
     */
    public function setChainExceptions($chainExceptions = true)
    {
        $this->chainExceptions = $chainExceptions;
    }

    /**
     * Returns the list of exceptions being profiled
     *
     * @return array[\Throwable]
     */
    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function collect()
    {
        return array(
            'count' => count($this->exceptions),
            'exceptions' => array_map(array($this, 'formatThrowableData'), $this->exceptions)
        );
    }

    /**
     * Returns exception data as an array
     *
     * @param Exception $e
     * @return array
     * @deprecated in favor on formatThrowableData
     */
    public function formatExceptionData(Exception $e)
    {
        return $this->formatThrowableData($e);
    }

    /**
     * Returns Throwable trace as an formated array
     *
     * @return array
     */
    public function formatTrace(array $trace)
    {
        if (! empty($this->xdebugReplacements)) {
            $trace = array_map(function ($track) {
                if (isset($track['file'])) {
                    $track['file'] = $this->normalizeFilePath($track['file']);
                }
                return $track;
            }, $trace);
        }

        // Remove large objects from the trace
        $trace = array_map(function ($track) {
            if (isset($track['args'])) {
                foreach ($track['args'] as $key => $arg) {
                    if (is_object($arg)) {
                        $track['args'][$key] = '[object ' . $this->getDataFormatter()->formatClassName($arg) . ']';
                    }
                }
            }
            return $track;
        }, $trace);

        return $trace;
    }

    /**
     * Returns Throwable data as an string
     *
     * @param \Throwable $e
     * @return string
     */
    public function formatTraceAsString($e)
    {
        if (! empty($this->xdebugReplacements)) {
            return implode("\n", array_map(array($this, 'formatTrack'), explode("\n", $e->getTraceAsString())));
        }

        return $e->getTraceAsString();
    }

    private function formatTrack($track)
    {
        $track = explode(' ', $track);
        if (isset($track[1])) {
            $track[1] = $this->normalizeFilePath($track[1]);
        }

        return implode(' ', $track);
    }

    /**
     * Returns Throwable data as an array
     *
     * @param \Throwable|array $e
     * @return array
     */
    public function formatThrowableData($e)
    {
        if (is_array($e)) {
            return $e;
        }

        $filePath = $e->getFile();
        if ($filePath && file_exists($filePath)) {
            $lines = file($filePath);
            $start = $e->getLine() - 4;
            $lines = array_slice($lines, $start < 0 ? 0 : $start, 7);
        } else {
            $lines = array('Cannot open the file ('.$this->normalizeFilePath($filePath).') in which the exception occurred');
        }

        $traceHtml = null;
        if ($this->isHtmlVarDumperUsed()) {
            $traceHtml = $this->getVarDumper()->renderVar($this->formatTrace($e->getTrace()));
        }

        return array(
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $this->normalizeFilePath($filePath),
            'line' => $e->getLine(),
            'stack_trace' => $traceHtml ? null : $this->formatTraceAsString($e),
            'stack_trace_html' => $traceHtml,
            'surrounding_lines' => $lines,
            'xdebug_link' => $this->getXdebugLink($filePath, $e->getLine())
        );
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'exceptions';
    }

    /**
     * @return array
     */
    public function getWidgets()
    {
        return array(
            'exceptions' => array(
                'icon' => 'bug',
                'widget' => 'PhpDebugBar.Widgets.ExceptionsWidget',
                'map' => 'exceptions.exceptions',
                'default' => '[]'
            ),
            'exceptions:badge' => array(
                'map' => 'exceptions.count',
                'default' => 'null'
            )
        );
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
