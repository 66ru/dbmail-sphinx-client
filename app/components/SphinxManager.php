<?php namespace app\components;

use app\helpers\CommandLineHelper;
use Silex\Application;

class SphinxManager
{
    /** @var \Silex\Application */
    public $app;

    /** @var  string */
    protected $indexDirectory;

    function __construct(Application $app)
    {
        $this->app = $app;
        $this->indexDirectory = $app['appDir'] . '/indexes';
    }

    /**
     * @param int $userId
     * @return string
     */
    public function getPidFilePath($userId)
    {
        return $this->indexDirectory . "/user$userId.pid";
    }

    /**
     * @param int $userId
     * @return string
     */
    public function getConfigFilePath($userId)
    {
        return $this->indexDirectory . "/user$userId.conf";
    }

    /**
     * @param int $userId
     * @throws \ErrorException
     * @return string
     */
    public function getSphinxUri($userId)
    {
        $pidExists = file_exists($this->indexDirectory . "/user$userId.pid");
        $confExists = file_exists($this->indexDirectory . "/user$userId.conf");
        if ($pidExists && $confExists) {
            $config = file_get_contents($this->indexDirectory . "/user$userId.conf");
            $portOffset = strrpos($config, 'listen = ') + 9;
            $port = substr($config, $portOffset, 5);
        } elseif ($pidExists && !$confExists) {
            throw new \ErrorException("Pid for $userId exist, but not config file");
        } else {
            $port = $this->getAvailablePort();
            $this->writeConfig($userId, $port);
            $configFilePath = $this->getConfigFilePath($userId);
            $this->reindex($configFilePath);
            $this->serve($configFilePath);
        }
        return $_SERVER['SERVER_ADDR'] . ':' . $port;
    }

    /**
     * @param string $ip
     * @param int $port
     * @return bool
     */
    protected function checkPortOpen($ip, $port)
    {
        $connection = @fsockopen($ip, $port, $null, $null, 1);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return int port
     * @throws \ErrorException
     */
    protected function getAvailablePort()
    {
        $i = 0;
        do {
            $port = rand(10000, 65535);
        } while ($this->checkPortOpen($_SERVER['SERVER_ADDR'], $port) && ++$i < 500);
        if ($i == 500) {
            throw new \ErrorException('Failed to find available port for searchd');
        }
        return $port;
    }

    /**
     * @param int $userId
     * @param int $port
     * @throws \ErrorException
     */
    public function writeConfig($userId, $port)
    {
        /** @var \Twig_Environment $twig */
        $twig = $this->app['twig'];
        $config = $twig->render(
            'sphinx.conf.twig',
            array(
                'userId' => $userId,
                'db' => $this->app['params']['db'],
                'pidFile' => $this->getPidFilePath($userId),
                'indexDirectory' => $this->indexDirectory,
                'searchdPort' => $port,
            )
        );
        $written = file_put_contents($this->getConfigFilePath($userId), $config);
        if (!$written) {
            throw new \ErrorException("Unable to write config for $userId");
        }
    }

    public function reindex($configFilePath)
    {
        $configFilePath = escapeshellarg($configFilePath);
        CommandLineHelper::exec($this->app['params']['sphinx']['indexer'] . " --config $configFilePath --all --quiet");
    }

    public function serve($configFilePath)
    {
        $configFilePath = escapeshellarg($configFilePath);
        CommandLineHelper::exec($this->app['params']['sphinx']['searchd'] . " --config $configFilePath");
    }
}