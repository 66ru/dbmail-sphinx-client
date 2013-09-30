<?php namespace app\components;

use Silex\Application;

class SphinxManager
{
    /** @var \Silex\Application */
    protected $app;

    function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param int $userId
     * @return string
     */
    public function getSphinxUri($userId)
    {
        /** @var \Twig_Environment $twig */
        $twig = $this->app['twig'];
        $twig->render('sphinx.conf.twig', array(
                'userId' => $userId,
                'db' => $this->app['params']['db'],
                'indexDirectory' => $this->app['appDir'] . '/indexes',
                'searchdPort' => 3,
            ));

        return 'bulk!'.$userId;
    }

    /**
     * @param int $userId
     */
    public function startSearchDaemon($userId)
    {

    }
}