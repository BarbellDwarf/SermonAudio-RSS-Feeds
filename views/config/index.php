<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\modules\sermonaudio\assets\ConfigAsset;

/** @var $space \humhub\modules\space\models\Space */
/** @var $feeds \app\modules\sermonaudio\models\Feed[] */

ConfigAsset::register($this);
$this->registerJs("document.body.classList.add('sermonaudio-config-active');");

?>

<div class="sermonaudio-config">

<div class="panel panel-default">
    <div class="panel-heading">
        <strong>SermonAudio RSS Feeds</strong>
        <div class="pull-right">
            <?= Html::a('<i class="fa fa-plus"></i> Add Feed', 
                ['add', 'container' => $space], 
                ['class' => 'btn btn-sm btn-success', 'data-target' => '#globalModal']) ?>
        </div>
        <div class="clearfix"></div>
    </div>

    <div class="panel-body">
        <p class="help-block">
            Configure RSS feeds from SermonAudio to automatically post new sermons to this space.
            The system checks for new sermons every hour by default.
        </p>

        <?php if (empty($feeds)): ?>
            <div class="alert alert-info">
                No feeds configured yet. Click "Add Feed" to get started.
            </div>
        <?php else: ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Feed URL</th>
                        <th>Type</th>
                        <th>Schedule</th>
                        <th>Last Check</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feeds as $feed): ?>
                        <tr>
                            <td><?= Html::encode($feed->feed_url) ?></td>
                            <td><span class="label label-default"><?= ucfirst($feed->feed_type) ?></span></td>
                            <td>
                                <?php 
                                $schedules = \app\modules\sermonaudio\models\Feed::getCronScheduleOptions();
                                echo $schedules[$feed->cron_schedule] ?? 'Every hour';
                                ?>
                            </td>
                            <td><?= $feed->last_check ? Yii::$app->formatter->asDatetime($feed->last_check) : 'Never' ?></td>
                            <td>
                                <?php if ($feed->enabled): ?>
                                    <span class="label label-success">Enabled</span>
                                <?php else: ?>
                                    <span class="label label-danger">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= Html::a('<i class="fa fa-refresh"></i>', 
                                    ['check-now', 'id' => $feed->id, 'container' => $space], 
                                    [
                                        'class' => 'btn btn-sm btn-info', 
                                        'title' => 'Check Now',
                                        'data-method' => 'post',
                                        'data-confirm' => 'Check this feed now for new sermons?'
                                    ]) ?>
                                <?= Html::a('<i class="fa fa-power-off"></i>', 
                                    ['toggle', 'id' => $feed->id, 'container' => $space], 
                                    ['class' => 'btn btn-sm btn-default', 'title' => 'Toggle Status']) ?>
                                <?= Html::a('<i class="fa fa-pencil"></i>', 
                                    ['edit', 'id' => $feed->id, 'container' => $space], 
                                    ['class' => 'btn btn-sm btn-primary']) ?>
                                <?= Html::a('<i class="fa fa-trash"></i>', 
                                    ['delete', 'id' => $feed->id, 'container' => $space], 
                                    [
                                        'class' => 'btn btn-sm btn-danger',
                                        'data-method' => 'post',
                                        'data-confirm' => 'Are you sure you want to delete this feed?'
                                    ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><strong>Check Frequency Setup</strong></div>
    <div class="panel-body">
        <div class="alert alert-warning">
            <strong>Important:</strong> By default, feeds are checked once per hour via HumHub's built-in cron.
            If you selected "Every 15 minutes" or "Every 30 minutes", you need to add an additional cron job to your server.
        </div>
        
        <h4>For 15-minute checks, add this to your server crontab:</h4>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">*/15 * * * * /var/www/humhub/protected/modules/sermonaudio/check-sermons.sh >> /var/log/sermonaudio.log 2>&1</pre>
        
        <h4>For 30-minute checks:</h4>
        <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px;">*/30 * * * * /var/www/humhub/protected/modules/sermonaudio/check-sermons.sh >> /var/log/sermonaudio.log 2>&1</pre>
        
        <h4>How to set up (requires server SSH access):</h4>
        <ol>
            <li>SSH into your server</li>
            <li>Run: <code>sudo crontab -e</code></li>
            <li>Add one of the lines above based on your desired frequency</li>
            <li>Save and exit (in nano: Ctrl+X, then Y, then Enter)</li>
        </ol>
        
        <div class="alert alert-info">
            <strong>Note:</strong> This runs internally on your server and does not expose any public endpoints.
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading"><strong>How to Use</strong></div>
    <div class="panel-body">
        <h4>SermonAudio Feed URLs:</h4>
        <ul>
            <li><strong>Audio Feed:</strong> <code>https://feed.sermonaudio.com/broadcasters/{broadcaster_name}</code></li>
            <li><strong>Video Feed:</strong> <code>https://feed.sermonaudio.com/broadcasters/{broadcaster_name}?video=true</code></li>
        </ul>
        <p>Example: For a broadcaster named examplebroadcaster, use:</p>
        <ul>
            <li>Audio: <code>https://feed.sermonaudio.com/broadcasters/examplebroadcaster</code></li>
            <li>Video: <code>https://feed.sermonaudio.com/broadcasters/examplebroadcaster?video=true</code></li>
        </ul>
    </div>
</div>

</div>
