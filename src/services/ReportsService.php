<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\services;

use craft\db\ActiveRecord;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\elements\CampaignElement;
use putyourlightson\campaign\elements\ContactElement;
use putyourlightson\campaign\elements\MailingListElement;
use putyourlightson\campaign\elements\SendoutElement;
use putyourlightson\campaign\helpers\NumberHelper;
use putyourlightson\campaign\models\ContactActivityModel;
use putyourlightson\campaign\models\LinkModel;
use putyourlightson\campaign\models\ContactCampaignModel;
use putyourlightson\campaign\models\ContactMailingListModel;
use putyourlightson\campaign\records\ContactRecord;
use putyourlightson\campaign\records\LinkRecord;
use putyourlightson\campaign\records\ContactCampaignRecord;
use putyourlightson\campaign\records\ContactMailingListRecord;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;

/**
 * ReportsService
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 *
 * @property array $mailingListsChartData
 * @property array $contactsReportData
 * @property array $campaignsReportData
 * @property array $campaignsChartData
 * @property array $mailingListsReportData
 */
class ReportsService extends Component
{
    // Constants
    // =========================================================================

    const MIN_INTERVALS = 5;

    // Public Methods
    // =========================================================================

    /**
     * Returns max intervals
     *
     * @param string $interval
     * @return int
     */
    public function getMaxIntervals(string $interval): int
    {
        $maxIntervals = ['minutes' => 60, 'hours' => 24, 'days' => 14, 'months'=> 12, 'years' => 10];

        return $maxIntervals[$interval] ?? 12;
    }

    /**
     * Returns campaigns report data
     *
     * @param int|null $siteId
     *
     * @return array
     */
    public function getCampaignsReportData(int $siteId = null): array
    {
        // Get all sent campaigns
        $data['campaigns'] = CampaignElement::find()
            ->status(CampaignElement::STATUS_SENT)
            ->orderBy('lastSent DESC')
            ->siteId($siteId)
            ->all();

        // Get data
        $data['recipients'] = 0;
        $data['opened'] = 0;
        $data['clicked'] = 0;

        /** @var CampaignElement $campaign */
        foreach ($data['campaigns'] as $campaign) {
            $data['recipients'] += $campaign->recipients;
            $data['opened'] += $campaign->opened;
            $data['clicked'] += $campaign->clicked;
        }

        $data['clickThroughRate'] = $data['opened'] ? NumberHelper::floorOrOne($data['clicked'] / $data['opened'] * 100) : 0;

        // Get sendouts count
        $data['sendouts'] = SendoutElement::find()
            ->siteId($siteId)
            ->count();

        return $data;
    }

    /**
     * Returns campaign report data
     *
     * @param int $campaignId
     *
     * @return array
     */
    public function getCampaignReportData(int $campaignId): array
    {
        // Get campaign
        $data['campaign'] = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        // Get sendouts
        $data['sendouts'] = SendoutElement::find()
            ->campaignId($campaignId)
            ->orderBy(['sendDate' => SORT_ASC])
            ->all();

        // Get date first sent
        /** @var ContactCampaignRecord|null $contactCampaignRecord */
        $contactCampaignRecord = ContactCampaignRecord::find()
            ->where(['campaignId' => $campaignId])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit(1)
            ->one();

        $data['dateFirstSent'] = $contactCampaignRecord === null ? null : DateTimeHelper::toDateTime($contactCampaignRecord->dateCreated);

        // Check if chart exists
        $data['hasChart'] = count($this->getCampaignContactActivity($campaignId, 'opened', 1)) > 0;

        return $data;
    }

    /**
     * Returns campaign chart data
     *
     * @param int $campaignId
     * @param string|null $interval
     *
     * @return array
     */
    public function getCampaignChartData(int $campaignId, string $interval = null): array
    {
        $interval = $interval ?? 'hours';

        return $this->_getChartData(
            ContactCampaignRecord::class,
            ['campaignId' => $campaignId],
            ContactCampaignModel::INTERACTIONS,
            $interval
        );
    }

