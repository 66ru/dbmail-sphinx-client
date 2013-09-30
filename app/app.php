<?php

use app\components\SphinxManager;
use Symfony\Component\HttpFoundation\JsonResponse;

/** @var \Silex\Application $app */
/** @var \Silex\ControllerCollection $api */
$api = $app['controllers_factory'];

$api->get(
    '/sphinxUri/{userId}',
    function (Silex\Application $app, $userId) {
        /** @var SphinxManager $sphinxManager */
        $sphinxManager = $app['sphinx'];
        return new JsonResponse(array(
            'status' => 200,
            'sphinxUri' => $sphinxManager->getSphinxUri($userId),
        ));
    }
)->assert('userId', '\d+');

$app->mount('/v1', $api);
