<?php
namespace app\modules\sermonaudio;

use humhub\modules\content\components\ContentContainerModule;
use humhub\modules\space\models\Space;
use Yii;

class Module extends ContentContainerModule
{
    /**
     * @inheritdoc
     */
    public function getContentContainerTypes()
    {
        return [
            Space::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getContentContainerName(\humhub\modules\content\components\ContentContainerActiveRecord $container)
    {
        return Yii::t('SermonaudioModule.base', 'SermonAudio');
    }

    /**
     * @inheritdoc
     */
    public function getContentContainerDescription(\humhub\modules\content\components\ContentContainerActiveRecord $container)
    {
        return Yii::t('SermonaudioModule.base', 'Automatically posts new sermons from SermonAudio RSS feeds');
    }

    /**
     * @inheritdoc
     * No global config - this is a space-level module
     */
    public function getConfigUrl()
    {
        // Return null to hide the Configure button in global admin
        return null;
    }

    /**
     * @inheritdoc
     * This is where space-level configuration happens
     */
    public function getContentContainerConfigUrl(\humhub\modules\content\components\ContentContainerActiveRecord $container)
    {
        return $container->createUrl('/sermonaudio/config-container');
    }

    /**
     * @inheritdoc
     */
    public function disable()
    {
        parent::disable();
    }
}
