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
 * @property string $event_type
 * @property string $url_parameters
 * @property integer $sermon_limit
 * @property integer $created_at
 * @property integer $updated_at
 */
class Feed extends ActiveRecord
{
    /**
     * @var string|null
     */
    private $originalEventType;

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        // Ensure event_type is always a string for form handling
        if ($name === 'event_type') {
            $value = parent::__get($name);
            return $value === null ? '' : $value;
        }
        return parent::__get($name);
    }

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
            [['space_id', 'post_as_user_id', 'last_check', 'sermon_limit'], 'integer'],
            [['feed_url'], 'string', 'max' => 500],
            [['feed_url'], 'url'],
            [['feed_type'], 'string', 'max' => 20],
            [['feed_type'], 'in', 'range' => ['audio', 'video']],
            [['cron_schedule'], 'string', 'max' => 20],
            [['cron_schedule'], 'in', 'range' => ['hourly', 'daily', 'weekly']],
            [['post_template'], 'string'],
            [['last_sermon_guid'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
            [['event_type'], 'string', 'max' => 50],
            [['event_type'], 'validateEventType'],
            [['url_parameters'], 'string'],
            [['sermon_limit'], 'integer', 'min' => 1, 'max' => 100],
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
        $module = \Yii::$app->getModule('sermonaudio');
        $debugMode = $module && $module->isDebugModeEnabled();

        // In debug mode, use shorter intervals (every 15 minutes) for testing
        if ($debugMode) {
            return $timeSinceLastCheck >= 900; // 15 minutes for testing
        }

        switch ($this->cron_schedule) {
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
            'hourly' => 'Every hour',
            'daily' => 'Once per day',
            'weekly' => 'Once per week',
        ];
    }

    /**
     * Get default sermon limit
     */
    public static function getDefaultSermonLimit()
    {
        return 10;
    }

    public function getBroadcasterId(): ?string
    {
        if (empty($this->feed_url)) {
            return null;
        }

        $parsedUrl = parse_url($this->feed_url);
        if (empty($parsedUrl['path'])) {
            return null;
        }

        $path = trim($parsedUrl['path'], '/');
        $segments = explode('/', $path);
        $broadcasterIndex = array_search('broadcasters', $segments, true);
        if ($broadcasterIndex === false || !isset($segments[$broadcasterIndex + 1])) {
            return null;
        }

        return $segments[$broadcasterIndex + 1] ?: null;
    }

    /**
     * Get event type options (hardcoded list from SermonAudio)
     */
    public static function getEventTypeOptions()
    {
        return [
            'Sunday - AM' => 'Sunday - AM',
            'Sunday - PM' => 'Sunday - PM',
            'Sunday Service' => 'Sunday Service',
            'Midweek Service' => 'Midweek Service',
            'Prayer Meeting' => 'Prayer Meeting',
            'Conference' => 'Conference',
            'Special Meeting' => 'Special Meeting',
            'Sunday School' => 'Sunday School',
        ];
    }

    /**
     * Validate event type - warn if not in standard list but allow custom values
     */
    public function validateEventType($attribute, $params)
    {
        if (!empty($this->$attribute)) {
            $standardTypes = array_keys(self::getEventTypeOptions());
            if (!in_array($this->$attribute, $standardTypes)) {
                $this->addWarning($attribute, 'This is a custom event type. Please ensure it exists in your SermonAudio broadcaster settings.');
            }
        }
    }

    /**
     * Extract URL parameters from feed_url and populate fields
     */
    public function afterFind()
    {
        parent::afterFind();
        
        // Ensure event_type is at least an empty string, not null
        if ($this->event_type === null) {
            $this->event_type = '';
        }

        $this->originalEventType = $this->event_type;
        
        // Parse URL and extract parameters for auto-population
        if (!empty($this->feed_url)) {
            $parsedUrl = parse_url($this->feed_url);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                
                // Auto-populate event_type if not already set and exists in URL
                if (empty($this->event_type) && isset($queryParams['eventType'])) {
                    $this->event_type = $queryParams['eventType'];
                }
            }
        }
    }

    /**
     * Extract parameters from feed_url before saving
     */
    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        // Convert empty string event_type back to null
        if ($this->event_type === '') {
            $this->event_type = null;
        }

        if (!$insert && $this->originalEventType !== null && $this->event_type !== $this->originalEventType) {
            $this->last_sermon_guid = null;
            $this->last_check = null;
        }

        // Parse URL and extract all parameters
        if (!empty($this->feed_url)) {
            $parsedUrl = parse_url($this->feed_url);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'];
            
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                
                // Remove eventType and video parameters - they're controlled by UI fields
                unset($queryParams['eventType']);
                unset($queryParams['video']);
                
                // Store remaining parameters as JSON
                if (!empty($queryParams)) {
                    $this->url_parameters = json_encode($queryParams);
                } else {
                    $this->url_parameters = null;
                }
            }
            
            // Store clean base URL without parameters
            $this->feed_url = $baseUrl;
        }

        return true;
    }

    /**
     * Build full feed URL with all parameters
     * UI field selections override URL parameters
     */
    public function getFullFeedUrl()
    {
        $url = $this->feed_url;
        $params = [];

        // Add video parameter if feed_type is video
        if ($this->feed_type === 'video') {
            $params['video'] = 'true';
        }

        // Add eventType parameter if set
        if (!empty($this->event_type)) {
            $params['eventType'] = $this->event_type;
        }

        // Add other stored parameters
        if (!empty($this->url_parameters)) {
            $storedParams = json_decode($this->url_parameters, true);
            if (is_array($storedParams)) {
                $params = array_merge($params, $storedParams);
            }
        }

        // Build query string
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }
}
