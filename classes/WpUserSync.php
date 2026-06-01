<?php

declare(strict_types=1);

namespace WpUserSync\classes;

use Admidio\Infrastructure\Plugins\PluginAbstract;
use Admidio\UI\Presenter\PagePresenter;
use Throwable;

/**
 * Basic plugin entry point for the WordPress -> Admidio user provisioning plugin.
 */
class WpUserSync extends PluginAbstract
{
    public static function doRender($page = null): bool
    {
        global $gL10n;

        try {
            $plugin = self::getInstance();
            $endpointUrl = self::$pluginPath . '/api/users.php';
            $tokenHint = $gL10n->get('PLG_WP_USER_SYNC_TOKEN_NOT_CONFIGURED');

            if ($page instanceof PagePresenter) {
                $page->assign('pluginHeadline', $gL10n->get('PLG_WP_USER_SYNC_PLUGIN_NAME'));
                $page->assign('pluginDescription', $gL10n->get('PLG_WP_USER_SYNC_PLUGIN_DESCRIPTION'));
                $page->assign('endpointUrl', $endpointUrl);
                $page->assign('tokenHint', $tokenHint);
                echo $page->fetch($plugin::getPluginPath() . '/templates/plugin.info.tpl');
            } else {
                echo '<div class="admidio-plugin-content">';
                echo '<h3>' . htmlspecialchars($gL10n->get('PLG_WP_USER_SYNC_PLUGIN_NAME')) . '</h3>';
                echo '<p>' . htmlspecialchars($gL10n->get('PLG_WP_USER_SYNC_PLUGIN_DESCRIPTION')) . '</p>';
                echo '<p><strong>Endpoint:</strong> <code>' . htmlspecialchars($endpointUrl) . '</code></p>';
                echo '<p>' . htmlspecialchars($tokenHint) . '</p>';
                echo '</div>';
            }
        } catch (Throwable $e) {
            echo $e->getMessage();
        }

        return true;
    }
}
