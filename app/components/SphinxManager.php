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
        $configFilePath = $this->getConfigFilePath($userId);
        $pidFilePath = $this->getPidFilePath($userId);
        $pidExists = file_exists($pidFilePath);
        $confExists = file_exists($configFilePath);

        if ($pidExists) {
            $pid = trim(file_get_contents($pidFilePath));
            $processStartedTime = @filectime('/proc/' . $pid);
            if (!$processStartedTime) {
                unlink($pidFilePath);
                $pidExists = false;
            }
        }

        if ($pidExists && $confExists) {
            $port = $this->getPortFromConfig($userId);
            $this->reindexInBackground($configFilePath, "user{$userId}_delta");
        } elseif ($pidExists && !$confExists) {
            throw new \ErrorException("Pid for $userId exist, but not config file");
        } else {
            $lockKey = 'sphinxIndexerRun' . $userId;
            if ($this->memcache->add($lockKey, 1, 300)) {
                $port = $this->getAvailablePort();
                $this->writeConfig($userId, $port);

                if ($this->insertEmptyCounter($userId)) {
                    $this->reindex($configFilePath);
                    $this->serve($configFilePath);
                } else {
                    $this->rotate($configFilePath, "user$userId", "user{$userId}_delta");
                    $this->serve($configFilePath);
                    $this->rotateMaxIds($userId);
                    $this->reindexInBackground($configFilePath, "user{$userId}_delta");
                }
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
     * @param string $indexName
     */
    public function reindex($configFilePath, $indexName = '--all')
    {
        $configFilePath = escapeshellarg($configFilePath);
        $indexName = escapeshellarg($indexName);
        CommandLineHelper::exec($this->app['params']['sphinx']['indexer'] . " --config $configFilePath $indexName --quiet");
    }

    /**
     * @param string $configFilePath
     * @param string $indexName
     */
    public function reindexInBackground($configFilePath, $indexName = '--all')
    {
        $configFilePath = escapeshellarg($configFilePath);
        $indexName = escapeshellarg($indexName);
        CommandLineHelper::exec($this->app['params']['sphinx']['indexer'] . " --config $configFilePath $indexName --quiet --rotate &");
    }

    /**
     * @param string $configFilePath
     * @param string $mainIndexName
     * @param string $deltaIndexName
     */
    public function rotate($configFilePath, $mainIndexName, $deltaIndexName)
    {
        $configFilePath = escapeshellarg($configFilePath);
        $mainIndexName = escapeshellarg($mainIndexName);
        $deltaIndexName = escapeshellarg($deltaIndexName);
        CommandLineHelper::exec($this->app['params']['sphinx']['indexer'] . " --config $configFilePath --merge $mainIndexName $deltaIndexName --quiet");
    }

    /**
     * @param int $userId
     * @return bool success or not
     */
    protected function rotateMaxIds($userId)
    {
        /** @var \PDO $pdo */
        $pdo = $this->app['pdo'];
        $PDOStatement = $pdo->prepare("UPDATE sphinx_counter SET mainTopId = deltaTopId WHERE userId = :userId");
        $PDOStatement->execute(array(':userId' => $userId));
        return (bool)$PDOStatement->rowCount();
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

    /**
     * @param int $userId
     * @return bool whether empty counter line was inserted successfully
     */
    protected function insertEmptyCounter($userId)
    {
        /** @var \PDO $pdo */
        $pdo = $this->app['pdo'];
        $PDOStatement = $pdo->prepare("INSERT IGNORE INTO sphinx_counter VALUES (:userId, 0, 0)");
        $PDOStatement->execute(array(':userId' => $userId));
        return (bool)$PDOStatement->rowCount();
    }
}