#!/usr/bin/env php
<?php
/**
 * SermonAudio Feed Checker
 * Run this script from cron for frequent feed checks
 */

define('HUMHUB_PATH', __DIR__ . '/../../');

// Minimal bootstrap
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require(HUMHUB_PATH . 'vendor/autoload.php');
require(HUMHUB_PATH . 'vendor/yiisoft/yii2/Yii.php');

$config = require(HUMHUB_PATH . 'config/common.php');
$config['components']['request'] = ['class' => 'yii\console\Request'];
$config['components']['response'] = ['class' => 'yii\console\Response'];

$app = new yii\base\Application($config);

// Push the job to the queue
Yii::$app->queue->push(new app\modules\sermonaudio\jobs\FetchSermonsJob());

echo "[" . date('Y-m-d H:i:s') . "] Feed check job queued successfully.\n";
exit(0);
