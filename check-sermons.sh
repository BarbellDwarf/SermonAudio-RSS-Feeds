#!/bin/bash
# SermonAudio Feed Checker
# Calls HumHub's cron hourly command which triggers our feed check

cd /var/www/humhub
php protected/yii cron/hourly >> /var/log/sermonaudio.log 2>&1
