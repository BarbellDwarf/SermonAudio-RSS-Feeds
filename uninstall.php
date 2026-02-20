<?php

use Yii;

$db = Yii::$app->db;
$schema = $db->schema;

if ($schema->getTableSchema('sermonaudio_feed', true) !== null) {
    $db->createCommand()->dropTable('sermonaudio_feed')->execute();
}

// Remove module settings
Yii::$app->settings->delete('apiKey', 'sermonaudio');
Yii::$app->settings->delete('apiEnabled', 'sermonaudio');
Yii::$app->settings->delete('apiEncryptionKey', 'sermonaudio');
Yii::$app->settings->delete('useApi', 'sermonaudio');