<?php

use humhub\components\Migration;

class m260220_000002_add_sermon_limit extends Migration
{
    public function safeUp()
    {
        $this->addColumn('sermonaudio_feed', 'sermon_limit', $this->integer()->notNull()->defaultValue(10));
    }

    public function safeDown()
    {
        $this->dropColumn('sermonaudio_feed', 'sermon_limit');
    }
}
