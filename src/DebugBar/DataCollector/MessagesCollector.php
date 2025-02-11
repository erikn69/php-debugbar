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

use Psr\Log\AbstractLogger;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataFormatter\DataFormatter;

/**
 * Provides a way to log messages
 */
class MessagesCollector extends AbstractLogger implements DataCollectorInterface, MessagesAggregateInterface, Renderable, AssetProvider
{
    protected $name;

    protected $messages = array();

    protected $aggregates = array();

    /** @var bool */
    protected $collectFile = false;

    /**
     * @param string $name
     */
    public function __construct($name = 'messages')
    {
        $this->name = $name;
    }

    /** @return void */
    public function collectFileTrace($enabled = true)
    {
        $this->collectFile = $enabled;
    }

    /**
     * @param string|null $messageHtml
     * @param mixed $message
     *
     * @return string|null
     */
    protected function customizeMessageHtml($messageHtml, $message)
    {
        $pos = strpos((string) $messageHtml, 'sf-dump-expanded');
        if ($pos !== false) {
            $messageHtml = substr_replace($messageHtml, 'sf-dump-compact', $pos, 16);
        }

        return $messageHtml;
    }

    /**
     * Adds a message
     *
     * A message can be anything from an object to a string
     *
     * @param mixed $message
     * @param string $label
     */
    public function addMessage($message, $label = 'info', $isString = true)
    {
        $messageText = $message;
        $messageHtml = null;
        if (!is_string($message)) {
            // Send both text and HTML representations; the text version is used for searches
            $messageText = $this->getDataFormatter()->formatVar($message);
            if ($this->isHtmlVarDumperUsed()) {
                $messageHtml = $this->getVarDumper()->renderVar($message);
            }
            $isString = false;
        }

        $stackItem = array();
        if ($this->collectFile) {
            $stacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $stackItem = $stacktrace[0];
            foreach ($stacktrace as $trace) {
                if (!isset($trace['file']) || strpos($trace['file'], '/vendor/') !== false) {
                    continue;
                }

                $stackItem = $trace;
                break;
            }
        }

        $this->messages[] = array(
            'message' => $messageText,
            'message_html' => $this->customizeMessageHtml($messageHtml, $message),
            'is_string' => $isString,
            'label' => $label,
            'time' => microtime(true),
            'xdebug_link' => $stackItem ? $this->getXdebugLink($stackItem['file'], isset($stackItem['line'])?$stackItem['line']:null) : null,
        );
    }

    /**
     * Aggregates messages from other collectors
     *
     * @param MessagesAggregateInterface $messages
     */
    public function aggregate(MessagesAggregateInterface $messages)
    {
        if ($this->collectFile && method_exists($messages, 'collectFileTrace')) {
            $messages->collectFileTrace();
        }

        $this->aggregates[] = $messages;
    }

    /**
     * @return array
     */
    public function getMessages()
    {
        $messages = $this->messages;
        foreach ($this->aggregates as $collector) {
            $msgs = array_map(function ($m) use ($collector) {
                $m['collector'] = $collector->getName();
                return $m;
            }, $collector->getMessages());
            $messages = array_merge($messages, $msgs);
        }

        // sort messages by their timestamp
        usort($messages, function ($a, $b) {
            if ($a['time'] === $b['time']) {
                return 0;
            }
            return $a['time'] < $b['time'] ? -1 : 1;
        });

        return $messages;
    }

