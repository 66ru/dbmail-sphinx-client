<?php

/** @var \Silex\Application $app */

use CSanquer\Silex\PdoServiceProvider\Provider\PDOServiceProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app = new \Silex\Application(array(
    'debug' => $params['debug'],
    'params' => $params,
    'appDir' => __DIR__,
));
$app->register(
    new Silex\Provider\TwigServiceProvider(),
    array(
        'twig.path' => __DIR__ . '/views',
    )
);
$app->register(
    new PdoServiceProvider(),
    array(
        'pdo.db.options' => array(
            'driver' => 'mysql',
            'host' => $params['db']['host'],
            'dbname' => $params['db']['dbname'],
            'user' => $params['db']['user'],
            'password' => $params['db']['password'],
            'options' => array(
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'UTF8'"
            ),
        ),
    )
);
$app['sphinx'] = $app->share(
    function () use ($app, $params) {
        return new \app\components\SphinxManager($app);
    }
);
if (!$app['debug']) {
    $app->error(
        function (\Exception $e, $code) use ($client) {
            $client->captureException($e);
            $status = $code ? $code : 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                $message = $e->getMessage();
            } else {
                $message = 'An error has occurred';
            }
            return new JsonResponse(array(
                    'status' => $status,
                    'message' => $message,
                ),
                $status);
        }
    );
    $app->before(
        function (Request $request) use ($app) {
            if ((string)$app['params']['secretKey'] !== (string)$request->headers->get('secKey')) {
                throw new AccessDeniedHttpException('Forbidden');
            }
        }
    );
}
return $app;
