<?php

namespace app\modules\sermonaudio\controllers;

use app\modules\sermonaudio\models\Feed;
use humhub\modules\content\components\ContentContainerController;
use humhub\modules\space\models\Space;
use humhub\modules\space\models\Membership;
use humhub\modules\post\models\Post;
use humhub\models\UrlOembed;
use Yii;

class ConfigController extends ContentContainerController
{
    /**
     * Configuration Index
     */
    public function actionIndex()
    {
        $space = $this->contentContainer;
        
        if (!$space instanceof Space) {
            throw new \yii\web\HttpException(404, 'Invalid content container');
        }

        $feeds = Feed::find()->where(['space_id' => $space->id])->all();

        return $this->render('index', [
            'space' => $space,
            'feeds' => $feeds,
        ]);
    }

    /**
     * Add new feed
     */
    public function actionAdd()
    {
        $space = $this->contentContainer;
        $model = new Feed();
        $model->space_id = $space->id;

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
        }

        return $this->render('edit-page', [
            'model' => $model,
            'space' => $space,
        ]);
    }

    /**
     * Edit feed
     */
    public function actionEdit($id)
    {
        $space = $this->contentContainer;
        $model = Feed::findOne(['id' => $id, 'space_id' => $space->id]);

        if (!$model) {
            throw new \yii\web\HttpException(404, 'Feed not found');
        }

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
        }

        return $this->render('edit-page', [
            'model' => $model,
            'space' => $space,
        ]);
    }

    /**
     * Delete feed
     */
    public function actionDelete($id)
    {
        $space = $this->contentContainer;
        $model = Feed::findOne(['id' => $id, 'space_id' => $space->id]);

        if ($model) {
            $model->delete();
        }

        return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
    }

    /**
     * Toggle feed enabled status
     */
    public function actionToggle($id)
    {
        $space = $this->contentContainer;
        $model = Feed::findOne(['id' => $id, 'space_id' => $space->id]);

        if ($model) {
            $model->enabled = !$model->enabled;
            $model->save(false);
        }

        return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
    }

    /**
     * Manual feed check
     */
    public function actionCheckNow($id)
    {
        $space = $this->contentContainer;
        $feed = Feed::findOne(['id' => $id, 'space_id' => $space->id]);

        if (!$feed) {
            throw new \yii\web\HttpException(404, 'Feed not found');
        }

        try {
            $xml = @simplexml_load_file($feed->feed_url);
            
            if ($xml === false) {
                Yii::$app->session->setFlash('error', 'Failed to load RSS feed. Please check the feed URL.');
                return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
            }

            $items = $xml->channel->item;
            
            if (empty($items)) {
                Yii::$app->session->setFlash('warning', 'No items found in feed.');
                return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
            }

            // Collect all new sermons
            $newSermons = [];
            
            foreach ($items as $item) {
                $guid = (string) $item->guid;
                
                // If this is the last sermon we posted, we're caught up
                if ($feed->last_sermon_guid === $guid) {
                    break;
                }
                
                // This is a new sermon, add it to the list
                $newSermons[] = $item;
            }
            
            // If no new sermons
            if (empty($newSermons)) {
                Yii::$app->session->setFlash('info', 'Latest sermon has already been retrieved.');
                $feed->last_check = time();
                $feed->save(false);
                return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
            }
            
            // Post sermons in reverse order (oldest to newest) so they appear chronologically
            $newSermons = array_reverse($newSermons);
            $postedCount = 0;
            
            foreach ($newSermons as $sermon) {
                if ($this->createSermonPost($feed, $sermon, $xml->channel)) {
                    $postedCount++;
                }
            }

            // Update feed record with the newest sermon GUID
            $feed->last_sermon_guid = (string) $items[0]->guid;
            $feed->last_check = time();
            $feed->save(false);

            if ($postedCount === 0) {
                Yii::$app->session->setFlash('info', 'All recent sermons have already been posted.');
            } elseif ($postedCount === 1) {
                Yii::$app->session->setFlash('success', 'New sermon post created successfully!');
            } else {
                Yii::$app->session->setFlash('success', "{$postedCount} new sermon posts created successfully!");
            }

        } catch (\Exception $e) {
            Yii::error("Error checking feed {$feed->id}: " . $e->getMessage(), 'sermonaudio');
            Yii::$app->session->setFlash('error', 'Error checking feed: ' . $e->getMessage());
        }

        return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
    }

    protected function sermonAlreadyPosted(Feed $feed, string $link): bool
    {
        $space = $feed->space;
        
        if (!$space || !$space->contentcontainer_id) {
            return false;
        }
        
        $oembedLink = 'oembed:' . $link;
        
        return (new \yii\db\Query())
            ->from('{{%post}} p')
            ->innerJoin('{{%content}} c', 'c.object_id = p.id AND c.object_model = :model', [':model' => Post::class])
            ->where(['c.contentcontainer_id' => $space->contentcontainer_id])
            ->andWhere(['or', ['like', 'p.message', $link], ['like', 'p.message', $oembedLink]])
            ->exists();
    }

    /**
     * Create sermon post
     */
    protected function createSermonPost(Feed $feed, $item, $channel)
    {
        $space = $feed->space;
        
        if (!$space) {
            return false;
        }

        // Get the user to post as
        $user = null;
        if ($feed->post_as_user_id) {
            $user = $feed->postAsUser;
        }
        
        if (!$user) {
            // Find a space admin if no user specified
            $adminMembership = Membership::find()
                ->where(['space_id' => $space->id, 'admin_role' => 1])
                ->with('user')
                ->one();
            
            if ($adminMembership && $adminMembership->user) {
                $user = $adminMembership->user;
            } else {
                // Fallback to any member if no admin found
                $anyMembership = Membership::find()
                    ->where(['space_id' => $space->id])
                    ->with('user')
                    ->one();
                
                if ($anyMembership && $anyMembership->user) {
                    $user = $anyMembership->user;
                }
            }
        }
        
        if (!$user) {
            Yii::error("No user found to post sermon for feed {$feed->id}", 'sermonaudio');
            return false;
        }

        // Extract data from RSS
        $title = (string) $item->title;
        $link = (string) $item->link;
        $channelName = (string) $channel->title;
        $channelName = str_replace(' (Video)', '', $channelName);
        $channelName = str_replace(' (Audio)', '', $channelName);
        
        if ($this->sermonAlreadyPosted($feed, $link)) {
            Yii::info("Skipping sermon already posted: {$link}", 'sermonaudio');
            return false;
        }
        
        // Extract series from itunes:subtitle if available
        $namespaces = $item->getNameSpaces(true);
        $itunes = $item->children($namespaces['itunes'] ?? 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $subtitle = (string) ($itunes->subtitle ?? '');
        // Parse series from subtitle (format: "Author - Series")
        $series = '';
        if ($subtitle && strpos($subtitle, ' - ') !== false) {
            $parts = explode(' - ', $subtitle, 2);
            if (count($parts) > 1) {
                $series = trim($parts[1]);
            }
        }

        $speaker = trim((string) ($itunes->author ?? ''));
        if (!$speaker && isset($item->author)) {
            $speaker = trim((string) $item->author);
        }
        if (!$speaker && isset($namespaces['dc'])) {
            $dc = $item->children($namespaces['dc']);
            $speaker = trim((string) ($dc->creator ?? ''));
        }
        if (!$speaker && isset($namespaces['sermonaudio'])) {
            $sermonaudio = $item->children($namespaces['sermonaudio']);
            $speaker = trim((string) ($sermonaudio->speaker ?? ''));
        }
        if (!$speaker && isset($namespaces['sa'])) {
            $sermonaudio = $item->children($namespaces['sa']);
            $speaker = trim((string) ($sermonaudio->speaker ?? ''));
        }

        // Build message from template
        $template = $feed->post_template ?: $feed->getDefaultTemplate();
        $type = $feed->feed_type === 'video' ? 'video' : 'sermon';
        
        $message = $template;
        $message = str_replace('{channel}', $channelName, $message);
        $message = str_replace('{type}', $type, $message);
        $message = str_replace('{title}', $title, $message);
        $message = str_replace('{title_link}', '[' . $title . '](oembed:' . $link . ')', $message);
        $message = str_replace('{link}', '[' . $link . '](oembed:' . $link . ')', $message);
        // Handle series - remove line if no series
        if (strpos($message, '{series}') !== false) {
            if ($series) {
                $message = str_replace('{series}', $series, $message);
            } else {
                // Remove lines containing {series}
                $lines = explode("\n", $message);
                $lines = array_filter($lines, function($line) {
                    return strpos($line, '{series}') === false;
                });
                $message = implode("\n", $lines);
            }
        }

        // Handle speaker - remove line if no speaker
        if (strpos($message, '{speaker}') !== false) {
            if ($speaker) {
                $message = str_replace('{speaker}', $speaker, $message);
            } else {
                $lines = explode("\n", $message);
                $lines = array_filter($lines, function($line) {
                    return strpos($line, '{speaker}') === false;
                });
                $message = implode("\n", $lines);
            }
        }

        // Format link in oembed markdown format for HumHub via placeholders


        // Pre-fetch the oembed to cache it
        UrlOembed::getOEmbed($link);

        // Temporarily switch user identity to post as the correct user
        $currentIdentity = Yii::$app->user->identity;
        Yii::$app->user->switchIdentity($user);
        
        try {
            // Create the post
            $post = new Post($space);
            $post->message = trim($message);
            
            if ($post->save()) {
                Yii::info("Created sermon post: {$title} in space {$space->name} as user {$user->username}", 'sermonaudio');
                return true;
            }
            
            Yii::error("Failed to create sermon post: " . print_r($post->errors, true), 'sermonaudio');
            return false;
        } finally {
            // Always restore the original user identity
            Yii::$app->user->switchIdentity($currentIdentity);
        }
    }
}
