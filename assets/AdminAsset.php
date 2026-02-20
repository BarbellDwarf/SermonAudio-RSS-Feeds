<?php

namespace app\modules\sermonaudio\assets;

use yii\web\AssetBundle;

class AdminAsset extends AssetBundle
{
    public $css = [
        'css/admin.css',
    ];

    public function init()
    {
        $this->sourcePath = dirname(__FILE__, 2) . '/resources';
        parent::init();
    }
}
