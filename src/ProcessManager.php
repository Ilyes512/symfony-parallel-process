<?php
namespace Jack\Symfony;

use SuperClosure\Analyzer\TokenAnalyzer;
use SuperClosure\Serializer;
use Symfony\Component\Process\Process;

/**
 * This ProcessManager is a simple wrapper to enable parallel processing using Symfony Process component.
 */
class ProcessManager
{
    /**
     * This will store all current (possibly running) processes
     *
     * @var array
     */
    protected $currentProcesses = [];

    /**
     * Contains all (optional) extra conditionals before firing a new process.
     */
    protected $extraConditions = [];

    /**
     * May contain a SuperClosure\Serializer instance
     */
    protected $serializer;

    /**
     * @param Process[] $processes
     * @param int       $maxParallel
     * @param int       $poll
     */
    public function runParallel(array $processes, $maxParallel, $poll = 1000)
    {
        $this->validateProcesses($processes);

        // do not modify the object pointers in the argument, copy to local working variable
        $processesQueue = $processes;

        // fix maxParallel to be max the number of processes or positive
        $maxParallel = min(abs($maxParallel), count($processesQueue));

        // get the first stack of processes to start at the same time
        /** @var Process[] $this ->currentProcesses */
        $this->currentProcesses = array_splice($processesQueue, 0, $maxParallel);

        // start the initial stack of processes
        foreach ($this->currentProcesses as $process) {
            $process->start();
        }

        do {
            // wait for the given time
            usleep($poll);

            if (!$this->extraConditional()) {
                $this->stopAllCurrentProcesses();

                break;
            }

            // remove all finished processes from the stack
            foreach ($this->currentProcesses as $index => $process) {
                if (!$process->isRunning()) {
                    unset($this->currentProcesses[$index]);

                    // directly add and start new process after the previous finished
                    if (count($processesQueue) > 0) {
                        $nextProcess = array_shift($processesQueue);
                        $nextProcess->start();
                        $this->currentProcesses[] = $nextProcess;
                    }
                }
            }
            // continue loop while there are processes being executed or waiting for execution
        } while (count($processesQueue) > 0 || count($this->currentProcesses) > 0);
    }

    /**
     * Stop all current processes.
     *
     * @param int $timeout
     */
    public function stopAllCurrentProcesses($timeout = 30)
    {
        foreach ($this->currentProcesses as $process) {
            $process->stop($timeout);
        }

        $this->currentProcesses = [];
    }

    /**
     * Add a new extra condition that will have to return true before executing a new
     * process.
     *
     * @param $condition
     *
     * @return static
     */
    public function addConditional($condition)
    {
        $this->extraConditions[] = $condition;

        return $this;
    }

    /**
     * Validate the processes before we process them.
     *
     * @param Process[] $processes
     */
    protected function validateProcesses(array $processes)
    {
        if (empty($processes)) {
            throw new \InvalidArgumentException('Can not run in parallel 0 commands');
        }

        foreach ($processes as $process) {
            if (!($process instanceof Process)) {
                throw new \InvalidArgumentException('Process in array need to be instance of Symfony Process');
            }
        }
    }

    /**
     * Apply extra conditions before executing a process. Returns true if we can continue.
     *
     * @return bool
     */
    protected function extraConditional()
    {
        if (empty($this->extraConditions)) {
            return true;
        }

        foreach ($this->extraConditions as $condition) {
            $unserialized = $this->getSerializer()->unserialize($condition);

            if (!$unserialized()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the serializer
     *
     * @return void
     */
    protected function getSerializer()
    {
        if (empty($this->serializer)) {
            $this->serializer = app(Serializer::class, [
                'analyzer' => app(TokenAnalyzer::class),
            ]);
        }

        return $this->serializer;
    }
}