    /**
     * Returns campaign recipients
     *
     * @param int $campaignId
     * @param int|null $sendoutId
     * @param int|null $limit
     *
     * @return ContactCampaignModel[]
     */
    public function getCampaignRecipients(int $campaignId, int $sendoutId = null, int $limit = null): array
    {
        $contactCampaignQuery = ContactCampaignRecord::find()
            ->where(['campaignId' => $campaignId])
            ->orderBy(['sent' => SORT_DESC]);

        if ($sendoutId !== null) {
            $contactCampaignQuery->andWhere(['sendoutId' => $sendoutId]);
        }

        $contactCampaignRecords = $contactCampaignQuery->all();

        return ContactCampaignModel::populateModels($contactCampaignRecords, false);
    }

    /**
     * Returns campaign contact activity
     *
     * @param int $campaignId
     * @param string|null $interaction
     * @param int|null $limit
     *
     * @return ContactActivityModel[]
     */
    public function getCampaignContactActivity(int $campaignId, string $interaction = null, int $limit = null): array
    {
        // If no interaction was specified then set check for any interaction that is not null
        $interactionCondition = $interaction ? [$interaction => null] : [
            'or',
            [
                'opened' => null,
                'clicked' => null,
                'unsubscribed' => null,
                'complained' => null,
                'bounced' => null,
            ]
        ];

        $contactCampaignRecords = ContactCampaignRecord::find()
            ->where(['campaignId' => $campaignId])
            ->andWhere(['not', $interactionCondition])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        $contactCampaignModels = ContactCampaignModel::populateModels($contactCampaignRecords, false);

        // Return contact activity
        return $this->_getActivity($contactCampaignModels, $interaction, $limit);
    }

    /**
     * Returns campaign links
     *
     * @param int $campaignId
     * @param int|null $limit
     *
     * @return LinkModel[]
     */
    public function getCampaignLinks(int $campaignId, int $limit = null): array
    {
        // Get campaign links
        $linkRecords = LinkRecord::find()
            ->where(['campaignId' => $campaignId])
            ->orderBy(['clicked' => SORT_DESC, 'clicks' => SORT_DESC])
            ->limit($limit)
            ->all();

        return LinkModel::populateModels($linkRecords, false);
    }

    /**
     * Returns campaign locations
     *
     * @param int $campaignId
     * @param int|null $limit
     *
     * @return array
     */
    public function getCampaignLocations(int $campaignId, int $limit = null): array
    {
        // Get campaign
        $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        if ($campaign === null) {
            return [];
        }

        // Return locations of contact campaigns
        return $this->_getLocations(ContactCampaignRecord::class, ['and', ['campaignId' => $campaignId], ['not', ['opened' => null]]], $campaign->opened, $limit);
    }

    /**
     * Returns campaign devices
     *
     * @param int $campaignId
     * @param bool $detailed
     * @param int|null $limit
     *
     * @return array
     */
    public function getCampaignDevices(int $campaignId, bool $detailed = false, int $limit = null): array
    {
        // Get campaign
        $campaign = Campaign::$plugin->campaigns->getCampaignById($campaignId);

        if ($campaign === null) {
            return [];
        }

        // Return device, os and client of contact campaigns
        return $this->_getDevices(ContactCampaignRecord::class, ['and', ['campaignId' => $campaignId], ['not', ['opened' => null]]], $detailed, $campaign->opened, $limit);
    }

    /**
     * Returns contacts report data
     *
     * @return array
     */
    public function getContactsReportData(): array
    {
        $data = [];

        // Get interactions
        $interactions = ContactMailingListModel::INTERACTIONS;

        foreach ($interactions as $interaction) {
            $count = ContactMailingListRecord::find()
                ->where(['subscriptionStatus' => $interaction])
                ->count();

            $data[$interaction] = $count;
        }

        $data['total'] = ContactMailingListRecord::find()->count();

        return $data;
    }

