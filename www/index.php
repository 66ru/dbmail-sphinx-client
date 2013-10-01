<?php

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require_once(__DIR__ . '/../vendor/autoload.php');
$loader->add('app', realpath(__DIR__ . '/../'));

$params = require(__DIR__ . '/../app/config/params.php');

if (extension_loaded('xhprof')) {
    if ($_SERVER['REQUEST_URI'] != '/' &&
        file_exists(__DIR__ . '/../vendor/facebook/xhprof/xhprof_html' . $_SERVER['REQUEST_URI'])
    ) {
        readfile(__DIR__ . '/../vendor/facebook/xhprof/xhprof_html' . $_SERVER['REQUEST_URI']);
        exit;
    }
    if (!empty($_GET['run']) && !empty($_GET['source']) || !empty($_GET['xhprof'])) {
        require __DIR__ . '/../vendor/facebook/xhprof/xhprof_html/index.php';
        exit;
    }
    xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);

    register_shutdown_function(
        function () {
            $profiler_namespace = 'dbmail-sphinx-client'; // namespace for your application
            $xhprof_data = xhprof_disable();
            $xhprof_runs = new XHProfRuns_Default();
            $xhprof_runs->save_run($xhprof_data, $profiler_namespace);
        }
    );
}

if (!empty($params['sentryDSN'])) {
    Raven_Autoloader::register();
    $client = new Raven_Client($params['sentryDSN']);
    $error_handler = new Raven_ErrorHandler($client);
    $error_handler->registerExceptionHandler();
    $error_handler->registerErrorHandler();
    $error_handler->registerShutdownFunction();
}

$app = new \Silex\Application(array(
    'debug' => $params['debug'],
    'params' => $params,
    'appDir' => realpath(__DIR__ . '/../app'),
));
require(__DIR__ . '/../app/bootstrap.php');
require(__DIR__ . '/../app/app.php');
$app->run();
