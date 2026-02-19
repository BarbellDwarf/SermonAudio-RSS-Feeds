<?php

use yii\bootstrap\ActiveForm;
use yii\helpers\Html;
use app\modules\sermonaudio\models\Feed;
use app\modules\sermonaudio\assets\ConfigAsset;
use humhub\modules\space\models\Membership;

/** @var $model \app\modules\sermonaudio\models\Feed */
/** @var $space \humhub\modules\space\models\Space */

ConfigAsset::register($this);
$this->registerJs("document.body.classList.add('sermonaudio-config-active');");

// Get space members for dropdown
$spaceMembers = [];
$memberships = Membership::find()->where(['space_id' => $space->id])->with('user')->all();
foreach ($memberships as $membership) {
    if ($membership->user) {
        $spaceMembers[$membership->user->id] = $membership->user->displayName;
    }
}

?>

<div class="sermonaudio-config">
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong><?= $model->isNewRecord ? 'Add Feed' : 'Edit Feed' ?></strong>
            <div class="pull-right">
                <?= Html::a('<i class="fa fa-arrow-left"></i> Back',
                    ['index', 'container' => $space],
                    ['class' => 'btn btn-sm btn-default']) ?>
            </div>
            <div class="clearfix"></div>
        </div>
        <div class="panel-body">
            <?php $form = ActiveForm::begin(); ?>
            <?= $form->field($model, 'feed_url')->textInput([
                'placeholder' => 'https://feed.sermonaudio.com/broadcasters...'
            ])->label('RSS Feed URL') ?>

            <?= $form->field($model, 'feed_type')->dropDownList([
                'audio' => 'Audio',
                'video' => 'Video',
            ])->label('Feed Type') ?>

            <?= $form->field($model, 'cron_schedule')->dropDownList(
                Feed::getCronScheduleOptions()
            )->label('Check Schedule')->hint('How often should we check for new sermons?') ?>

            <?php if (!empty($spaceMembers)): ?>
                <?= $form->field($model, 'post_as_user_id')->dropDownList(
                    $spaceMembers,
                    ['prompt' => '-- Use Space Admin --']
                )->label('Post As User')->hint('Select which user will create the posts. Leave empty to use space admin.') ?>
            <?php endif; ?>

            <?= $form->field($model, 'post_template')->textarea([
                'rows' => 4,
                'placeholder' => $model->getDefaultTemplate()
            ])->label('Post Template (Optional)')->hint('
                Available variables:<br>
                <code>{channel}</code> - Channel name<br>
                <code>{type}</code> - "video" or "sermon"<br>
                <code>{title}</code> - Sermon title<br>
                <code>{title_link}</code> - Sermon title linked to the sermon<br>
                <code>{speaker}</code> - Speaker name (line removed if empty)<br>
                <code>{series}</code> - Series name (line removed if empty)<br>
                <code>{link}</code> - Sermon URL<br><br>
                Example: <code>{channel} has published a new {type} in {series}: {title}</code>
            ') ?>

            <?= $form->field($model, 'enabled')->checkbox()->label('Enabled') ?>

            <div class="help-block">
                <strong>Feed URL Examples:</strong><br>
                Audio: <code>https://feed.sermonaudio.com/broadcasters/examplebroadcaster</code><br>
                Video: <code>https://feed.sermonaudio.com/broadcasters/examplebroadcaster?video=true</code>
            </div>

            <div class="form-group">
                <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
                <?= Html::a('Cancel', ['index', 'container' => $space], ['class' => 'btn btn-default']) ?>
            </div>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
