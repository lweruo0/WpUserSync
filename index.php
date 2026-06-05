<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap-admidio.php';
require_once __DIR__ . '/bootstrap-plugin.php';
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;

use WpUserSync\classes\WpUserSyncPlugin;

try {


    $plugin = new WpUserSyncPlugin(__DIR__);
    
    if (!$gValidLogin || !$gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    //$plugin->render();

    $headline = 'Wordpress User Sync';
    $pluginUrl = ADMIDIO_URL . FOLDER_PLUGINS . '/wpusersync/index.php';
    $gNavigation->addStartUrl($pluginUrl, $headline, 'bi-cloud');
    
    $pagePresenter = PagePresenter::withHtmlIDAndHeadline('wpusersync', $headline);
    
    // Top action buttons in the canonical Admidio page-function menu.
    $pagePresenter->addPageFunctionsMenuItem('menu_item_api', 'Update API', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'WpUserSync')), 'github');
    
    ob_start();
    
    $pagePresenter->addHtml('TEST');
    $pagePresenter->show();

} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo $e->getMessage();
}



