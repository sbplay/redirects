<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Redirects\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\DataHandling\Model\CorrelationId;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Hooks\DataHandlerSlugUpdateHook;

/**
 * @internal Due to some possible refactorings in TYPO3 v10
 */
class SlugService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * `dechex(1569615472)` (similar to timestamps used with exceptions, but in hex)
     */
    public const CORRELATION_ID_IDENTIFIER = '5d8e6e70';

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var LanguageService
     */
    protected $languageService;

    /**
     * @var SiteInterface
     */
    protected $site;

    /**
     * @var SiteFinder
     */
    protected $siteFinder;

    /**
     * @var PageRepository
     */
    protected $pageRepository;

    /**
     * @var CorrelationId|string
     */
    protected $correlationIdRedirectCreation = '';

    /**
     * @var CorrelationId|string
     */
    protected $correlationIdSlugUpdate = '';

    /**
     * @var bool
     */
    protected $autoUpdateSlugs;

    /**
     * @var bool
     */
    protected $autoCreateRedirects;

    /**
     * @var int
     */
    protected $redirectTTL;

    /**
     * @var int
     */
    protected $httpStatusCode;

    public function __construct(Context $context, LanguageService $languageService, SiteFinder $siteFinder, PageRepository $pageRepository)
    {
        $this->context = $context;
        $this->languageService = $languageService;
        $this->siteFinder = $siteFinder;
        $this->pageRepository = $pageRepository;
    }

    public function rebuildSlugsForSlugChange(int $pageId, string $currentSlug, string $newSlug, CorrelationId $correlationId): void
    {
        $currentPageRecord = BackendUtility::getRecord('pages', $pageId);
        if ($currentPageRecord === null) {
            return;
        }
        $defaultPageId = (int)$currentPageRecord['sys_language_uid'] > 0 ? (int)$currentPageRecord['l10n_parent'] : $pageId;
        $this->initializeSettings($defaultPageId);
        if ($this->autoUpdateSlugs || $this->autoCreateRedirects) {
            $this->createCorrelationIds($pageId, $correlationId);
            if ($this->autoCreateRedirects) {
                $this->createRedirect($currentSlug, $newSlug, (int)$currentPageRecord['sys_language_uid']);
            }
            if ($this->autoUpdateSlugs) {
                $this->checkSubPages($currentPageRecord, $currentSlug, $newSlug);
            }
            $this->sendNotification();
        }
    }

    protected function initializeSettings(int $pageId): void
    {
        $this->site = $this->siteFinder->getSiteByPageId($pageId);
        $settings = $this->site->getConfiguration()['settings']['redirects'] ?? [];
        $this->autoUpdateSlugs = $settings['autoUpdateSlugs'] ?? true;
        $this->autoCreateRedirects = $settings['autoCreateRedirects'] ?? true;
        if (!$this->context->getPropertyFromAspect('workspace', 'isLive')) {
            $this->autoCreateRedirects = false;
        }
        $this->redirectTTL = (int)($settings['redirectTTL'] ?? 0);
        $this->httpStatusCode = (int)($settings['httpStatusCode'] ?? 307);
    }

    protected function createCorrelationIds(int $pageId, CorrelationId $correlationId): void
    {
        if ($correlationId->getSubject() === null) {
            $subject = md5('pages:' . $pageId);
            $correlationId = $correlationId->withSubject($subject);
        }

        $this->correlationIdRedirectCreation = $correlationId->withAspects(self::CORRELATION_ID_IDENTIFIER, 'redirect');
        $this->correlationIdSlugUpdate = $correlationId->withAspects(self::CORRELATION_ID_IDENTIFIER, 'slug');
    }

    protected function createRedirect(string $originalSlug, string $newSlug, int $languageId): void
    {
        $basePath = rtrim($this->site->getLanguageById($languageId)->getBase()->getPath(), '/');

        /** @var DateTimeAspect $date */
        $date = $this->context->getAspect('date');
        $endtime = $date->getDateTime()->modify('+' . $this->redirectTTL . ' days');
        $record = [
            'pid' => 0,
            'updatedon' => $date->get('timestamp'),
            'createdon' => $date->get('timestamp'),
            'createdby' => $this->context->getPropertyFromAspect('backend.user', 'id'),
            'deleted' => 0,
            'disabled' => 0,
            'starttime' => 0,
            'endtime' => $this->redirectTTL > 0 ? $endtime->getTimestamp() : 0,
            'source_host' => $this->site->getBase()->getHost() ?: '*',
            'source_path' => $basePath . $originalSlug,
            'is_regexp' => 0,
            'force_https' => 0,
            'respect_query_parameters' => 0,
            'target' => $basePath . $newSlug,
            'target_statuscode' => $this->httpStatusCode,
            'hitcount' => 0,
            'lasthiton' => 0,
            'disable_hitcount' => 0,
        ];
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', $record);
        $id = (int)$connection->lastInsertId('sys_redirect');
        $record['uid'] = $id;
        $this->getRecordHistoryStore()->addRecord('sys_redirect', $id, $record, $this->correlationIdRedirectCreation);
    }

    protected function checkSubPages(array $currentPageRecord, string $oldSlugOfParentPage, string $newSlugOfParentPage): void
    {
        $languageUid = (int)$currentPageRecord['sys_language_uid'];
        // resolveSubPages needs the page id of the default language
        $pageId = $languageUid === 0 ? (int)$currentPageRecord['uid'] : (int)$currentPageRecord['l10n_parent'];
        $subPageRecords = $this->resolveSubPages($pageId, $languageUid);
        foreach ($subPageRecords as $subPageRecord) {
            $newSlug = $this->updateSlug($subPageRecord, $oldSlugOfParentPage, $newSlugOfParentPage);
            if ($newSlug !== null && $this->autoCreateRedirects) {
                $this->createRedirect($subPageRecord['slug'], $newSlug, $languageUid);
            }
        }
    }

    protected function resolveSubPages(int $id, int $languageUid): array
    {
        // First resolve all sub-pages in default language
        $queryBuilder = $this->getQueryBuilderForPages();
        $subPages = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->orderBy('uid', 'ASC')
            ->execute()
            ->fetchAll();

        // if the language is not the default language, resolve the language related records.
        if ($languageUid > 0) {
            $queryBuilder = $this->getQueryBuilderForPages();
            $subPages = $queryBuilder
                ->select('*')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter(array_column($subPages, 'uid'), Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, \PDO::PARAM_INT))
                )
                ->orderBy('uid', 'ASC')
                ->execute()
                ->fetchAll();
        }
        $results = [];
        if (!empty($subPages)) {
            $subPages = $this->pageRepository->getPagesOverlay($subPages, $languageUid);
            foreach ($subPages as $subPage) {
                $results[] = $subPage;
                // resolveSubPages needs the page id of the default language
                $pageId = $languageUid === 0 ? (int)$subPage['uid'] : (int)$subPage['l10n_parent'];
                foreach ($this->resolveSubPages($pageId, $languageUid) as $page) {
                    $results[] = $page;
                }
            }
        }
        return $results;
    }

    /**
     * Update a slug by given record, old parent page slug and new parent page slug.
     * In case no update is required, the method returns null else the new slug.
     */
    protected function updateSlug(array $subPageRecord, string $oldSlugOfParentPage, string $newSlugOfParentPage): ?string
    {
        if (strpos($subPageRecord['slug'], $oldSlugOfParentPage) !== 0) {
            return null;
        }

        $newSlug = rtrim($newSlugOfParentPage, '/') . '/'
            . substr($subPageRecord['slug'], strlen(rtrim($oldSlugOfParentPage, '/') . '/'));
        $state = RecordStateFactory::forName('pages')
            ->fromArray($subPageRecord, $subPageRecord['pid'], $subPageRecord['uid']);
        $fieldConfig = $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? [];
        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $fieldConfig);

        if (!$slugHelper->isUniqueInSite($newSlug, $state)) {
            $newSlug = $slugHelper->buildSlugForUniqueInSite($newSlug, $state);
        }

        $this->persistNewSlug((int)$subPageRecord['uid'], $newSlug);
        return $newSlug;
    }

    protected function persistNewSlug(int $uid, string $newSlug): void
    {
        $this->disableHook();
        $data = [];
        $data['pages'][$uid]['slug'] = $newSlug;
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->setCorrelationId($this->correlationIdSlugUpdate);
        $dataHandler->process_datamap();
        $this->enabledHook();
    }

    protected function sendNotification(): void
    {
        $data = [
            'componentName' => 'redirects',
            'eventName' => 'slugChanged',
            'correlations' => [
                'correlationIdSlugUpdate' => $this->correlationIdSlugUpdate,
                'correlationIdRedirectCreation' => $this->correlationIdRedirectCreation,
            ],
            'autoUpdateSlugs' => (bool)$this->autoUpdateSlugs,
            'autoCreateRedirects' => (bool)$this->autoCreateRedirects,
        ];
        GeneralUtility::makeInstance(PageRenderer::class)->loadRequireJsModule(
            'TYPO3/CMS/Backend/BroadcastService',
            sprintf('function(service) { service.post(%s); }', json_encode($data))
        );
    }

    protected function getRecordHistoryStore(): RecordHistoryStore
    {
        $backendUser = $GLOBALS['BE_USER'];
        return GeneralUtility::makeInstance(
            RecordHistoryStore::class,
            RecordHistoryStore::USER_BACKEND,
            $backendUser->user['uid'],
            (int)$backendUser->getOriginalUserIdWhenInSwitchUserMode(),
            $this->context->getPropertyFromAspect('date', 'timestamp'),
            $backendUser->workspace ?? 0
        );
    }

    protected function getQueryBuilderForPages(): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        /** @noinspection PhpStrictTypeCheckingInspection */
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->context->getPropertyFromAspect('workspace', 'id')));
        return $queryBuilder;
    }

    protected function enabledHook(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['redirects'] =
            DataHandlerSlugUpdateHook::class;
    }

    protected function disableHook(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['redirects']);
    }
}
