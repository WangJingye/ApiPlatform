<?php
$config = [
    'LOAD_EXT_CONFIG' => 'rewrite,db',
    'MODULE_ALLOW_LIST' => ['Home'],
    'MODULE_DENY_LIST' => ['Common'],
    'DEFAULT_MODULE' => 'Home',
    'DEFAULT_CONTROLLER' => 'Upload', // 默认控制器名称
    'DEFAULT_ACTION' => 'index', // 默认操作名称
    'LAYOUT_ON' => true,
    'LAYOUT_NAME' => 'Layout/layout',
    'TMPL_TEMPLATE_SUFFIX' => '.phtml',
    'URL_MODEL' => 2,
];

$modConfig = $config['MODULE_ALLOW_LIST'];
$extConfig = [];
foreach ($modConfig as $item) {
    $itemConfig = require('./Application/' . $item . '/Conf/config.php');
    if (!empty($itemConfig)) {
        $extConfig = array_merge($extConfig, $itemConfig);
    }
}

return array_merge($config, $extConfig);