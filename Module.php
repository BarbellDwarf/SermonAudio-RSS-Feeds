<?php
namespace app\modules\sermonaudio;

use humhub\modules\content\components\ContentContainerModule;
use humhub\modules\space\models\Space;
use yii\helpers\Url;
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
        return Url::to(['/sermonaudio/admin']);
    }

    public function hasApiKey(): bool
    {
        return (bool) $this->settings->get('apiKey');
    }

    public function getApiEnabled(): bool
    {
        return (bool) $this->settings->get('apiEnabled');
    }

    public function setApiEnabled(bool $enabled): void
    {
        $this->settings->set('apiEnabled', $enabled ? 1 : 0);
    }

    public function setApiKey(string $apiKey): void
    {
        $encryptionKey = $this->getApiEncryptionKey();
        $encrypted = Yii::$app->security->encryptByKey($apiKey, $encryptionKey);
        $this->settings->set('apiKey', base64_encode($encrypted));
    }

    public function getApiKey(): ?string
    {
        $encrypted = $this->settings->get('apiKey');
        if (empty($encrypted)) {
            return null;
        }

        $encryptionKey = $this->getApiEncryptionKey();
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return null;
        }

        return Yii::$app->security->decryptByKey($decoded, $encryptionKey);
    }

    protected function getApiEncryptionKey(): string
    {
        $key = $this->settings->get('apiEncryptionKey');
        if (empty($key)) {
            $key = Yii::$app->security->generateRandomString(32);
            $this->settings->set('apiEncryptionKey', $key);
        }

        return $key;
    }

    /**
     * Check if debug mode is enabled (shorter intervals for testing)
     */
    public function isDebugModeEnabled(): bool
    {
        return (bool) $this->settings->get('debugMode');
    }

    /**
     * Set debug mode state
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->settings->set('debugMode', $enabled ? 1 : 0);
    }

    /**
     * Clean up orphaned content records (posts deleted in UI but content record remains)
     * This prevents false "already posted" detection
     */
    public function cleanupOrphanedContent(): int
    {
        $deleted = Yii::$app->db->createCommand()->delete(
            '{{%content}}',
            ['and',
                ['object_model' => 'humhub\modules\post\models\Post'],
                ['not in', 'object_id', (new \yii\db\Query())->select('id')->from('{{%post}}')]
            ]
        )->execute();
        
        if ($deleted > 0) {
            Yii::info("Cleaned up {$deleted} orphaned content records", 'sermonaudio');
        }
        
        return $deleted;
    }

    /**
     * Clean up soft-deleted sermon posts
     * Permanently hard-deletes posts that were created by this module and are in STATE_DELETED state
     * Only deletes posts older than the retention period (default 30 days)
     */
    public function cleanupSoftDeletedSermonPosts(): int
    {
        $retentionDays = (int) $this->settings->get('softDeleteRetentionDays', 30);
        $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        // Find soft-deleted sermon posts older than retention period
        $softDeletedPosts = (new \yii\db\Query())
            ->select('p.id')
            ->from('{{%post}} p')
            ->innerJoin('{{%content}} c', 'c.object_id = p.id AND c.object_model = :model', [':model' => 'humhub\modules\post\models\Post'])
            ->where(['c.state' => \humhub\modules\content\models\Content::STATE_DELETED])
            ->andWhere(['<', 'c.updated_at', $cutoffDate])
            ->column();

        if (empty($softDeletedPosts)) {
            return 0;
        }

        $deletedCount = 0;

        // Hard delete each post
        foreach ($softDeletedPosts as $postId) {
            try {
                $post = \humhub\modules\post\models\Post::findOne($postId);
                if ($post) {
                    $post->hardDelete();
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                Yii::warning("Failed to hard-delete sermon post {$postId}: " . $e->getMessage(), 'sermonaudio');
            }
        }

        if ($deletedCount > 0) {
            Yii::info("Cleaned up {$deletedCount} soft-deleted sermon posts (retention: {$retentionDays} days)", 'sermonaudio');
        }

        return $deletedCount;
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
