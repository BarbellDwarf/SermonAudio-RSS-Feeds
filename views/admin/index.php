<?php

use yii\bootstrap\ActiveForm;
use yii\helpers\Html;

/** @var $model \app\modules\sermonaudio\models\ApiSettingsForm */

?>

<div class="panel panel-default">
    <div class="panel-heading">
        <strong>SermonAudio Settings</strong>
    </div>
    <div class="panel-body">
        <?php $form = ActiveForm::begin(); ?>

        <?= $form->field($model, 'apiKey')->passwordInput([
            'autocomplete' => 'new-password',
            'placeholder' => $model->hasApiKey ? '******** (leave blank to keep existing)' : 'Enter API key'
        ])->label('SermonAudio API Key')->hint('Required to enable API-based features. This value is encrypted and never displayed after saving.') ?>

        <?php if ($model->hasApiKey): ?>
            <hr>
            <h4>API Features</h4>
            <?= $form->field($model, 'apiEnabled')->checkbox()->label('Enable API features') ?>
        <?php else: ?>
            <div class="alert alert-info">
                Enter an API key to enable optional API features.
            </div>
        <?php endif; ?>

        <hr>
        <h4>Debug Mode</h4>
        <?= $form->field($model, 'debugMode')->checkbox()->label('Enable debug mode (testing only)')->hint('When enabled, feeds will check for new sermons every 15 minutes instead of following the configured schedule. <strong>Do not enable in production.</strong>') ?>

        <hr>
        <h4>Cleanup Settings</h4>
        <?= $form->field($model, 'softDeleteRetentionDays')->textInput(['type' => 'number', 'min' => 1, 'max' => 365])->label('Soft-Deleted Post Retention Days')->hint('Posts deleted via the web interface are soft-deleted (kept in database but hidden). This setting controls how many days to retain soft-deleted sermon posts before they are permanently removed. Default: 30 days.') ?>

        <div class="form-group">
            <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
        </div>

        <hr>
        <h4>Debugging</h4>
        <p class="text-muted">When API features are enabled, detailed logs are written to:<br>
        <code>/var/www/humhub/protected/runtime/logs/app.log</code></p>
        <p class="text-muted">Look for messages tagged with <code>sermonaudio</code> to troubleshoot API issues.</p>

        <?php ActiveForm::end(); ?>
    </div>
</div>