    /**
     * @param $level
     * @param $message
     * @param array $context
     */
    public function log($level, $message, array $context = array())
    {
        // For string messages, interpolate the context following PSR-3
        if (is_string($message)) {
            $message = $this->interpolate($message, $context);
        }
        $this->addMessage($message, $level);
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    function interpolate($message, array $context = array())
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            $placeholder = '{' . $key . '}';
            if (strpos($message, $placeholder) === false) {
                continue;
            }
            // check that the value can be cast to string
            if (null === $val || is_scalar($val) || (is_object($val) && method_exists($val, "__toString"))) {
                $replace[$placeholder] = $val;
            } elseif ($val instanceof \DateTimeInterface) {
                $replace[$placeholder] = $val->format("Y-m-d\TH:i:s.uP");
            } elseif ($val instanceof \UnitEnum) {
                $replace[$placeholder] = $val instanceof \BackedEnum ? $val->value : $val->name;
            } elseif (is_object($val)) {
                $replace[$placeholder] = '[object ' . $this->getDataFormatter()->formatClassName($val) . ']';
            } elseif (is_array($val)) {
                $json = @json_encode($val);
                $replace[$placeholder] = false === $json ? 'null' : 'array' . $json;
            } else {
                $replace[$placeholder] = '['.gettype($val).']';
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Deletes all messages
     */
    public function clear()
    {
        $this->messages = array();
    }

    /**
     * @return array
     */
    public function collect()
    {
        $messages = $this->getMessages();
        return array(
            'count' => count($messages),
            'messages' => $messages
        );
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return array
     */
    public function getAssets() {
        return $this->isHtmlVarDumperUsed() ? $this->getVarDumper()->getAssets() : array();
    }

    /**
     * @return array
     */
    public function getWidgets()
    {
        $name = $this->getName();
        return array(
            "$name" => array(
                'icon' => 'list-alt',
                "widget" => "PhpDebugBar.Widgets.MessagesWidget",
                "map" => "$name.messages",
                "default" => "[]"
            ),
            "$name:badge" => array(
                "map" => "$name.count",
                "default" => "null"
            )
        );
    }
    
    
    // The HTML var dumper requires debug bar users to support the new inline assets, which not all
    // may support yet - so return false by default for now.
    protected $useHtmlVarDumper = false;
    protected $dataFormater;
    protected $varDumper;

    /**
     * Sets a flag indicating whether the Symfony HtmlDumper will be used to dump variables for
     * rich variable rendering.
     *
     * @param bool $value
     * @return $this
     */
    public function useHtmlVarDumper($value = true)
    {
        $this->useHtmlVarDumper = $value;
        return $this;
    }

    /**
     * Indicates whether the Symfony HtmlDumper will be used to dump variables for rich variable
     * rendering.
     *
     * @return mixed
     */
    public function isHtmlVarDumperUsed()
    {
        return $this->useHtmlVarDumper;
    }

    /**
     * Sets the default data formater instance used by all collectors subclassing this class
     *
     * @param DataFormatterInterface $formater
     */
    public static function setDefaultDataFormatter(DataFormatterInterface $formater)
    {
        DataCollector::$defaultDataFormatter = $formater;
    }

    /**
     * Returns the default data formater
     *
     * @return DataFormatterInterface
     */
    public static function getDefaultDataFormatter()
    {
        if (DataCollector::$defaultDataFormatter === null) {
            DataCollector::$defaultDataFormatter = new DataFormatter();
        }
        return DataCollector::$defaultDataFormatter;
    }

    /**
     * Sets the data formater instance used by this collector
     *
     * @param DataFormatterInterface $formater
     * @return $this
     */
    public function setDataFormatter(DataFormatterInterface $formater)
    {
        $this->dataFormater = $formater;
        return $this;
    }

    /**
     * @return DataFormatterInterface
     */
    public function getDataFormatter()
    {
        if ($this->dataFormater === null) {
            $this->dataFormater = DataCollector::getDefaultDataFormatter();
        }
        return $this->dataFormater;
    }
    /**
     * Sets the default variable dumper used by all collectors subclassing this class
     *
     * @param DebugBarVarDumper $varDumper
     */
    public static function setDefaultVarDumper(DebugBarVarDumper $varDumper)
    {
        DataCollector::$defaultVarDumper = $varDumper;
    }

    /**
     * Returns the default variable dumper
     *
     * @return DebugBarVarDumper
     */
    public static function getDefaultVarDumper()
    {
        if (DataCollector::$defaultVarDumper === null) {
            DataCollector::$defaultVarDumper = new DebugBarVarDumper();
        }
        return DataCollector::$defaultVarDumper;
    }

    /**
     * Sets the variable dumper instance used by this collector
     *
     * @param DebugBarVarDumper $varDumper
     * @return $this
     */
    public function setVarDumper(DebugBarVarDumper $varDumper)
    {
        $this->varDumper = $varDumper;
        return $this;
    }

    /**
     * Gets the variable dumper instance used by this collector; note that collectors using this
     * instance need to be sure to return the static assets provided by the variable dumper.
     *
     * @return DebugBarVarDumper
     */
    public function getVarDumper()
    {
        if ($this->varDumper === null) {
            $this->varDumper = DataCollector::getDefaultVarDumper();
        }
        return $this->varDumper;
    }

    /**
     * @deprecated
     */
    public function formatVar($var)
    {
        return $this->getDataFormatter()->formatVar($var);
    }

    /**
     * @deprecated
     */
    public function formatDuration($seconds)
    {
        return $this->getDataFormatter()->formatDuration($seconds);
    }

    /**
     * @deprecated
     */
    public function formatBytes($size, $precision = 2)
    {
        return $this->getDataFormatter()->formatBytes($size, $precision);
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