    /**
     * Returns contacts activity
     *
     * @param int|null $limit
     *
     * @return array
     */
    public function getContactsActivity(int $limit = null): array
    {
        // Get recently active contacts
        return ContactElement::find()
            ->orderBy(['lastActivity' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Returns contacts locations
     *
     * @param int|null $limit
     *
     * @return array
     */
    public function getContactsLocations(int $limit = null): array
    {
        // Get total active contacts
        $total = ContactElement::find()
            ->where(['complained' => null, 'bounced' => null])
            ->count();

        // Return locations of contacts
        return $this->_getLocations(ContactRecord::class, [], $total, $limit);
    }

    /**
     * Returns contacts devices
     *
     * @param bool $detailed
     * @param int|null $limit
     *
     * @return array
     */
    public function getContactsDevices(bool $detailed = false, int $limit = null): array
    {
        // Get total active contacts
        $total = ContactElement::find()
            ->where(['complained' => null, 'bounced' => null])
            ->count();

        // Return device, os and client of contacts
        return $this->_getDevices(ContactRecord::class, [], $detailed, $total, $limit);
    }

    /**
     * Returns contact campaigns
     *
     * @param int $contactId
     * @param int|null $limit
     * @param int|int[]|null $campaignId
     *
     * @return ContactActivityModel[]
     */
    public function getContactCampaignActivity(int $contactId, int $limit = null, $campaignId = null): array
    {
        $conditions = ['contactId' => $contactId];

        if ($campaignId !== null) {
            $conditions['campaignId'] = $campaignId;
        }

        // Get contact campaigns
        $contactCampaignRecords = ContactCampaignRecord::find()
            ->where($conditions)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        $contactCampaignModels = ContactCampaignModel::populateModels($contactCampaignRecords, false);

        // Return contact activity
        return $this->_getActivity($contactCampaignModels, null, $limit);
    }

    /**
     * Returns contact mailing list activity
     *
     * @param int $contactId
     * @param int|null $limit
     * @param int|int[]|null $mailingListId
     *
     * @return ContactActivityModel[]
     */
    public function getContactMailingListActivity(int $contactId, int $limit = null, $mailingListId = null): array
    {
        $conditions = ['contactId' => $contactId];

        if ($mailingListId !== null) {
            $conditions['mailingListId'] = $mailingListId;
        }

        // Get mailing lists
        $contactMailingListRecords = ContactMailingListRecord::find()
            ->where($conditions)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        $contactMailingListModels = ContactMailingListModel::populateModels($contactMailingListRecords, false);

        // Return contact activity
        return $this->_getActivity($contactMailingListModels, null, $limit);
    }

    /**
     * Returns mailing lists report data
     *
     * @param int|null $siteId
     *
     * @return array
     */
    public function getMailingListsReportData(int $siteId = null): array
    {
        // Get all mailing lists in all sites
        $data['mailingLists'] = MailingListElement::find()
            ->siteId($siteId)
            ->all();

        // Get data
        $data['subscribed'] = 0;
        $data['unsubscribed'] = 0;
        $data['complained'] = 0;
        $data['bounced'] = 0;

        /** @var MailingListElement $mailingList */
        foreach ($data['mailingLists'] as $mailingList) {
            $data['subscribed'] += $mailingList->getSubscribedCount();
            $data['unsubscribed'] += $mailingList->getUnsubscribedCount();
            $data['complained'] += $mailingList->getComplainedCount();
            $data['bounced'] += $mailingList->getBouncedCount();
        }

        return $data;
    }

    /**
     * Returns mailing list report data
     *
     * @param int $mailingListId
     *
     * @return array
     */
    public function getMailingListReportData(int $mailingListId): array
    {
        // Get mailing list
        $data['mailingList'] = Campaign::$plugin->mailingLists->getMailingListById($mailingListId);

        // Get sendouts
        $data['sendouts'] = SendoutElement::find()
            ->mailingListId($mailingListId)
            ->orderBy(['sendDate' => SORT_ASC])
            ->all();

        // Get first contact mailing list
        $contactMailingListRecord = ContactMailingListRecord::find()
            ->where(['mailingListId' => $mailingListId])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->one();

        // Check if chart exists
        $data['hasChart'] = ($contactMailingListRecord !== null);

        return $data;
    }

    /**
     * Returns mailing list chart data
     *
     * @param int $mailingListId
     * @param string $interval
     *
     * @return array
     */
    public function getMailingListChartData(int $mailingListId, string $interval = 'days'): array
    {
        return $this->_getChartData(
            ContactMailingListRecord::class,
            ['mailingListId' => $mailingListId],
            ContactMailingListModel::INTERACTIONS,
            $interval
        );
    }

    /**
     * Returns mailing list contact activity
     *
     * @param int $mailingListId
     * @param string|null $interaction
     * @param int|null $limit
     *
     * @return ContactActivityModel[]
     */
    public function getMailingListContactActivity(int $mailingListId, string $interaction = null, int $limit = null): array
    {
        // If no interaction was specified then set check for any interaction that is not null
        $interactionCondition = $interaction ? [$interaction => null] : [
            'or',
            [
                'subscribed' => null,
                'unsubscribed' => null,
                'complained' => null,
                'bounced' => null,
            ]
        ];

        $contactMailingListRecords = ContactMailingListRecord::find()
            ->where(['mailingListId' => $mailingListId])
            ->andWhere(['not', $interactionCondition])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        $contactMailingListModels = ContactMailingListModel::populateModels($contactMailingListRecords, false);

        // Return contact activity
        return $this->_getActivity($contactMailingListModels, $interaction, $limit);
    }

    /**
     * Returns mailing list locations
     *
     * @param int $mailingListId
     * @param int|null $limit
     *
     * @return array
     */
    public function getMailingListLocations(int $mailingListId, int $limit = null): array
    {
        // Get mailing list
        $mailingList = Campaign::$plugin->mailingLists->getMailingListById($mailingListId);

        if ($mailingList === null) {
            return [];
        }

        // Return locations of contact mailing lists
        return $this->_getLocations(ContactMailingListRecord::class, ['and', ['mailingListId' => $mailingListId], ['not', ['subscribed' => null]]], $mailingList->getSubscribedCount(), $limit);
    }

    /**
     * Returns mailing list devices
     *
     * @param int $mailingListId
     * @param bool $detailed
     * @param int|null $limit
     *
     * @return array
     */
    public function getMailingListDevices(int $mailingListId, bool $detailed = false, int $limit = null): array
    {
        // Get mailing list
        $mailingList = Campaign::$plugin->mailingLists->getMailingListById($mailingListId);

        if ($mailingList === null) {
            return [];
        }

        // Return device, os and client of contact mailing lists
        return $this->_getDevices(ContactMailingListRecord::class, ['and', ['mailingListId' => $mailingListId], ['not', ['subscribed' => null]]], $detailed, $mailingList->getSubscribedCount(), $limit);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns chart data
     *
     * @param string $recordClass
     * @param array $condition
     * @param array $interactions
     * @param string $interval
     *
     * @return array
     */
    private function _getChartData(string $recordClass, array $condition, array $interactions, string $interval): array
    {
        $data = [];

        // Get date time format ensuring interval is valid
        $format = $this->_getDateTimeFormat($interval);

        if ($format === null) {
            return [];
        }

        // Get first record
        /** @var ActiveRecord $recordClass */
        $record = $recordClass::find()
            ->where($condition)
            ->orderBy(['dateCreated' => SORT_ASC])
            ->one();

        if ($record === null) {
            return [];
        }

        /** @var ActiveRecord $record */
        // Get start and end date times
        $startDateTime = DateTimeHelper::toDateTime($record->dateCreated)->modify('-1 '.$interval);
        $endDateTime = clone $startDateTime;
        $endDateTime->modify('+'.$this->getMaxIntervals($interval).' '.$interval);

        $fields = [];

        /** @var ActiveRecord $recordClass */
        foreach ($record->fields() as $field) {
            $fields[] = 'MIN([['.$field.']]) AS '.$field;
        }

        // Get records within date range
        $records = $recordClass::find()
            ->select(array_merge(['contactId'], $fields))
            ->where($condition)
            ->andWhere(Db::parseDateParam('dateCreated', $endDateTime, '<'))
            ->orderBy(['dateCreated' => SORT_ASC])
            ->groupBy('contactId')
            ->all();

        // Get activity
        $activity = [];

        /** @var DateTime|null $lastInteraction */
        $lastInteraction = null;

        foreach ($records as $record) {
            foreach ($interactions as $interaction) {
                // If the interaction exists for the record
                if ($record->{$interaction}) {
                    // Convert interaction to datetime
                    $interactionDateTime = DateTimeHelper::toDateTime($record->{$interaction});

                    // If interaction datetime is before the specified end time
                    if ($interactionDateTime < $endDateTime) {
                        // Update last interaction if null or if interaction dateTime is greater than it
                        if ($lastInteraction === null || $interactionDateTime > $lastInteraction) {
                            $lastInteraction = $interactionDateTime;
                        }

                        // Get interaction dateTime as timestamp in the correct format
                        $index = DateTimeHelper::toDateTime($interactionDateTime->format($format))->getTimestamp();

                        $activity[$interaction][$index] = isset($activity[$interaction][$index]) ? $activity[$interaction][$index] + 1 : 1;
                    }
                }
            }
        }

        // Set data
        $data['startDateTime'] = $startDateTime;
        $data['interval'] = $interval;
        $data['format'] = $format;
        $data['interactions'] = $interactions;
        $data['activity'] = $activity;
        $data['lastInteraction'] = $lastInteraction;

        return $data;
    }

    /**
     * Returns activity
     *
     * @param ContactCampaignModel[]|ContactMailingListModel[] $models
     * @param string|null $interaction
     * @param int|null $limit
     *
     * @return ContactActivityModel[]
     */
    private function _getActivity(array $models, string $interaction = null, int $limit = null): array
    {
        $activity = [];

        foreach ($models as $model) {
            /** @var ContactCampaignModel|ContactMailingListModel $model */
            $interactionTypes = ($interaction !== null && in_array($interaction, $model::INTERACTIONS)) ? [$interaction] : $model::INTERACTIONS;

            foreach ($interactionTypes as $key => $interactionType) {
                if ($model->{$interactionType} !== null) {
                    $contactActivityModel = new ContactActivityModel([
                        'model' => $model,
                        'interaction' => $interactionType,
                        'date' => $model->{$interactionType},
                        'links' => $interactionType == 'clicked' ? $model->getLinks() : [],
                        'count' => 1,
                    ]);

                    if ($interactionType == 'opened') {
                        $contactActivityModel->count = $model->opens;
                    }
                    elseif ($interactionType == 'clicked') {
                        $contactActivityModel->count = $model->clicks;
                    }

                    if (!empty($model->sourceType)) {
                        switch ($model->sourceType) {
                            case 'import':
                                $contactActivityModel->sourceUrl = UrlHelper::cpUrl('campaign/contacts/import/'.$model->source);
                                break;
                            case 'user':
                                $path = (Craft::$app->getEdition() === Craft::Pro && $model->source) ? 'users/'.$model->source : 'myaccount';
                                $contactActivityModel->sourceUrl = UrlHelper::cpUrl($path);
                                break;
                            default:
                                $contactActivityModel->sourceUrl = $model->source;
                        }
                    }

                    $activity[$contactActivityModel->date->getTimestamp().'-'.$key.'-'.$interactionType.'-'.$model->contactId] = $contactActivityModel;
                }
            }
        }

        // Sort by key in reverse order
        krsort($activity);

        // Enforce the limit
        if ($limit !== null) {
            $activity = array_slice($activity, 0, $limit);
        }

        return $activity;
    }

    /**
     * Returns locations
     *
     * @param string $recordClass
     * @param array $conditions
     * @param int $total
     * @param int|null $limit
     *
     * @return array
     */
    private function _getLocations(string $recordClass, array $conditions, int $total, int $limit = null): array
    {
        $results = [];
        $fields = ['country', 'MAX([[geoIp]]) AS geoIp'];

        /** @var ActiveRecord $recordClass */
        $query = ContactRecord::find()
            ->select(array_merge($fields, ['COUNT(*) AS count']))
            ->groupBy('country');

        if ($recordClass != ContactRecord::class) {
            $contactIds = $recordClass::find()
                ->select('contactId')
                ->where($conditions)
                ->groupBy('contactId')
                ->column();

            $query->andWhere([ContactRecord::tableName().'.id' => $contactIds]);
        }

        $records = $query->all();

        // Set default unknown count
        $unknownCount = 0;

        foreach ($records as $record) {
            // Increment unknown results
            if (empty($record->country)) {
                $unknownCount++;
                continue;
            }

            $result = $record->toArray();
            $result['count'] = $record->count;

            // Decode GeoIp
            $geoIp = $record->geoIp ? Json::decodeIfJson($record->geoIp) : [];

            $result['countryCode'] = strtolower($geoIp['countryCode'] ?? '');
            $result['countRate'] = $total ? NumberHelper::floorOrOne($record->count / $total * 100) : 0;
            $results[] = $result;
        }

        // If there is an unknown count then add it to results
        if ($unknownCount > 0) {
            $results[] = [
                'country' => '',
                'countryCode' => '',
                'count' => $unknownCount,
                'countRate' => $total ? NumberHelper::floorOrOne($unknownCount / $total * 100) : 0,
            ];
        }

        // Sort results
        usort($results, [$this, '_compareCount']);

        // Enforce the limit
        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Returns devices
     *
     * @param string $recordClass
     * @param array $conditions
     * @param bool $detailed
     * @param int $total
     * @param int|null $limit
     *
     * @return array
     */
    private function _getDevices(string $recordClass, array $conditions, bool $detailed, int $total, int $limit = null): array
    {
        $results = [];
        $fields = $detailed ? ['device', 'os', 'client'] : ['device'];

        /** @var ActiveRecord $recordClass */
        $query = ContactRecord::find()
            ->select(array_merge($fields, ['COUNT(*) AS count']))
            ->where(['not', ['device' => null]])
            ->groupBy($fields);

        if ($recordClass != ContactRecord::class) {
            $contactIds = $recordClass::find()
                ->select('contactId')
                ->where($conditions)
                ->groupBy('contactId')
                ->column();

            $query->andWhere([ContactRecord::tableName().'.id' => $contactIds]);
        }

        $records = $query->all();

        foreach ($records as $record) {
            $result = $record->toArray();
            $result['count'] = $record->count;
            $result['countRate'] = $total ? NumberHelper::floorOrOne($record->count / $total * 100) : 0;
            $results[] = $result;
        }

        // Sort results
        usort($results, [$this, '_compareCount']);

        // Enforce the limit
        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Returns date time format
     *
     * @param string $interval
     *
     * @return string|null
     */
    private function _getDateTimeFormat(string $interval)
    {
        $formats = [
            'minutes' => str_replace(':s', '', DATE_ATOM),
            'hours' => str_replace(['i', ':s'], ['00', ''], DATE_ATOM),
            'days' => 'Y-m-d',
            'months' => 'Y-m',
            'years' => 'Y',
        ];

        return $formats[$interval] ?? null;
    }

    /**
     * Compares two count values by count descending
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private function _compareCount(array $a, array $b): int
    {
        return (int)$a['count'] < (int)$b['count'] ? 1 : -1;
    }
}
