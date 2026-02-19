<?php

namespace app\modules\sermonaudio\controllers;

class ConfigContainerController extends ConfigController
{
    public function getViewPath()
    {
        return $this->module->getViewPath() . DIRECTORY_SEPARATOR . 'config';
    }
}
