<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\SaaSCommon\Cron;

use Magento\DataExporter\Lock\FeedLockManager;
use Magento\DataExporter\Model\Batch\BatchGeneratorInterface;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Indexer\Model\ProcessManagerFactory;
use Magento\SaaSCommon\Model\Exception\UnableSendData;
use Magento\DataExporter\Model\FeedPool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\ModuleList;
use Magento\SaaSCommon\Model\FeedRegistry;
use Magento\SaaSCommon\Model\Http\Command\SubmitFeed as HttpCommandSubmitFeed;
use Magento\SaaSCommon\Model\ResyncManagerPool;
use Magento\ServicesConnector\Exception\PrivateKeySignException;
use Magento\ServicesConnector\Model\Environment;
use Magento\SaaSCommon\Model\Logging\SaaSExportLoggerInterface as LoggerInterface;
use Magento\DataExporter\Model\FeedInterface;

/**
 * Class to execute submitting data feed
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SubmitFeed implements SubmitFeedInterface
{
    public const ENVIRONMENT_CONFIG_PATH = 'magento_saas/environment';

    /**
     * @var HttpCommandSubmitFeed
     */
    private HttpCommandSubmitFeed $submitFeed;

    /**
     * @var ModuleList
     */
    private ModuleList $moduleList;

    /**
     * @var FeedPool
     */
    private FeedPool $feedPool;

    /**
     * @var FlagManager
     */
    private FlagManager $flagManager;

    /**
     * @var FeedRegistry
     */
    private FeedRegistry $feedRegistry;

    /**
     * @var CommerceDataExportLoggerInterface
     */
    private CommerceDataExportLoggerInterface $logger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $config;

    /**
     * @var string
     */
    private string $feedName;

    /**
     * @var string
     */
    private string $feedSyncFlag;

    /**
     * @var ResyncManagerPool
     */
    private ResyncManagerPool $resyncManagerPool;

    /**
     * @var ProcessManagerFactory
     */
    private ProcessManagerFactory $processManagerFactory;

    /**
     * @var BatchGeneratorInterface
     */
    private BatchGeneratorInterface $batchGenerator;

    /**
     * @var FeedLockManager
     */
    private FeedLockManager $feedLockManager;

    /**
     * @param FeedPool $feedPool
     * @param HttpCommandSubmitFeed $submitFeed
     * @param ModuleList $moduleList
     * @param FlagManager $flagManager
     * @param FeedRegistry $feedRegistry
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $config
     * @param string $feedSyncFlag
     * @param ResyncManagerPool $resyncManagerPool
     * @param BatchGeneratorInterface $batchGenerator
     * @param ProcessManagerFactory $processManagerFactory
     * @param FeedLockManager $feedLockManager
     * @param CommerceDataExportLoggerInterface $exporterLogger
     * @param ?string $feedName
     * @param ?FeedIndexMetadata $feedMetadata
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        FeedPool $feedPool,
        HttpCommandSubmitFeed $submitFeed,
        ModuleList $moduleList,
        FlagManager $flagManager,
        FeedRegistry $feedRegistry,
        LoggerInterface $logger,
        ScopeConfigInterface $config,
        string $feedSyncFlag,
        ResyncManagerPool $resyncManagerPool,
        BatchGeneratorInterface $batchGenerator,
        ProcessManagerFactory $processManagerFactory,
        FeedLockManager $feedLockManager,
        CommerceDataExportLoggerInterface $exporterLogger,
        ?string $feedName = null,
        ?FeedIndexMetadata $feedMetadata = null
    ) {
        $this->feedPool = $feedPool;
        $this->submitFeed = $submitFeed;
        $this->moduleList = $moduleList;
        $this->flagManager = $flagManager;
        $this->feedRegistry = $feedRegistry;
        $this->logger = $exporterLogger;
        $this->config = $config;
        $this->feedName = $feedMetadata ? $feedMetadata->getFeedName() : $feedName;
        $this->feedSyncFlag = $feedSyncFlag;
        $this->resyncManagerPool = $resyncManagerPool;
        $this->batchGenerator = $batchGenerator;
        $this->processManagerFactory = $processManagerFactory;
        $this->feedLockManager = $feedLockManager;
    }

    /**
     * Submit feed data
     *
     * @param array $data
     * @return bool
     * @throws UnableSendData|PrivateKeySignException
     */
    public function submitFeed(array $data) : bool
    {
        $feed = $this->feedPool->getFeed($this->feedName);
        $chunks = array_chunk($data['feed'], $feed->getFeedMetadata()->getBatchSize());
        foreach ($chunks as $chunk) {
            $filteredData = $this->feedRegistry->filter($chunk);
            $this->logger->logProgress(count($chunk), count($filteredData));
            if (!empty($filteredData)) {
                $result = $this->submitFeed->execute(
                    $this->feedName,
                    $filteredData
                );
                if (!$result->getStatus()->isSuccess()) {
                    return false;
                } else {
                    $this->feedRegistry->registerFeed($filteredData);
                }
            }
        }
        return true;
    }

    /**
     * Execute feed data submission
     *
     * @throws \Zend_Db_Statement_Exception
     */
    public function execute()
    {
        //TODO: looks like this check is not needed here
        $environment = $this->config->getValue(self::ENVIRONMENT_CONFIG_PATH);
        $key = $this->config->getValue(str_replace('{env}', $environment, Environment::API_KEY_PATH));
        if (!$key) {
            return;
        }

        try {
            $feed = $this->feedPool->getFeed($this->feedName);
            $metadata = $feed->getFeedMetadata();
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf('Cannot obtain feed metadata for feed name "%s". Process terminated', $this->feedName),
                ['exception' => $exception]
            );
            return ;
        }
        $isImmediateExport = $metadata->isExportImmediately();
        $operation = $isImmediateExport ? 'retry failed items' : 'partial sync (legacy)';

        $this->logger->initSyncLog($metadata, $operation, false);
        $feedNameForLock = $metadata->getFeedName();

        // non-immediate export feed can sync data in simultaneous with data collection phase
        if (!$isImmediateExport) {
            $feedNameForLock .= '_sync';
        }

        if (!$this->feedLockManager->lock($feedNameForLock, $operation)) {
            $this->logger->info(sprintf(
                'operation skipped - process locked by "%s"',
                $this->feedLockManager->getLockedByName($feedNameForLock)
            ));
            return;
        }

        try {
            $isImmediateExport ? $this->retryFailedItems($metadata) : $this->submit($metadata);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
        } finally {
            $this->feedLockManager->unlock($feedNameForLock);
        }
    }

    /**
     * Retry submit feed items which were failed during previous indexation due to server or application error
     *
     * @param FeedIndexMetadata $metadata
     * @return void
     */
    private function retryFailedItems(FeedIndexMetadata $metadata): void
    {
        $batchIterator = $this->batchGenerator->generate($metadata);
        $threadCount = min($metadata->getThreadCount(), $batchIterator->count());
        $userFunctions = [];
        for ($threadNumber = 1; $threadNumber <= $threadCount; $threadNumber++) {
            $userFunctions[] = function () use ($batchIterator, $metadata) {
                try {
                    foreach ($batchIterator as $ids) {
                        $this->resyncManagerPool->getResyncManager($metadata->getFeedName())
                            ->regenerateFeedDataByIds($ids);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Data Exporter exception has occurred: ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                    throw $e;
                }
            };
        }
        $processManager = $this->processManagerFactory->create(['threadsCount' => $threadCount]);
        $processManager->execute($userFunctions);
        if ($batchIterator->count() > 0) {
            $this->logger->complete();
        }
    }

    /**
     * Submit feed data.
     *
     * @param FeedIndexMetadata $metadata
     * @return void
     * @phpcs:disable Generic.Metrics.NestingLevel
     */
    private function submit(FeedIndexMetadata $metadata): void
    {
        $lastSyncTimestamp = $this->flagManager->getFlagData($this->feedSyncFlag) ?: '1';

        $batchIterator = $this->batchGenerator->generate($metadata, ['sinceTimestamp' => $lastSyncTimestamp]);
        $threadCount = min($metadata->getThreadCount(), $batchIterator->count());
        $userFunctions = [];
        for ($threadNumber = 1; $threadNumber <= $threadCount; $threadNumber++) {
            $userFunctions[] = function () use ($batchIterator) {
                try {
                    // phpcs:disable Generic.Formatting.DisallowMultipleStatements.SameLine
                    // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall
                    for ($batchIterator->rewind(); $batchIterator->valid(); $batchIterator->next()) {
                        $data = $batchIterator->current();
                        $result = $this->submitFeed($data);
                        if ($result) {
                            $this->flagManager->saveFlag($this->feedSyncFlag, $data['recentTimestamp']);
                        } else {
                            $batchIterator->markBatchForRetry();
                        }
                    }
                    // phpcs:enable Generic.Formatting.DisallowMultipleStatements.SameLine
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Data Exporter exception has occurred: ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                    throw $e;
                }
            };
        }
        $processManager = $this->processManagerFactory->create(['threadsCount' => $threadCount]);
        $processManager->execute($userFunctions);
        if ($batchIterator->count() > 0) {
            $this->logger->complete();
        }
    }
}
