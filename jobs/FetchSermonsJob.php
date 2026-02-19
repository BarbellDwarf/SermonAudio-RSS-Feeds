<?php

namespace app\modules\sermonaudio\jobs;

use app\modules\sermonaudio\models\Feed;
use humhub\modules\post\models\Post;
use humhub\modules\queue\ActiveJob;
use humhub\modules\space\models\Membership;
use humhub\modules\user\models\User;
use humhub\models\UrlOembed;
use Yii;

class FetchSermonsJob extends ActiveJob
{
    public function run()
    {
        $feeds = Feed::find()->where(['enabled' => true])->all();

        foreach ($feeds as $feed) {
            // Check if this feed should be checked based on its schedule
            if ($feed->shouldCheck()) {
                $this->processFeed($feed);
            }
        }
    }

    protected function processFeed(Feed $feed)
    {
        try {
            $xml = @simplexml_load_file($feed->feed_url);
            
            if ($xml === false) {
                Yii::error("Failed to load RSS feed: {$feed->feed_url}", 'sermonaudio');
                return;
            }

            $items = $xml->channel->item;
            
            if (empty($items)) {
                return;
            }

            // Collect all new sermons
            $newSermons = [];
            $foundLastSermon = false;
            
            foreach ($items as $item) {
                $guid = (string) $item->guid;
                
                // If this is the last sermon we posted, we're caught up
                if ($feed->last_sermon_guid === $guid) {
                    $foundLastSermon = true;
                    break;
                }
                
                // This is a new sermon, add it to the list
                $newSermons[] = $item;
            }
            
            // If no new sermons, just update the last check time
            if (empty($newSermons)) {
                $feed->last_check = time();
                $feed->save(false);
                return;
            }
            
            // Post sermons in reverse order (oldest to newest) so they appear chronologically
            $newSermons = array_reverse($newSermons);
            
            foreach ($newSermons as $sermon) {
                $this->createSermonPost($feed, $sermon, $xml->channel);
                
                // Small delay between posts to avoid overwhelming the system
                usleep(500000); // 0.5 second delay
            }

            // Update feed record with the newest sermon GUID
            $feed->last_sermon_guid = (string) $items[0]->guid;
            $feed->last_check = time();
            $feed->save(false);
            
            Yii::info("Posted " . count($newSermons) . " new sermon(s) for feed {$feed->id}", 'sermonaudio');

        } catch (\Exception $e) {
            Yii::error("Error processing feed {$feed->id}: " . $e->getMessage(), 'sermonaudio');
        }
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
