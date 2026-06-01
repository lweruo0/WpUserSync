<?php

declare(strict_types=1);

namespace WpUserSync\classes\Presenter;

use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use Smarty\Smarty;
use WpUserSync\classes\WpUserSync;

/**
 * Preferences presenter for the plugin manager.
 */
class WpUserSyncPreferencesPresenter
{
    public static function createWpUserSyncForm(Smarty $smarty): string
    {
        global $gL10n, $gCurrentSession;

        $plugin = WpUserSync::getInstance();
        $formValues = $plugin::getPluginConfig();

        $form = new FormPresenter(
            'adm_preferences_form_wp_user_sync',
            $plugin::getPluginPath() . '/templates/preferences.plugin.wp-user-sync.tpl',
            SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/preferences.php', array(
                'mode' => 'save',
                'panel' => 'wp_user_sync'
            )),
            null,
            array('class' => 'form-preferences')
        );

        $form->addCheckbox(
            'wp_user_sync_enabled',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_enabled']['name']),
            (bool) $formValues['wp_user_sync_enabled']['value'],
            array('helpTextId' => $formValues['wp_user_sync_enabled']['description'])
        );

        $form->addCheckbox(
            'wp_user_sync_require_https',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_require_https']['name']),
            (bool) $formValues['wp_user_sync_require_https']['value'],
            array('helpTextId' => $formValues['wp_user_sync_require_https']['description'])
        );

        $form->addCheckbox(
            'wp_user_sync_update_existing_by_email',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_update_existing_by_email']['name']),
            (bool) $formValues['wp_user_sync_update_existing_by_email']['value'],
            array('helpTextId' => $formValues['wp_user_sync_update_existing_by_email']['description'])
        );

        $form->addCheckbox(
            'wp_user_sync_assign_default_roles',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_assign_default_roles']['name']),
            (bool) $formValues['wp_user_sync_assign_default_roles']['value'],
            array('helpTextId' => $formValues['wp_user_sync_assign_default_roles']['description'])
        );

        $form->addInput(
            'wp_user_sync_external_id_field',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_external_id_field']['name']),
            $formValues['wp_user_sync_external_id_field']['value'],
            array('maxLength' => 50, 'helpTextId' => $formValues['wp_user_sync_external_id_field']['description'])
        );

        $form->addInput(
            'wp_user_sync_default_role',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_default_role']['name']),
            $formValues['wp_user_sync_default_role']['value'],
            array('maxLength' => 100, 'helpTextId' => $formValues['wp_user_sync_default_role']['description'])
        );

        $form->addMultilineTextInput(
            'wp_user_sync_role_map_json',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_role_map_json']['name']),
            $formValues['wp_user_sync_role_map_json']['value'],
            6,
            array('helpTextId' => $formValues['wp_user_sync_role_map_json']['description'])
        );

        $form->addInput(
            'wp_user_sync_allowed_ips',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_allowed_ips']['name']),
            $formValues['wp_user_sync_allowed_ips']['value'],
            array('maxLength' => 1000, 'helpTextId' => $formValues['wp_user_sync_allowed_ips']['description'])
        );

        $form->addInput(
            'wp_user_sync_api_token_hash',
            Language::translateIfTranslationStrId($formValues['wp_user_sync_api_token_hash']['name']),
            $formValues['wp_user_sync_api_token_hash']['value'],
            array('maxLength' => 128, 'helpTextId' => $formValues['wp_user_sync_api_token_hash']['description'])
        );

        $form->addSubmitButton(
            'adm_button_save_wp_user_sync',
            $gL10n->get('SYS_SAVE'),
            array('icon' => 'bi-check-lg', 'class' => 'offset-sm-3')
        );

        $form->addToSmarty($smarty);
        $gCurrentSession->addFormObject($form);

        return $smarty->fetch($plugin::getPluginPath() . '/templates/preferences.plugin.wp-user-sync.tpl');
    }
}
