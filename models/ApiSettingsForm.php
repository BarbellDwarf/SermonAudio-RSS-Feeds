<?php

namespace app\modules\sermonaudio\models;

use Yii;
use yii\base\Model;

class ApiSettingsForm extends Model
{
    public $apiEnabled = false;
    public $apiKey;
    public $hasApiKey = false;
    public $debugMode = false;
    public $softDeleteRetentionDays = 30;

    public function rules()
    {
        return [
            [['apiEnabled', 'debugMode'], 'boolean'],
            [['apiKey'], 'string', 'max' => 255],
            [['apiKey'], 'trim'],
            [['softDeleteRetentionDays'], 'integer', 'min' => 1, 'max' => 365],
            [['apiEnabled'], 'validateApiEnabled'],
        ];
    }

    public function validateApiEnabled($attribute, $params)
    {
        if ($this->$attribute && empty($this->apiKey) && !$this->hasApiKey) {
            $this->addError($attribute, Yii::t('SermonaudioModule.base', 'An API key is required to enable API features.'));
        }
    }
}