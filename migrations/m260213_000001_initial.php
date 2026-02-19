<?php

use yii\db\Migration;

class m260213_000001_initial extends Migration
{
    public function up()
    {
        $this->createTable('sermonaudio_feed', [
            'id' => $this->primaryKey(),
            'space_id' => $this->integer()->notNull(),
            'feed_url' => $this->string(500)->notNull(),
            'feed_type' => $this->string(20)->notNull()->defaultValue('audio'),
            'last_check' => $this->integer(),
            'last_sermon_guid' => $this->string(255),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx_space_id', 'sermonaudio_feed', 'space_id');
        $this->createIndex('idx_enabled', 'sermonaudio_feed', 'enabled');

        $this->addForeignKey(
            'fk_sermonaudio_feed_space',
            'sermonaudio_feed',
            'space_id',
            'space',
            'id',
            'CASCADE'
        );
    }

    public function down()
    {
        $this->dropForeignKey('fk_sermonaudio_feed_space', 'sermonaudio_feed');
        $this->dropTable('sermonaudio_feed');
    }
}
