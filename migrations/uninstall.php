<?php

use yii\db\Migration;

class uninstall extends Migration
{
    public function up()
    {
        $this->dropForeignKey('fk_sermonaudio_feed_space', 'sermonaudio_feed');
        $this->dropTable('sermonaudio_feed');
    }

    public function down()
    {
        echo "uninstall does not support migration down.\n";
        return false;
    }
}
