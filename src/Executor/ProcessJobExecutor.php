<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Executor;

use Cron\CronExpression;
use DateTimeImmutable;
use Generator;
use JsonException;
use Orisai\Clock\Adapter\ClockAdapterFactory;
use Orisai\Clock\Clock;
use Orisai\Clock\SystemClock;
use Orisai\Scheduler\Exception\JobProcessFailure;
use Orisai\Scheduler\Exception\RunFailure;
use Orisai\Scheduler\Job\JobSchedule;
use Orisai\Scheduler\Status\JobInfo;
use Orisai\Scheduler\Status\JobResult;
use Orisai\Scheduler\Status\JobResultState;
use Orisai\Scheduler\Status\JobSummary;
use Orisai\Scheduler\Status\RunParameters;
use Orisai\Scheduler\Status\RunSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;
use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * @infection-ignore-all
 */
final class ProcessJobExecutor implements JobExecutor
{

	private Clock $clock;

	private string $script = 'bin/console';

	private string $command = 'scheduler:run-job';

	public function __construct(?ClockInterface $clock = null)
	{
		$this->clock = ClockAdapterFactory::create($clock ?? new SystemClock());
	}

	public function setExecutable(string $script, string $command = 'scheduler:run-job'): void
	{
		$this->script = $script;
		$this->command = $command;
	}

	public function runJobs(array $jobSchedulesBySecond, DateTimeImmutable $runStart): Generator
	{
		$jobExecutions = [];
		$jobSummaries = [];
		$suppressedExceptions = [];

		$lastExecutedSecond = -1;
		while ($jobExecutions !== [] || $jobSchedulesBySecond !== []) {
			// If we have scheduled jobs and are at right second, execute them
			if ($jobSchedulesBySecond !== []) {
				$shouldRunSecond = $this->clock->now()->getTimestamp() - $runStart->getTimestamp();

				while ($lastExecutedSecond < $shouldRunSecond) {
					$currentSecond = $lastExecutedSecond + 1;
					if (isset($jobSchedulesBySecond[$currentSecond])) {
						$jobExecutions = $this->startJobs(
							$jobSchedulesBySecond[$currentSecond],
							$jobExecutions,
							new RunParameters($currentSecond),
						);
						unset($jobSchedulesBySecond[$currentSecond]);
					}

					$lastExecutedSecond = $currentSecond;
				}
			}

			// Check running jobs
			foreach ($jobExecutions as $i => [$execution, $cronExpression]) {
				if (!$execution->isRunning()) {
					unset($jobExecutions[$i]);

					$output = $execution->getOutput() . $execution->getErrorOutput();

					try {
						$decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
						assert(is_array($decoded));

						yield $jobSummaries[] = $this->createSummary($decoded, $cronExpression);
					} catch (JsonException $e) {
						$suppressedExceptions[] = JobProcessFailure::create()
							->withMessage("Job subprocess failed with following output:\n$output");
					}
				}
			}

			// Nothing to do, wait
			$this->clock->sleep(0, 1);
		}

		$summary = new RunSummary($runStart, $this->clock->now(), $jobSummaries);

		if ($suppressedExceptions !== []) {
			throw RunFailure::create($summary, $suppressedExceptions);
		}

		return $summary;
	}

	/**
	 * @param array<int|string, JobSchedule>             $jobSchedules
	 * @param array<int, array{Process, CronExpression}> $jobExecutions
	 * @return array<int, array{Process, CronExpression}>
	 */
	private function startJobs(array $jobSchedules, array $jobExecutions, RunParameters $parameters): array
	{
		foreach ($jobSchedules as $id => $jobSchedule) {
			$execution = new Process([
				PHP_BINARY,
				$this->script,
				$this->command,
				$id,
				'--json',
				'--parameters',
				json_encode($parameters->toArray(), JSON_THROW_ON_ERROR),
			]);
			$execution->start();

			$jobExecutions[] = [$execution, $jobSchedule->getExpression()];
		}

		return $jobExecutions;
	}

	/**
	 * @param array<mixed> $raw
	 */
	private function createSummary(array $raw, CronExpression $cronExpression): JobSummary
	{
		return new JobSummary(
			new JobInfo(
				$raw['info']['id'],
				$raw['info']['name'],
				$raw['info']['expression'],
				$raw['info']['repeatAfterSeconds'],
				$raw['info']['runSecond'],
				DateTimeImmutable::createFromFormat('U.u', $raw['info']['start']),
			),
			new JobResult(
				$cronExpression,
				DateTimeImmutable::createFromFormat('U.u', $raw['result']['end']),
				JobResultState::from($raw['result']['state']),
			),
		);
	}

}
