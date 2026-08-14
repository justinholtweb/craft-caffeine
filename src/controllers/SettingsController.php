<?php

namespace justinholtweb\caffeine\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\caffeine\models\Settings;
use justinholtweb\caffeine\Plugin;
use Psr\Log\LogLevel;
use yii\web\Response;

/**
 * Plugin settings.
 *
 * Its own screen rather than the generic plugin settings modal, because the CP nav links here
 * and because these settings are operational — where artifacts go, how many are kept — rather
 * than the kind of thing you set once during install.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Settings are stored in project config, so this is an admin-only screen in the same way
        // every other project-config screen is.
        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $suggestions = [];

        foreach (Craft::$app->getFs()->getAllFilesystems() as $fs) {
            $suggestions[] = ['name' => $fs->handle, 'hint' => $fs->name];
        }

        return $this->renderTemplate('caffeine/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'filesystemSuggestions' => $suggestions === [] ? [] : [[
                'label' => Craft::t('caffeine', 'Filesystems'),
                'data' => $suggestions,
            ]],
            'logLevelOptions' => array_map(
                fn(string $level) => ['label' => ucfirst($level), 'value' => $level],
                [
                    LogLevel::DEBUG,
                    LogLevel::INFO,
                    LogLevel::NOTICE,
                    LogLevel::WARNING,
                    LogLevel::ERROR,
                    LogLevel::CRITICAL,
                ],
            ),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $settings->setAttributes(Craft::$app->getRequest()->getBodyParam('settings', []), false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(Craft::t('caffeine', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('caffeine', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
