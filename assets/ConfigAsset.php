<?php

namespace app\modules\sermonaudio\assets;

use yii\web\AssetBundle;

class ConfigAsset extends AssetBundle
{
    public $css = [
        'css/config.css',
    ];

    public function init()
    {
        $this->sourcePath = dirname(__FILE__, 2) . '/resources';
        parent::init();
    }
}
