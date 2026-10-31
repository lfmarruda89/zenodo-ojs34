<?php

/**
 * @file plugins/generic/zenodo/filter/ZenodoJsonFilter.php
 *
 * Copyright (c) 2025-2026 Simon Fraser University
 * Copyright (c) 2025-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ZenodoJsonFilter
 *
 * @ingroup plugins_generic_zenodo
 *
 * @brief Converts OJS 3.4.0-10 publications to Zenodo InvenioRDM JSON.
 * Compatibility: no review DOI, data citations, structured funding, ROR or stage APIs.
 */

namespace APP\plugins\generic\zenodo\filter;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\zenodo\ZenodoExportDeployment;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use PKP\db\DAORegistry;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;

class ZenodoJsonFilter extends PKPImportExportFilter
{
    /**
     * InvenioRDM resource type titles keyed by resource type id.
     * https://github.com/inveniosoftware/invenio-rdm-records/blob/master/invenio_rdm_records/fixtures/data/vocabularies/resource_types.yaml
     */
    private const RESOURCE_TYPE_TITLES = [
        'publication-article' => 'Journal article',
        'publication-journal' => 'Journal',
        'publication-book' => 'Book',
        'publication-section' => 'Book chapter',
        'publication-conferencepaper' => 'Conference paper',
        'publication-conferenceproceeding' => 'Conference proceeding',
        'publication-preprint' => 'Preprint',
        'publication-report' => 'Report',
        'publication-standard' => 'Standard',
        'publication-dissertation' => 'Thesis',
        'publication-peerreview' => 'Peer review',
        'publication-other' => 'Other',
        'dataset' => 'Dataset',
    ];

    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Zenodo JSON export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @param Submission|Publication $pubObject
     *
     * @throws Exception
     *
     * @return string JSON
     *
     * @see Filter::process()
     *
     */
    public function &process(&$pubObject)
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();
        $cache = $plugin->getCache();

        if ($pubObject instanceof Submission) {
            $submission = $pubObject;
            $publication = $pubObject->getCurrentPublication();
            $submissionId = $pubObject->getId();
            if (!$cache->isCached('articles', $submissionId)) {
                $cache->add($submission, null);
            }
        } elseif ($pubObject instanceof Publication) {
            $publication = $pubObject;
            $submissionId = $pubObject->getData('submissionId');
            if ($cache->isCached('articles', $submissionId)) {
                $submission = $cache->get('articles', $submissionId); /** @var Submission $submission */
            } else {
                $submission = Repo::submission()->get($submissionId, $context->getId());
                if ($submission) {
                    $cache->add($submission, null);
                }
            }
        } else {
            throw new Exception('Invalid object type');
        }

        if (!$publication || !$submission) {
            throw new Exception('Publication or submission not found for Zenodo export');
        }
        $publicationLocale = $publication->getData('locale') ?: $context->getPrimaryLocale();

        $issueId = $publication->getData('issueId');
        $issue = null;
        if ($issueId) {
            if ($cache->isCached('issues', $issueId)) {
                $issue = $cache->get('issues', $issueId); /** @var Issue $issue */
            } else {
                $issue = Repo::issue()->get($issueId, $context->getId());
                if ($issue) {
                    $cache->add($issue, null);
                }
            }
        }

        $article = [];

        // Access Rights
        $fileAccess = 'public';
        $embargoUntil = null;

        if (
            $issue &&
            $context->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION &&
            $issue->getAccessStatus() == Issue::ISSUE_ACCESS_SUBSCRIPTION &&
            $publication->getData('accessStatus') != Submission::ARTICLE_ACCESS_OPEN
        ) {
            $openAccessDate = $issue->getOpenAccessDate() ? Carbon::parse($issue->getOpenAccessDate()) : null;
            if (!$openAccessDate) {
                $fileAccess = 'restricted';
            } elseif ($openAccessDate->isFuture()) {
                // Zenodo requires the embargo date to be in the future; a past date means the issue is open.
                $fileAccess = 'restricted';
                $embargoUntil = $openAccessDate;
            }
        }

        $article['access'] = [
            'files' => $fileAccess,
            'record' => 'public', // only files can be restricted
        ];

        if ($embargoUntil) {
            $article['access']['embargo'] = [
                'active' => true,
                'until' => $embargoUntil->format('Y-m-d'),
            ];
        }

        // Every record carries the article's galley files; metadata-only records are not deposited.
        $article['files'] = [
            'enabled' => true,
        ];

        // Journal Metadata
        $journalData = $this->getJournalData($context, $publication, $issue);
        $article['custom_fields'] = ['journal:journal' => $journalData];

        $article['metadata'] = [];

