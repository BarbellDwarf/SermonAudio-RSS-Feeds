<?php

use humhub\modules\user\authclient\Collection;
use humhub\commands\CronController;

return [
    'id' => 'sermonaudio',
    'class' => 'app\modules\sermonaudio\Module',
    'namespace' => 'app\modules\sermonaudio',
    'events' => [
        [
            'class' => \humhub\models\UrlOembed::class,
            'event' => \humhub\models\UrlOembed::EVENT_FETCH,
            'callback' => ['app\modules\sermonaudio\Events', 'onUrlOembedFetch']
        ],
        [
            'class' => CronController::class,
            'event' => CronController::EVENT_ON_HOURLY_RUN,
            'callback' => ['app\modules\sermonaudio\Events', 'onCronRun']
        ],
        [
            'class' => CronController::class,
            'event' => CronController::EVENT_ON_DAILY_RUN,
            'callback' => ['app\modules\sermonaudio\Events', 'onDailyCronRun']
        ],
    ]
];
