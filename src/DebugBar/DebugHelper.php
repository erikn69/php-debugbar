<?php
/*
 * This file is part of the DebugBar package.
 *
 * (c) 2013 Maxime Bouroumeau-Fuseau
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DebugBar;

use DebugBar\DebugBar;
use DebugBar\DataCollector\ObjectCountCollector;
use DebugBar\DataCollector\QueryCollector;
use DebugBar\OpenHandler;
use DebugBar\Storage\TempFileStorage;

class DebugHelper
{
    protected static $debugbar = null;
    protected static $startTime = null;
    protected static $stacked = false;

    public static function loadClass(){}
    public static function initialize($profilerPath, $openHandler, $baseUrl = null, $noRender = false) {
        if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
        $memoryStart = memory_get_usage(false);
        $timeStart = microtime(true);
        $debugbar = new StandardDebugBar();
        $requestTime = isset(self::$startTime) ? self::$startTime : $debugbar['time']->getRequestStartTime();
        $debugbar['time']->showMemoryUsage(true);
        $debugbar['time']->addMeasure('Booting', $requestTime, microtime(true), array('memoryUsage' => $memoryStart));

        $debugbar->setStorage(new TempFileStorage($profilerPath));
        $debugbar->addCollector(new QueryCollector(/*$debugbar['time']*/));
        $debugbar->addCollector(new ObjectCountCollector());

        $debugbar['memory']->setPrecision(1);
        $debugbar['memory']->resetMemoryBaseline(true);
        $debugbar['queries']->setDurationBackground(true);

        $debugbarRenderer = $debugbar->getJavascriptRenderer($baseUrl)
            ->setAjaxHandlerAutoShow(true)
            ->setBaseUrl(is_null($baseUrl) ? '../src/DebugBar/Resources' : $baseUrl)
            ->setAjaxHandlerEnableTab(true)
            ->setDeferDatasets(true)
            ->setHideEmptyTabs(true)
            ->setEnableJqueryNoConflict(true)
            ->setTheme(isset($_GET['theme']) ? $_GET['theme'] : 'auto')
            ->setOpenHandlerUrl($openHandler);

        set_exception_handler(function ($exception) {
            DebugHelper::addException($exception);
        });

        if ($noRender) {
            if (self::isAjax())
                register_shutdown_function(function () {
                    if(!DebugHelper::stacked())
                        DebugHelper::sendDataInHeaders(true);
                });
        } else {
            //if (! self::isAjax()) {
                ob_start();
            //}

            register_shutdown_function(function () use ($debugbarRenderer){
                if (DebugHelper::isAjax() || !DebugHelper::hasDebugBar()){
                    if(!DebugHelper::stacked())
                        DebugHelper::sendDataInHeaders(true);
                    ob_end_flush();
                }else {
                    $content = ob_get_contents();
                    ob_end_clean(); // Clean buffer

                    if (DebugHelper::stacked()) {
                        echo $content;
                        return;
                    }

                    // Try to put the js/css directly before the </head>
                    $pos = stripos($content, '</head>');
                    if (false !== $pos) {
                        $head = $debugbarRenderer->renderHead();
                        $content = substr($content, 0, $pos) . $head . substr($content, $pos);
                    }

                    // Try to put the widget at the end, directly before the </body>
                    $pos = strripos($content, '</body>');
                    if (false !== $pos) {
                        $widget = $debugbarRenderer->render();
                        $content = substr($content, 0, $pos) . $widget . substr($content, $pos);
                    }

                    echo $content; // show the content
                }
            });
        }

        $debugbar['time']->addMeasure('Debugbar Load', $timeStart, microtime(true), array('memoryUsage' => memory_get_usage(false)));
        self::setDebugBar($debugbar);
        self::enableFileTraces(true);
        self::setPathReplacements(' ');
        $debugbar['exceptions']->collectWarnings();
        return $debugbarRenderer;
    }

    public static function setDebugBar($debugbarInstance) {
        self::$debugbar = $debugbarInstance;
    }

    public static function hasDebugBar() {
        return !empty(self::$debugbar);
    }

    public static function stacked() {
        return self::$stacked;
    }

    public static function setEditor($editor, $localPath = null) {
        if (! isset(self::$debugbar) || !$editor) return;
        if (! is_null($localPath))
            $replacements = array_fill_keys(
                array(realpath(__DIR__.'/../../../../').DIRECTORY_SEPARATOR),
                rtrim($localPath, "/\\").DIRECTORY_SEPARATOR
            );

        foreach (self::$debugbar->getCollectors() as $collector) {
            if (method_exists($collector, 'setEditorLinkTemplate'))
                $collector->setEditorLinkTemplate($editor);
            if (! is_null($localPath) && method_exists($collector, 'addXdebugReplacements'))
                $collector->addXdebugReplacements($replacements);
        }
    }

    public static function setPathReplacements($local = null, $remotePaths = array()) {
        if (! isset(self::$debugbar)) return;
        $path = realpath(__DIR__.'/../../../../').DIRECTORY_SEPARATOR;
        $localPath = !is_null($local) ? rtrim($local, "/\\").DIRECTORY_SEPARATOR : $path;
        $remotePaths = count($remotePaths) ? array_filter($remotePaths) : array($path);
        $replacements = array_fill_keys($remotePaths, $localPath);

        foreach (self::$debugbar->getCollectors() as $collector)
            if (method_exists($collector, 'addXdebugReplacements'))
                $collector->addXdebugReplacements($replacements);
    }

    public static function enableFileTraces($enable = true) {
        if (! isset(self::$debugbar)) return;
        if (isset(self::$debugbar['queries']))
            self::$debugbar['queries']->setFindSource($enable);
        if (isset(self::$debugbar['messages']))
            self::$debugbar['messages']->collectFileTrace($enable);
    }

    public static function enableGlobalTimeline($enable = true) {
        if (! isset(self::$debugbar) || ! isset(self::$debugbar['time'])) return;
        if (isset(self::$debugbar['queries']))
            self::$debugbar['queries']->setTimeline(!$enable ? null : self::$debugbar['time']);
    }

    public static function addQueryComment($comment, $other = array()) {
        if (! isset(self::$debugbar)) return;
        if (isset(self::$debugbar['queries']))
            self::$debugbar['queries']->addComment(comment, $other);
    }

    public static function getJavascriptRenderer() {
        if (! isset(self::$debugbar)) return;
        return self::$debugbar->getJavascriptRenderer();
    }

    public static function renderHead() {
        if (! isset(self::$debugbar)) return;
        return self::$debugbar->getJavascriptRenderer()->renderHead();
    }

    public static function render() {
        if (! isset(self::$debugbar)) return;
        return self::$debugbar->getJavascriptRenderer()->render();
    }

    public static function aggregateMessagesCollector(MessagesAggregateInterface $messages) {
        if (! isset(self::$debugbar)) return;
        if (isset(self::$debugbar['messages']))
            self::$debugbar['messages']->aggregate($messages);
    }

    public static function log() {
        $args = func_get_args();
        if (! isset(self::$debugbar)) return;
        if (! isset(self::$debugbar['messages'])) return;
        foreach ($args as $message) {
            self::$debugbar['messages']->log('info', $message);
        }
    }

    public static function openHandler($profilerPath){
        $debugbar = new DebugBar();
        $debugbar->setStorage(new TempFileStorage($profilerPath));
        $openHandler = new OpenHandler($debugbar);
        $openHandler->handle();
    }

    public static function isAjax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    public static function __callStatic($name, $arguments) {
        if (! isset(self::$debugbar)){
            if ($name == 'measure' && isset($arguments[1]) && is_callable($arguments[1])) {
                $arguments[1]();
            }

            return;
        }

        if (method_exists(__CLASS__, $name)) {
            return call_user_func_array(array(__CLASS__, $name), $arguments);
        }

        if (in_array($name, array('addMessage', 'aggregate')) && isset(self::$debugbar['messages'])) {
            return call_user_func_array(array(self::$debugbar['messages'], $name), $arguments);
        }

        if (in_array($name, array('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug')) && isset(self::$debugbar['messages'])) {
            return call_user_func_array(array(self::$debugbar['messages'], $name), $arguments);
        }

        if (in_array($name, array('startQueryMeasure', 'addQuery', 'addTransactionEvent', 'addComment')) && isset(self::$debugbar['queries'])) {
            return call_user_func_array(array(self::$debugbar['queries'], $name), $arguments);
        }

        if (in_array($name, array('addMeasure', 'measure', 'startMeasure', 'stopMeasure', 'hasStartedMeasure')) && isset(self::$debugbar['time'])) {
            return call_user_func_array(array(self::$debugbar['time'], $name), $arguments);
        }

        if (in_array($name, array('countClass')) && isset(self::$debugbar['counter'])) {
            return call_user_func_array(array(self::$debugbar['counter'], $name), $arguments);
        }

        if (in_array($name, array('addException', 'addThrowable')) && isset(self::$debugbar['exceptions'])) {
            return call_user_func_array(array(self::$debugbar['exceptions'], $name), $arguments);
        }

        if (method_exists(self::$debugbar, $name)) {
            if ($name === 'stackData') {
                self::$stacked = true;
            }

            return call_user_func_array(array(self::$debugbar, $name), $arguments);
        }

        return;
    }
}

// Uso seguro sin riesgo de error:
//DebugHelper::addMessage("Mensaje de prueba");