        // Resource type
        $article['metadata']['resource_type'] = [
            'id' => 'publication-article',
        ];

        // Article title
        if ($publication->getLocalizedTitle($publicationLocale)) {
            $article['metadata']['title'] = $publication->getLocalizedTitle($publicationLocale);
        }

        // Subtitle and translated titles
        $additionalTitles = $this->getAdditionalTitlesData($publication, $publicationLocale);
        if (!empty($additionalTitles)) {
            $article['metadata']['additional_titles'] = $additionalTitles;
        }

        // Creators and contributors: names, identifiers, affiliations and roles
        [$creators, $contributors] = $this->getContributorsData($publication, $publicationLocale);
        if (!empty($creators)) {
            $article['metadata']['creators'] = $creators;
        }
        if (!empty($contributors)) {
            $article['metadata']['contributors'] = $contributors;
        }

        // Abstract (InvenioRDM accepts sanitized HTML) and translated abstracts
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::stripUnsafeHtml($abstract);
        }
        foreach ($publication->getData('abstract') ?? [] as $locale => $localizedAbstract) {
            if ($locale == $publicationLocale || !$this->isValidText($localizedAbstract)) {
                continue;
            }
            $article['metadata']['additional_descriptions'][] = $this->withLanguage([
                'description' => PKPString::stripUnsafeHtml($localizedAbstract),
                'type' => ['id' => 'abstract'],
            ], $locale);
        }

        // Publication date
        if ($publication->getData('datePublished')) {
            $article['metadata']['publication_date'] = Carbon::parse(
                $publication->getData('datePublished')
            )->format('Y-m-d');
        } elseif ($issue?->getDatePublished()) {
            $article['metadata']['publication_date'] = Carbon::parse(
                $issue->getDatePublished()
            )->format('Y-m-d');
        }

        // Publisher name, falling back to the journal name
        $publisher = $context->getData('publisherInstitution') ?: $context->getLocalizedName($publicationLocale);
        if (!empty($publisher)) {
            $article['metadata']['publisher'] = $publisher;
        }

        // OJS 3.4 stores parsed references in CitationDAO, not as Citation
        // objects in publication metadata. Preserve their original sequence/text.
        $citationDao = DAORegistry::getDAO('CitationDAO');
        $citations = $citationDao->getByPublicationId($publication->getId());
        while ($citation = $citations->next()) {
            $reference = trim((string) $citation->getRawCitation());
            if ($reference !== '') {
                $article['metadata']['references'][] = ['reference' => $reference];
            }
        }

        // FullText URL relation
        $request = Application::get()->getRequest();
        // The 3.4 article route accepts an explicit publication version id.
        $path = [$publication->getData('urlPath') ?: $submissionId,
            'version', $publication->getId()];

        $url = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            $path
        );

        $article['metadata']['related_identifiers'][] = [
            'identifier' => $url,
            'relation_type' => [
                'id' => 'isidenticalto'
            ],
            'scheme' => 'url',
            'resource_type' => $this->resourceType('publication-article'),
        ];

        // Online ISSN relation
        $onlineIssn = $context->getData('onlineIssn');
        if ($onlineIssn) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $onlineIssn,
                'relation_type' => [
                    'id' => 'ispublishedin'
                ],
                'scheme' => 'issn',
                'resource_type' => $this->resourceType('publication-journal'),
            ];
        }

        // Print ISSN relation
        $printIssn = $context->getData('printIssn');
        if ($printIssn) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $printIssn,
                'relation_type' => [
                    'id' => 'ispublishedin'
                ],
                'scheme' => 'issn',
                'resource_type' => $this->resourceType('publication-journal'),
            ];
        }

        // Issue relation
        if ($issue?->getDoi()) {
            $article['metadata']['related_identifiers'][] = [
                'identifier' => $issue->getDoi(),
                'relation_type' => [
                    'id' => 'ispartof'
                ],
                'scheme' => 'doi',
                'resource_type' => $this->resourceType('publication-journal'),
            ];
        }

        // Review DOI and stage-based version relations are not available in 3.4.

        // Keywords and Subjects
        $keywords = $publication->getLocalizedData('keywords', $publicationLocale) ?? [];
        $subjects = $publication->getLocalizedData('subjects', $publicationLocale) ?? [];
        $keywordsSubjects = array_merge($keywords, $subjects);
        if (!empty($keywordsSubjects)) {
            $subjectMetadata = [];
            foreach ($keywordsSubjects as $subject) {
                $subjectMetadata[] = [
                    'subject' => $subject,
                ];
            }
            $article['metadata']['subjects'] = $subjectMetadata;
        }

        // Structured funders/grants and major/minor stages are unavailable in 3.4.
        $version = $publication->getData('version');
        if ($version !== null && $version !== '') {
            $article['metadata']['version'] = (string) $version;
        }

        // Languages (ISO 639-3)
        $languagesData = $this->getLanguagesData($publication, $publicationLocale);
        if (!empty($languagesData)) {
            foreach ($languagesData as $language) {
                $article['metadata']['languages'][] = [
                    'id' => $language,
                ];
            }
        }

        // Copyright statement
        if ($publication->getData('copyrightHolder', $publicationLocale) && $publication->getData('copyrightYear')) {
            $article['metadata']['copyright'] = __('submission.copyrightStatement', [
                'copyrightYear' => $publication->getData('copyrightYear'),
                'copyrightHolder' => $publication->getData('copyrightHolder', $publicationLocale)
            ]);
        }

        // License
        $licenseUrl = $publication->getData('licenseUrl') ?: $context->getData('licenseUrl') ?: '';
        $rights = $this->getRightsData($licenseUrl);
        if ($rights) {
            $article['metadata']['rights'][] = $rights;
        }

        // Dates
        // https://inveniordm.docs.cern.ch/reference/metadata/#dates-0-n
        $dates = [];
        if ($submission?->getData('dateSubmitted')) {
            $dates[] = $this->dateEntry($submission->getData('dateSubmitted'), 'submitted', 'Submission date');
        }
        if ($publication->getData('lastModified')) {
            $dates[] = $this->dateEntry($publication->getData('lastModified'), 'updated', 'Last modified');
        }
        if ($embargoUntil) {
            $dates[] = $this->dateEntry($embargoUntil, 'available', 'Open access date');
        }
        if (!empty($dates)) {
            $article['metadata']['dates'] = $dates;
        }

        // DOI
        $doi = $publication->getDoi();
        if (!empty($doi)) {
            $article['pids'] =
                [
                    'doi' => [
                        'provider' => 'external',
                        'identifier' => $doi
                    ],
                ];
        }

        $json = json_encode($article, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $json;
    }

    /**
     * Helper function returning an InvenioRDM resource type array (id and title) for a resource type id.
     */
    private function resourceType(string $resourceTypeId): array
    {
        return ['id' => $resourceTypeId, 'title' => ['en' => self::RESOURCE_TYPE_TITLES[$resourceTypeId]]];
    }

    /**
     * Helper function returning an InvenioRDM date entry.
     */
    private function dateEntry(string|Carbon $date, string $typeId, string $description): array
    {
        return [
            'date' => Carbon::parse($date)->format('Y-m-d'),
            'type' => ['id' => $typeId],
            'description' => $description,
        ];
    }

    /**
     * Helper function adding an ISO 639-3 language to a title or description entry.
     */
    private function withLanguage(array $entry, string $locale): array
    {
        $iso3 = LocaleConversion::getIso3FromLocale($locale);
        if ($iso3) {
            $entry['lang'] = ['id' => $iso3];
        }
        return $entry;
    }

    /**
     * InvenioRDM rejects titles and descriptions shorter than three characters.
     */
    private function isValidText(?string $text): bool
    {
        return mb_strlen(trim(strip_tags((string) $text))) >= 3;
    }

    /**
     * Helper function for the subtitle and the titles in other locales.
     * https://inveniordm.docs.cern.ch/reference/metadata/#additional-titles-0-n
     */
    private function getAdditionalTitlesData(Publication $publication, string $publicationLocale): array
    {
        $titles = [];
        $allTitles = $publication->getTitles();
        $allSubtitles = $publication->getSubTitles();

        if ($this->isValidText($allSubtitles[$publicationLocale] ?? null)) {
            $titles[] = $this->withLanguage([
                'title' => $allSubtitles[$publicationLocale],
                'type' => ['id' => 'subtitle'],
            ], $publicationLocale);
        }

        foreach ($allTitles as $locale => $title) {
            if ($locale == $publicationLocale) {
                continue;
            }
            $fullTitle = isset($allSubtitles[$locale])
                ? PKPString::concatTitleFields([$title, $allSubtitles[$locale]])
                : $title;
            if ($this->isValidText($fullTitle)) {
                $titles[] = $this->withLanguage([
                    'title' => $fullTitle,
                    'type' => ['id' => 'translated-title'],
                ], $locale);
            }
        }

        return $titles;
    }

    /**
     * Helper function mapping a license URL to an InvenioRDM rights entry: a vocabulary id for
     * Creative Commons licenses (e.g. cc-by-4.0, cc0-1.0), otherwise a custom title and link.
     * https://inveniordm.docs.cern.ch/reference/metadata/#rights-licenses-0-n
     */
    private function getRightsData(string $licenseUrl): ?array
    {
        $licenseUrl = trim($licenseUrl);
        if ($licenseUrl === '') {
            return null;
        }

        $ccPattern = '#^https?://(?:www\.)?creativecommons\.org/(?:licenses/([a-z-]+)|publicdomain/(zero|mark))/(\d\.\d)/?(?:(?:deed|legalcode)(?:\.[a-z_]+)?)?$#i';
        if (preg_match($ccPattern, $licenseUrl, $match)) {
            $id = match (strtolower($match[2])) {
                'zero' => 'cc0-' . $match[3],
                'mark' => 'cc-pdm-' . $match[3],
                default => 'cc-' . strtolower($match[1]) . '-' . $match[3],
            };
            return ['id' => $id];
        }

        if (!filter_var($licenseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }
        return [
            'title' => ['en' => $licenseUrl],
            'link' => $licenseUrl,
        ];
    }

    /**
     * Helper function for journal metadata.
     * https://inveniordm.docs.cern.ch/reference/metadata/#journal
     */
    private function getJournalData(Context $context, Publication $publication, ?Issue $issue = null): array
    {
        $journalData = [];

        // Journal title
        $journalData['title'] = $context->getLocalizedName($publication->getData('locale'));

        // ISSN
        if ($issn = $context->getData('onlineIssn') ?: $context->getData('printIssn')) {
            $journalData['issn'] = $issn;
        }

        // Volume and Issue Number
        if ($issue) {
            $volume = $issue->getVolume();
            if (!empty($volume)) {
                $journalData['volume'] = (string)$volume;
            }

            $issueNumber = $issue->getNumber();
            if (!empty($issueNumber)) {
                $journalData['issue'] = $issueNumber;
            }
        }

        // Keep the complete 3.4 pages field, including discontinuous ranges/e-locators.
        $pages = $publication->getData('pages');
        if ($pages !== null && $pages !== '') {
            $journalData['pages'] = (string) $pages;
        }

        return $journalData;
    }

    /**
     * OJS 3.4 has personal authors with localized names and one text affiliation.
     * Export every publication author in its stored order as a creator.
     */
    private function getContributorsData(Publication $publication, string $publicationLocale): array
    {
        $creators = [];
        foreach ($publication->getData('authors') ?? [] as $articleAuthor) {
            $entry = $this->getPersonOrOrgData($articleAuthor, $publicationLocale);
            if ($entry !== null) {
                $creators[] = $entry;
            }
        }
        return [$creators, []];
    }

    private function getPersonOrOrgData(Author $articleAuthor, string $publicationLocale): ?array
    {
        // Use the same locale for given and family names to avoid mixing translations.
        $locale = $publicationLocale;
        if (!$articleAuthor->getGivenName($locale) && !$articleAuthor->getFamilyName($locale)) {
            $locale = $articleAuthor->getSubmissionLocale();
        }
        $givenName = trim((string) $articleAuthor->getGivenName($locale));
        $familyName = trim((string) $articleAuthor->getFamilyName($locale));
        if ($givenName === '' && $familyName === '') {
            throw new Exception('Zenodo export: author ' . $articleAuthor->getId() . ' has no name');
        }
        $person = ['type' => 'personal', 'family_name' => $familyName ?: $givenName];
        if ($familyName !== '' && $givenName !== '') {
            $person['given_name'] = $givenName;
        }
        // OJS 3.4 stores ORCID on Identity. Do not claim authentication status.
        $orcid = trim((string) $articleAuthor->getOrcid());
        if (preg_match('~^(?:https?://orcid\.org/)?(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/?$~i', $orcid, $matches)) {
            $person['identifiers'][] = ['scheme' => 'orcid', 'identifier' => strtoupper($matches[1])];
        }
        $entry = ['person_or_org' => $person];
        $affiliation = trim(strip_tags((string) $articleAuthor->getLocalizedData('affiliation', $publicationLocale)));
        if ($affiliation !== '') {
            $entry['affiliations'] = [['name' => $affiliation]];
        }
        return $entry;
    }

    /**
     * Helper function for language metadata which collects publication language
     * and galley languages.
     */
    private function getLanguagesData(Publication $publication, string $publicationLocale): array
    {
        $languageList = [];
        $languageList[] = LocaleConversion::getIso3FromLocale($publicationLocale);
        $galleys = $publication->getData('galleys');
        if (!empty($galleys)) {
            foreach ($publication->getData('galleys') as $galley) { /** @var Galley $galley */
                $languageList[] = LocaleConversion::getIso3FromLocale($galley->getLocale());
            }
        }
        return array_values(array_unique(array_filter($languageList)));
    }
}
