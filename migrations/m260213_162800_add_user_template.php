<?php

use yii\db\Migration;

class m260213_162800_add_user_template extends Migration
{
    public function up()
    {
        $this->addColumn('sermonaudio_feed', 'post_as_user_id', $this->integer()->after('space_id'));
        $this->addColumn('sermonaudio_feed', 'post_template', $this->text()->after('feed_type'));
        
        $this->addForeignKey(
            'fk_sermonaudio_feed_user',
            'sermonaudio_feed',
            'post_as_user_id',
            'user',
            'id',
            'SET NULL'
        );
    }

    public function down()
    {
        $this->dropForeignKey('fk_sermonaudio_feed_user', 'sermonaudio_feed');
        $this->dropColumn('sermonaudio_feed', 'post_template');
        $this->dropColumn('sermonaudio_feed', 'post_as_user_id');
    }
}
