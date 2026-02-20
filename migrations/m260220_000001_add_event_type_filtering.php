<?php

use humhub\components\Migration;

class m260220_000001_add_event_type_filtering extends Migration
{
    public function safeUp()
    {
        $this->addColumn('sermonaudio_feed', 'event_type', $this->string(50)->null()->defaultValue(null));
        $this->addColumn('sermonaudio_feed', 'url_parameters', $this->text()->null()->defaultValue(null));
    }

    public function safeDown()
    {
        $this->dropColumn('sermonaudio_feed', 'event_type');
        $this->dropColumn('sermonaudio_feed', 'url_parameters');
    }
}
