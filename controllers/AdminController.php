<?php

namespace app\modules\sermonaudio\controllers;

use app\modules\sermonaudio\models\ApiSettingsForm;
use humhub\modules\admin\components\Controller;
use Yii;

class AdminController extends Controller
{
    public function actionIndex()
    {
        /** @var \app\modules\sermonaudio\Module $module */
        $module = Yii::$app->getModule('sermonaudio');
        $model = new ApiSettingsForm();

        $model->apiEnabled = $module->getApiEnabled();
        $model->hasApiKey = $module->hasApiKey();
        $model->debugMode = $module->isDebugModeEnabled();
        $model->softDeleteRetentionDays = (int) $module->settings->get('softDeleteRetentionDays', 30);

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if (!empty($model->apiKey)) {
                $module->setApiKey($model->apiKey);
                $model->hasApiKey = true;
            }

            $module->setApiEnabled($model->apiEnabled && $model->hasApiKey);
            $module->setDebugMode($model->debugMode);
            $module->settings->set('softDeleteRetentionDays', $model->softDeleteRetentionDays);

            Yii::$app->session->setFlash('success', 'Settings saved.');
            return $this->redirect(['/sermonaudio/admin']);
        }

        return $this->render('index', [
            'model' => $model,
        ]);
    }
}