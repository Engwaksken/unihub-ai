<?php

namespace App\Services;

use App\Models\Job;

/**
 * Waits for the inference worker to finish a job, for synchronous APIs
 * (OpenAI-compatible chat). Polls the database; the worker runs separately.
 * Tests bind a version that runs the worker in-process instead.
 */
class JobWaiter
{
    public function await(Job $job, int $seconds): Job
    {
        $deadline = microtime(true) + $seconds;
        while (! in_array($job->state, Job::TERMINAL, true) && microtime(true) < $deadline) {
            usleep(250_000);
            $job->refresh();
        }

        return $job;
    }
}
