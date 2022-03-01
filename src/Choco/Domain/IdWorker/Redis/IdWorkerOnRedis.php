<?php

namespace Adachi\Choco\Domain\IdWorker\Redis;

use Adachi\Choco\Domain\IdConfig\IdConfig;
use Adachi\Choco\Domain\IdValue\Element\RegionId;
use Adachi\Choco\Domain\IdValue\Element\ServerId;
use Adachi\Choco\Domain\IdValue\IdValue;
use Adachi\Choco\Domain\IdWorker\AbstractIdWorker;
use Adachi\Choco\Domain\IdWorker\IdWorkerInterface;
use Predis\Client;

/**
 * Class IdWorkerOnRedis
 *
 * @package Adachi\Choco\Domain\IdWorker\Redis
 */
class IdWorkerOnRedis extends AbstractIdWorker implements IdWorkerInterface
{

    /**
     * @var array
     */
    private $credential;

    /**
     * @var Client
     */
    private $redis;

    const REDIS_SEQUENCE_PREFIX_KEY = 'chocolate_counter_';

    /**
     * @param IdConfig $config
     * @param RegionId $regionId
     * @param ServerId $serverId
     * @param array $credential
     */
    public function __construct(IdConfig $config,
        RegionId $regionId,
        ServerId $serverId,
        $credential = [
            'scheme'   => 'tcp',
            'host'     => '127.0.0.1',
            'port'     => 6379
        ]
    ) {
        $this->config = $config;
        $this->regionId = $regionId;
        $this->serverId = $serverId;
        $this->credential = $credential;

        $this->redis = new Client($this->credential);
    }

    /**
     * @return IdValue
     */
    public function generate()
    {
        $script = <<<LUA
if redis.call('exists', KEYS[1]) == 1 then
    return redis.call('incr', KEYS[1])
else
    redis.call('set', KEYS[1], 0, 'PX', 1000)
    return 0
end
LUA;

        $timestamp = $this->generateTimestamp();
        $sequence = $this->redis->eval($script, 1, self::REDIS_SEQUENCE_PREFIX_KEY . $timestamp);

        if ($sequence !== ($sequence & $this->config->getSequenceMask())) {
            // Sequence overflowed, rerun
            return $this->generate();
        }

        return new IdValue($timestamp, $this->regionId, $this->serverId, $sequence, $this->calculate($timestamp, $this->regionId, $this->serverId, $sequence));
    }
}