<?php

namespace app\modules\sermonaudio\models;

use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;
use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * SermonAudio Feed Model
 *
 * @property integer $id
 * @property integer $space_id
 * @property integer $post_as_user_id
 * @property string $feed_url
 * @property string $feed_type
 * @property string $cron_schedule
 * @property string $post_template
 * @property integer $last_check
 * @property string $last_sermon_guid
 * @property boolean $enabled
 * @property integer $created_at
 * @property integer $updated_at
 */
class Feed extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'sermonaudio_feed';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['space_id', 'feed_url'], 'required'],
            [['space_id', 'post_as_user_id', 'last_check'], 'integer'],
            [['feed_url'], 'string', 'max' => 500],
            [['feed_url'], 'url'],
            [['feed_type'], 'string', 'max' => 20],
            [['feed_type'], 'in', 'range' => ['audio', 'video']],
            [['cron_schedule'], 'string', 'max' => 20],
            [['cron_schedule'], 'in', 'range' => ['15min', '30min', 'hourly', 'daily', 'weekly']],
            [['post_template'], 'string'],
            [['last_sermon_guid'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getSpace()
    {
        return $this->hasOne(Space::class, ['id' => 'space_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getPostAsUser()
    {
        return $this->hasOne(User::class, ['id' => 'post_as_user_id']);
    }

    /**
     * Get default post template
     */
    public function getDefaultTemplate()
    {
        return "{channel} has published a new {type}: {title}\n\n{link}";
    }

    /**
     * Check if feed should be checked based on schedule
     */
    public function shouldCheck()
    {
        if (!$this->last_check) {
            return true; // Never checked, check now
        }

        $timeSinceLastCheck = time() - $this->last_check;

        switch ($this->cron_schedule) {
            case '15min':
                return $timeSinceLastCheck >= 900; // 15 minutes
            case '30min':
                return $timeSinceLastCheck >= 1800; // 30 minutes
            case 'hourly':
                return $timeSinceLastCheck >= 3600; // 1 hour
            case 'daily':
                return $timeSinceLastCheck >= 86400; // 24 hours
            case 'weekly':
                return $timeSinceLastCheck >= 604800; // 7 days
            default:
                return $timeSinceLastCheck >= 3600; // Default to hourly
        }
    }

    /**
     * Get cron schedule options
     */
    public static function getCronScheduleOptions()
    {
        return [
            '15min' => 'Every 15 minutes',
            '30min' => 'Every 30 minutes',
            'hourly' => 'Every hour',
            'daily' => 'Once per day',
            'weekly' => 'Once per week',
        ];
    }
}
