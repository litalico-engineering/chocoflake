<?php

use Adachi\Choco\Domain\IdValue\Element\RegionId;
use Adachi\Choco\Domain\IdValue\Element\ServerId;
use Adachi\Choco\Domain\IdValue\Element\Timestamp;
use Adachi\Choco\Domain\IdConfig\IdConfig;
use Adachi\Choco\Domain\IdWorker\RandomSequence\IdWorkerOnRandomSequence;

/**
 * Class IdWorkerOnRandomSequenceTest
 */
class IdWorkerOnRandomSequenceTest extends PHPUnit_Framework_TestCase
{

    /**
     * @var \Adachi\Choco\Domain\IdWorker\SharedMemory\IdWorkerOnRandomSequence|Mockery\Mock
     */
    private $idWorker;

    protected function setUp()
    {
        $config = new IdConfig(41, 5, 5, 12, 1414334507356);
        $this->idWorker = Mockery::mock('\Adachi\Choco\Domain\IdWorker\RandomSequence\IdWorkerOnRandomSequence[generateTimestamp]', [$config, new RegionId(1), new ServerId(1)]);
        $this->idWorker->shouldReceive('generateTimestamp')
            ->andReturn(new Timestamp(1000));
    }

    /**
     * @test
     */
    public function createIdValue()
    {
        $id = $this->idWorker->generate();
        // Timestamp(1000) | RegionId(1) | ServerId(1) | Sequence(0)
        // 1111101000 00001 00001 000000000000
        $this->assertSame(sprintf('%b', $this->idWorker->write($id)), '11111010000000100001000000000000');
    }

    /**
     * @test
     */
    public function convertIdValueToIntValue()
    {
        $id = $this->idWorker->generate();
        $intValue = $this->idWorker->write($id);
        $this->assertEquals($id, $this->idWorker->read($intValue));
    }

    /**
     * @test
     */
    public function createIdValueWithoutDuplication()
    {
        $config = new IdConfig(41, 5, 5, 4, 1414334507356);
        $this->idWorker = new IdWorkerOnRandomSequence($config, new RegionId(1), new ServerId(1));
        /** @var int $ids */
        $ids = array();
        $expectedCount = 1000;

        for ($i = 0; $i < $expectedCount; $i++) {
            $ids[] = $this->idWorker->generate()->toInt();
        }

        // unique ids
        $actual = array_values(array_unique($ids));

        $message = "";
        foreach (array_filter(array_count_values($ids), function ($v) { return --$v; }) as $id => $count) {
            $idValue = $this->idWorker->read($id);
            $message .= "value:{$idValue->asString()}, timestamp: {$idValue->timestamp}, sequence: {$idValue->sequence}, times: {$count}\n";
        }

        $this->assertCount($expectedCount, $actual, "The duplicate ID is as follows:\n{$message}");
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function createIdValueWithoutDuplicationUnderProcessForks()
    {
        $config = new IdConfig(41, 5, 5, 4, 1414334507356);
        $this->idWorker = new IdWorkerOnRandomSequence($config, new RegionId(1), new ServerId(1));

        $pids = array();
        $loopCount = 100;
        $forkCount = 10;

        // Prefix the temporary file for the result output
        $sharedFilePrefix = tempnam('/var/tmp', get_class($this));

        for ($i = 0; $i < $forkCount; $i++) {
            // process fork
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->fail('Failed to fork the process.');
                break;
            } else {
                if ($pid) {
                    // For the parent process
                    $pids[] = $pid;
                } else {
                    // For child processes
                    $sharedFile = $sharedFilePrefix . getmypid();
                    $ids = array();
                    for ($j = 0; $j < $loopCount; $j++) {
                        $ids[] = $this->idWorker->generate()->toInt();
                    }
                    // Output the result to a temporary file
                    file_put_contents($sharedFile, serialize($ids));

                    // Terminate child process
                    exit;
                }
            }
        }
        // Waiting for the process to exit
        $idsArray = array();
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            // Get the result output of each process.
            $sharedFile = $sharedFilePrefix . $pid;
            $idsArray[] = unserialize(file_get_contents($sharedFile));

            // Delete temporary files
            unlink($sharedFile);
        }

        // flatten ids
        $ids = array();
        array_walk_recursive($idsArray, function($e) use (&$ids) { $ids[] = $e; });

        // unique ids
        $actual = array_values(array_unique($ids));

        $message = "";
        foreach (array_filter(array_count_values($ids), function ($v) { return --$v; }) as $id => $count) {
            $idValue = $this->idWorker->read($id);
            $message .= "value:{$idValue->asString()}, timestamp: {$idValue->timestamp}, sequence: {$idValue->sequence}, times: {$count}\n";
        }

        $this->assertCount($loopCount * $forkCount,
            $actual,
            "The duplicate ID is as follows:\n{$message}");
    }
}