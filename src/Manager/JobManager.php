<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Manager;

use Cron\CronExpression;
use Orisai\Scheduler\Job\Job;

interface JobManager
{

	/**
	 * @param int|string $id
	 * @return array{Job, CronExpression}|null
	 */
	public function getScheduledJob($id): ?array;

	/**
	 * @return array<int|string, array{Job, CronExpression}>
	 */
	public function getScheduledJobs(): array;

	/**
	 * @return array<int|string, CronExpression>
	 */
	public function getExpressions(): array;

}
