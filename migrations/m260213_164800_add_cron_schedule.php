<?php

use yii\db\Migration;

class m260213_164800_add_cron_schedule extends Migration
{
    public function up()
    {
        $this->addColumn('sermonaudio_feed', 'cron_schedule', $this->string(20)->notNull()->defaultValue('hourly')->after('feed_type'));
    }

    public function down()
    {
        $this->dropColumn('sermonaudio_feed', 'cron_schedule');
    }
}
