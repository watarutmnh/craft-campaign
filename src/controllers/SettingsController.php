<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\campaign\controllers;

use craft\elements\User;
use putyourlightson\campaign\Campaign;
use putyourlightson\campaign\helpers\SendoutHelper;
use putyourlightson\campaign\models\SettingsModel;
use putyourlightson\campaign\elements\ContactElement;

use Craft;
use craft\web\Controller;
use craft\helpers\ArrayHelper;
use craft\helpers\MailerHelper;
use craft\errors\MissingComponentException;
use craft\mail\transportadapters\BaseTransportAdapter;
use craft\mail\transportadapters\Sendmail;
use craft\mail\transportadapters\TransportAdapterInterface;
use Throwable;
use yii\base\Exception;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * SettingsController
 *
 * @author    PutYourLightsOn
 * @package   Campaign
 * @since     1.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('campaign', 'Administrative changes are disallowed in this environment.'));
        }

        // Require permission
        $this->requirePermission('campaign:settings');

        return parent::beforeAction($action);
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditGeneral(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        return $this->renderTemplate('campaign/settings/general', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
            'phpBinPath' => PHP_BINARY ?: '/usr/bin/php',
            'isDynamicWebAliasUsed' => Campaign::$plugin->settings->isDynamicWebAliasUsed(),
        ]);
    }

    /**
     * @param SettingsModel|null             $settings The settings being edited, if there were any validation errors.
     * @param TransportAdapterInterface|null $adapter  The transport adapter, if there were any validation errors.
     *
     * @return Response
     * @throws MissingComponentException
     */
    public function actionEditEmail(SettingsModel $settings = null, TransportAdapterInterface $adapter = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        if ($adapter === null) {
            $settings->transportType = $settings->transportType ?: Sendmail::class;
            try {
                $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);
            }
            catch (MissingComponentException $e) {
                $adapter = new Sendmail();
                $adapter->addError('type', Craft::t('app', 'The transport type “{type}” could not be found.', [
                    'type' => $settings->transportType
                ]));
            }
        }

        // Get all the registered transport adapter types
        $allTransportAdapterTypes = MailerHelper::allMailerTransportTypes();

        // Make sure the selected adapter class is in there
        if (!in_array(get_class($adapter), $allTransportAdapterTypes)) {
            $allTransportAdapterTypes[] = get_class($adapter);
        }

        $allTransportAdapters = [];
        $transportTypeOptions = [];

        foreach ($allTransportAdapterTypes as $transportAdapterType) {
            /** @var string|TransportAdapterInterface $transportAdapterType */
            if ($transportAdapterType === get_class($adapter) || $transportAdapterType::isSelectable()) {
                $allTransportAdapters[] = MailerHelper::createTransportAdapter($transportAdapterType);
                $transportTypeOptions[] = [
                    'value' => $transportAdapterType,
                    'label' => $transportAdapterType::displayName()
                ];
            }
        }

        // Sort them by name
        ArrayHelper::multisort($transportTypeOptions, 'label');

        return $this->renderTemplate('campaign/settings/email', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
            'siteOptions' => Campaign::$plugin->settings->getSiteOptions(),
            'adapter' => $adapter,
            'allTransportAdapters' => $allTransportAdapters,
            'transportTypeOptions' => $transportTypeOptions,
        ]);
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditContact(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        return $this->renderTemplate('campaign/settings/contact', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
        ]);
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditSendout(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        return $this->renderTemplate('campaign/settings/sendout', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
            'system' => [
                'memoryLimit' => ini_get('memory_limit'),
                'memoryLimitExceeded' => (SendoutHelper::memoryInBytes($settings->memoryLimit) > SendoutHelper::memoryInBytes(ini_get('memory_limit'))),
                'timeLimit' => ini_get('max_execution_time'),
            ],
        ]);
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditGeoip(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        return $this->renderTemplate('campaign/settings/geoip', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
        ]);
    }

    /**
     * @param SettingsModel $settings The settings being edited, if there were any validation errors.
     *
     * @return Response
     */
    public function actionEditRecaptcha(SettingsModel $settings = null): Response
    {
        if ($settings === null) {
            $settings = Campaign::$plugin->getSettings();
        }

        return $this->renderTemplate('campaign/settings/recaptcha', [
            'settings' => $settings,
            'config' => Craft::$app->getConfig()->getConfigFromFile('campaign'),
        ]);
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveGeneral()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->testMode = Craft::$app->getRequest()->getBodyParam('testMode', $settings->testMode);
        $settings->apiKey = Craft::$app->getRequest()->getBodyParam('apiKey', $settings->apiKey);
        $settings->mailgunWebhookSigningKey = Craft::$app->getRequest()->getBodyParam('mailgunWebhookSigningKey', $settings->mailgunWebhookSigningKey);

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save general settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'General settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     */
    public function actionSaveEmail()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->fromNamesEmails = Craft::$app->getRequest()->getBodyParam('fromNamesEmails', $settings->fromNamesEmails);
        $settings->transportType = Craft::$app->getRequest()->getBodyParam('transportType', $settings->transportType);
        $settings->transportSettings = Craft::$app->getRequest()->getBodyParam('transportTypes.'.$settings->transportType);

        // Create the transport adapter so that we can validate it
        /** @var BaseTransportAdapter $adapter */
        $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);

        // Validate settings and transport adapter
        $settings->validate();
        $adapter->validate();

        if ($settings->hasErrors() || $adapter->hasErrors()) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save email settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'adapter' => $adapter
            ]);

            return null;
        }

        // Save it
        Campaign::$plugin->settings->saveSettings($settings);
        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Email settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws Exception
     */
    public function actionSaveContact()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->emailFieldLabel = Craft::$app->getRequest()->getBodyParam('emailFieldLabel', $settings->emailFieldLabel);

        // Set the field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = ContactElement::class;

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)
            || !Campaign::$plugin->settings->saveContactFieldLayout($fieldLayout)
        ) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save contact settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Contact settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveSendout()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->maxBatchSize = Craft::$app->getRequest()->getBodyParam('maxBatchSize', $settings->maxBatchSize);
        $settings->memoryLimit = Craft::$app->getRequest()->getBodyParam('memoryLimit', $settings->memoryLimit);
        $settings->timeLimit = Craft::$app->getRequest()->getBodyParam('timeLimit', $settings->timeLimit);

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save sendout settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'Sendout settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveGeoip()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->geoIp = Craft::$app->getRequest()->getBodyParam('geoIp', $settings->geoIp);
        $settings->ipstackApiKey = Craft::$app->getRequest()->getBodyParam('ipstackApiKey', $settings->ipstackApiKey);

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save GeoIP settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'GeoIP settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     */
    public function actionSaveRecaptcha()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->reCaptcha = Craft::$app->getRequest()->getBodyParam('reCaptcha', $settings->reCaptcha);
        $settings->reCaptchaVersion = Craft::$app->getRequest()->getBodyParam('reCaptchaVersion', $settings->reCaptchaVersion);
        $settings->reCaptchaSiteKey = Craft::$app->getRequest()->getBodyParam('reCaptchaSiteKey', $settings->reCaptchaSiteKey);
        $settings->reCaptchaSecretKey = Craft::$app->getRequest()->getBodyParam('reCaptchaSecretKey', $settings->reCaptchaSecretKey);
        $settings->reCaptchaErrorMessage = Craft::$app->getRequest()->getBodyParam('reCaptchaErrorMessage', $settings->reCaptchaErrorMessage);
        $settings->reCaptchaSize = Craft::$app->getRequest()->getBodyParam('reCaptchaSize', $settings->reCaptchaSize);
        $settings->reCaptchaTheme = Craft::$app->getRequest()->getBodyParam('reCaptchaTheme', $settings->reCaptchaTheme);
        $settings->reCaptchaBadge = Craft::$app->getRequest()->getBodyParam('reCaptchaBadge', $settings->reCaptchaBadge);

        // Save it
        if (!Campaign::$plugin->settings->saveSettings($settings)) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t save reCAPTCHA settings.'));

            // Send the settings back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('campaign', 'reCAPTCHA settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @throws MissingComponentException
     * @throws BadRequestHttpException
     */
    public function actionSendTestEmail()
    {
        $this->requirePostRequest();

        $settings = Campaign::$plugin->getSettings();

        // Set the simple stuff
        $settings->fromNamesEmails = Craft::$app->getRequest()->getBodyParam('fromNamesEmails', $settings->fromNamesEmails);
        $settings->transportType = Craft::$app->getRequest()->getBodyParam('transportType', $settings->transportType);
        $settings->transportSettings = Craft::$app->getRequest()->getBodyParam('transportTypes.'.$settings->transportType);

        // Create the transport adapter so that we can validate it
        /** @var BaseTransportAdapter $adapter */
        $adapter = MailerHelper::createTransportAdapter($settings->transportType, $settings->transportSettings);

        // Validate settings and transport adapter
        $settings->validate();
        $adapter->validate();

        if ($settings->hasErrors() || $adapter->hasErrors()) {
            Craft::$app->getSession()->setError(Craft::t('campaign', 'Couldn’t send test email.'));
        }
        else {
            // Create mailer with settings
            $mailer = Campaign::$plugin->createMailer($settings);

            // Get from name and email
            $fromNameEmail = Campaign::$plugin->settings->getFromNameEmail();

            $subject = Craft::t('campaign', 'This is a test email from Craft Campaign');
            $body = Craft::t('campaign', 'Congratulations! Craft Campaign was successfully able to send an email.');

            /** @var User $user */
            $user = Craft::$app->getUser()->getIdentity();

            $message = $mailer->compose()
                ->setFrom([$fromNameEmail['email'] => $fromNameEmail['name']])
                ->setTo($user->email)
                ->setSubject($subject)
                ->setHtmlBody($body)
                ->setTextBody($body);

            if ($fromNameEmail['replyTo']) {
                $message->setReplyTo($fromNameEmail['replyTo']);
            }

            // Send message
            try {
                $response = $message->send();
            }
            catch (Throwable $e) {
                Craft::error($e);
                Craft::$app->getErrorHandler()->logException($e);
                $response = false;
            }

            if ($response) {
                Craft::$app->getSession()->setNotice(Craft::t('app', 'Email sent successfully! Check your inbox.'));
            }
            else {
                Craft::$app->getSession()->setError(Craft::t('app', 'There was an error testing your email settings.'));
            }
        }

        // Send the settings back to the template
        Craft::$app->getUrlManager()->setRouteParams([
            'settings' => $settings,
            'adapter' => $adapter
        ]);
    }
}
