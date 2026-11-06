<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Upgrades;

use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\RepeatableInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Scheduler\Execution;

/**
 * Legt den Hydrate-Lauf alle vier Stunden an, ohne --force.
 *
 * Der System-Cron ruft scheduler:run bereits jede Minute auf. Dieser Task
 * wird nur zur Minute 30 jeder vierten Stunde fällig. Leere oder unveränderte
 * Felder verbrauchen dabei keine Übersetzungszeichen.
 *
 * parameters und execution_details sind im TCA json. Connection::insert
 * kodiert Arrays genau einmal. Fertige JSON-Strings würden ein zweites Mal
 * kodiert und der Planer könnte den Task nicht mehr laden.
 */
#[UpgradeWizard('maiTranslateEnsureHydrateOverlaysTask')]
final readonly class EnsureHydrateOverlaysTaskUpgrade implements UpgradeWizardInterface, RepeatableInterface
{
    private const string COMMAND = 'mai_translate:hydrate-overlays';
    private const string CRON = '30 */4 * * *';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Ensure overlay hydration runs every four hours';
    }

    public function getDescription(): string
    {
        return 'Creates a scheduler task for mai_translate:hydrate-overlays at 30 */4 * * * without --force.';
    }

    public function updateNecessary(): bool
    {
        return !$this->taskExists();
    }

    /**
     * @return list<class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function executeUpdate(): bool
    {
        if ($this->taskExists()) {
            return true;
        }

        $execution = Execution::createRecurringExecution(
            start: time(),
            interval: 0,
            end: 0,
            multiple: false,
            cronCmd: self::CRON,
        );

        $this->connectionPool->getConnectionForTable('tx_scheduler_task')->insert(
            'tx_scheduler_task',
            [
                'pid' => 0,
                'crdate' => time(),
                'deleted' => 0,
                'disable' => 0,
                'tasktype' => self::COMMAND,
                'task_group' => 0,
                'priority' => 100,
                'description' => 'Übersetzungen und fehlende Bildreferenzen nachziehen',
                'parameters' => ['commandIdentifier' => self::COMMAND],
                'execution_details' => $execution->toArray(),
                'nextexecution' => $execution->getNextExecution(),
                'lastexecution_time' => 0,
                'lastexecution_context' => '',
            ],
        );

        return $this->taskExists();
    }

    private function taskExists(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('*')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->eq(
                    'tasktype',
                    $queryBuilder->createNamedParameter(self::COMMAND),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }
}
