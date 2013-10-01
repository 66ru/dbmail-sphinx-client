<?php

/** @var \Silex\Application $app */

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

$app->register(
    new Silex\Provider\TwigServiceProvider(),
    array(
        'twig.path' => __DIR__ . '/views',
    )
);
$app['sphinx'] = $app->share(
    function () use ($app, $params) {
        return new \app\components\SphinxManager($app);
    }
);
if (!$app['debug']) {
    $app->error(
        function (\Exception $e, $code) {
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
