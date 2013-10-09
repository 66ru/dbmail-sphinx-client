<?php namespace app\components;

use app\helpers\CommandLineHelper;
use Silex\Application;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SphinxManager
{
    /** @var \Silex\Application */
    public $app;

    /** @var  string */
    protected $indexDirectory;
    /** @var  \Memcached */
    protected $memcache;

    function __construct(Application $app)
    {
        $this->app = $app;
        $this->indexDirectory = $app['appDir'] . '/indexes';
        $this->memcache = new \Memcached();
        $this->memcache->addServer($app['params']['memcache']['host'], $app['params']['memcache']['port']);
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
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     * @return string
     */
    public function getSphinxUri($userId)
    {
        $pidExists = file_exists($this->indexDirectory . "/user$userId.pid");
        $confExists = file_exists($this->indexDirectory . "/user$userId.conf");
        if ($pidExists && $confExists) {
            $port = $this->getPortFromConfig($userId);
        } elseif ($pidExists && !$confExists) {
            throw new \ErrorException("Pid for $userId exist, but not config file");
        } else {
            $lockKey = 'sphinxIndexerRun' . $userId;
            if ($this->memcache->add($lockKey, 1, 3600)) {
                $port = $this->getAvailablePort();
                $this->writeConfig($userId, $port);
                $configFilePath = $this->getConfigFilePath($userId);
                $this->reindex($configFilePath);
                $this->serve($configFilePath);
                $this->memcache->delete($lockKey);
            } else {
                if ($this->waitForKey($lockKey)) {
                    $port = $this->getPortFromConfig($userId);
                } else {
                    throw new HttpException(500, "Can't get sphinxUri. Try again later.");
                }
            }
        }
        return $_SERVER['SERVER_ADDR'] . ':' . $port;
    }

    /**
     * @param $key string
     * @param $timeoutSeconds int
     * @return bool true if unlocked
     */
    public function waitForKey($key, $timeoutSeconds = 5)
    {
        $i = 0;
        while ($this->memcache->get($key) && $i < $timeoutSeconds * 1000000) {
            usleep(100000); // 100ms
            $i += 100000;
        }

        return $this->memcache->get($key) === false;
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

    /**
     * @param string $configFilePath
     */
    public function reindex($configFilePath)
    {
        $configFilePath = escapeshellarg($configFilePath);
        CommandLineHelper::exec($this->app['params']['sphinx']['indexer'] . " --config $configFilePath --all --quiet");
    }

    /**
     * @param string $configFilePath
     */
    public function terminateDaemon($configFilePath)
    {
        $configFilePath = escapeshellarg($configFilePath);
        CommandLineHelper::exec($this->app['params']['sphinx']['searchd'] . " --config $configFilePath --stop");
    }

    /**
     * @param string $configFilePath
     */
    public function serve($configFilePath)
    {
        $configFilePath = escapeshellarg($configFilePath);
        CommandLineHelper::exec($this->app['params']['sphinx']['searchd'] . " --config $configFilePath");
    }

    /**
     * @return string
     */
    public function getIndexDirectory()
    {
        return $this->indexDirectory;
    }

    /**
     * @param $userId
     * @return string
     */
    protected function getPortFromConfig($userId)
    {
        $config = file_get_contents($this->indexDirectory . "/user$userId.conf");
        $portOffset = strrpos($config, 'listen = ') + 9;
        $port = substr($config, $portOffset, 5);
        return $port;
    }
}