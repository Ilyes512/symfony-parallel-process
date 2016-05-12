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
     * Contains all (optional) extra conditionals before firing a new process.
     */
    protected $extraConditions = [];

    /**
     * May contain a SuperClosure\Serializer instance
     */
    protected $serializer;

    /**
     * @param Process[] $processes
     * @param int $maxParallel
     * @param int $poll
     */
    public function runParallel(array $processes, $maxParallel, $poll = 1000)
    {
        $this->validateProcesses($processes);

        // do not modify the object pointers in the argument, copy to local working variable
        $processesQueue = $processes;

        // fix maxParallel to be max the number of processes or positive
        $maxParallel = min(abs($maxParallel), count($processesQueue));

        // get the first stack of processes to start at the same time
        /** @var Process[] $currentProcesses */
        $currentProcesses = array_splice($processesQueue, 0, $maxParallel);

        // start the initial stack of processes
        foreach ($currentProcesses as $process) {
            $process->start();
        }

        do {
            // wait for the given time
            usleep($poll);

            // remove all finished processes from the stack
            foreach ($currentProcesses as $index => $process) {
                if (!$process->isRunning()) {
                    unset($currentProcesses[$index]);

                    // directly add and start new process after the previous finished
                    if (count($processesQueue) > 0) {
                        $nextProcess = array_shift($processesQueue);
                        $nextProcess->start();
                        $currentProcesses[] = $nextProcess;
                    }
                }
            }
            // continue loop while there are processes being executed or waiting for execution
        } while ((count($processesQueue) > 0 || count($currentProcesses) > 0) && $this->extraConditional());
    }

    /**
     * Add a new extra condition that will have to return true before executing a new
     * process.
     *
     * @return self
     */
    public function addConditional($condition)
    {
        $this->extraConditions[] = $condition;

        return $this;
    }

    /**
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
            if (!$this->getSerializer()->unserialize($condition)()) {
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
