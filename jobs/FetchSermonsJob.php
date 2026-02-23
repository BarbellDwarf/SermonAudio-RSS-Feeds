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
        // Clean up orphaned content records periodically
        $module = Yii::$app->getModule('sermonaudio');
        if ($module) {
            $module->cleanupOrphanedContent();
        }

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
            Yii::info("=== Processing feed {$feed->id} (URL: {$feed->feed_url}) ===", 'sermonaudio');
            $module = Yii::$app->getModule('sermonaudio');
            $apiKey = $module ? $module->getApiKey() : null;
            $apiEnabled = $module ? $module->getApiEnabled() : false;
            
            Yii::info("API Settings: enabled={$apiEnabled}, hasKey=" . ($apiKey ? 'YES' : 'NO'), 'sermonaudio');
            
            if ($module && $module->getApiEnabled() && !empty($apiKey)) {
                Yii::info("Using API path for feed {$feed->id}", 'sermonaudio');
                $this->processApiFeed($feed, $apiKey);
                return;
            }
            
            Yii::info("Using RSS path for feed {$feed->id}", 'sermonaudio');

            $xml = @simplexml_load_file($feed->getFullFeedUrl());
            
            if ($xml === false) {
                Yii::error("Failed to load RSS feed: {$feed->getFullFeedUrl()}", 'sermonaudio');
                return;
            }

            $items = $xml->channel->item;
            
            if (empty($items)) {
                return;
            }

            // Consider sermons in order until we collect the limit of new posts
            $itemsArray = iterator_to_array($items);
            $limit = max(1, (int) $feed->sermon_limit);
            
            $newSermons = [];
            $matchedCount = 0;
            foreach ($itemsArray as $item) {
                if (!empty($feed->event_type) && !$this->itemMatchesEventType($item, $feed->event_type)) {
                    continue;
                }

                $matchedCount++;
                if ($matchedCount > $limit) {
                    break;
                }

                $link = (string) $item->link;
                if ($this->sermonAlreadyPosted($feed, $link)) {
                    continue;
                }

                $newSermons[] = $item;
            }
            
            // If no new sermons, just update the last check time
            if (empty($newSermons)) {
                $feed->last_check = time();
                $feed->last_sermon_guid = (string) ($itemsArray[0]->guid ?? $feed->last_sermon_guid);
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
            $feed->last_sermon_guid = (string) ($itemsArray[0]->guid ?? $feed->last_sermon_guid);
            $feed->last_check = time();
            $feed->save(false);
            
            Yii::info("Posted " . count($newSermons) . " new sermon(s) for feed {$feed->id}", 'sermonaudio');

        } catch (\Exception $e) {
            Yii::error("Error processing feed {$feed->id}: " . $e->getMessage(), 'sermonaudio');
        }
    }

    protected function processApiFeed(Feed $feed, string $apiKey): void
    {
        $limit = max(1, (int) $feed->sermon_limit);
        $sermons = $this->fetchApiSermons($feed, $apiKey, $limit);

        if (empty($sermons)) {
            $feed->last_check = time();
            $feed->save(false);
            return;
        }

        $newSermons = [];
        foreach ($sermons as $sermon) {
            if (!empty($feed->event_type) && !$this->apiSermonMatchesEventType($sermon, $feed->event_type)) {
                continue;
            }

            $link = $this->getApiSermonLink($sermon);
            if (empty($link)) {
                continue;
            }

            if ($this->sermonAlreadyPosted($feed, $link)) {
                continue;
            }

            $newSermons[] = $sermon;
        }

        if (empty($newSermons)) {
            $feed->last_check = time();
            $feed->last_sermon_guid = (string) ($sermons[0]['sermonID'] ?? $feed->last_sermon_guid);
            $feed->save(false);
            return;
        }

        $newSermons = array_reverse($newSermons);
        foreach ($newSermons as $sermon) {
            $this->createSermonPostFromApi($feed, $sermon);
            usleep(500000);
        }

        $feed->last_sermon_guid = (string) ($sermons[0]['sermonID'] ?? $feed->last_sermon_guid);
        $feed->last_check = time();
        $feed->save(false);

        Yii::info("Posted " . count($newSermons) . " new API sermon(s) for feed {$feed->id}", 'sermonaudio');
    }

    protected function fetchApiSermons(Feed $feed, string $apiKey, int $limit): array
    {
        $broadcasterId = $feed->getBroadcasterId();
        if (empty($broadcasterId)) {
            Yii::error("Missing broadcaster ID for feed {$feed->id}", 'sermonaudio');
            return [];
        }

        $params = [
            'broadcasterID' => $broadcasterId,
            'page' => 1,
            'pageSize' => $limit,
            'sortBy' => 'newest',
        ];

        if ($feed->feed_type === 'video') {
            $params['requireVideo'] = 'true';
        } else {
            $params['requireAudio'] = 'true';
        }

        if (!empty($feed->event_type)) {
            $params['eventType'] = $feed->event_type;
        }

        $url = 'https://api.sermonaudio.com/v2/node/sermons?' . http_build_query($params);
        Yii::info("Fetching API sermons from: {$url}", 'sermonaudio');
        
        $response = $this->apiGet($url, $apiKey);
        if (empty($response)) {
            Yii::warning("Empty response from API for feed {$feed->id}", 'sermonaudio');
            return [];
        }

        Yii::info("API Response: " . substr($response, 0, 500), 'sermonaudio');
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            Yii::error("Invalid JSON response from API: {$response}", 'sermonaudio');
            return [];
        }

        if (isset($data['errors'])) {
            Yii::error("API returned error: " . json_encode($data['errors']), 'sermonaudio');
            return [];
        }

        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];
        Yii::info("Found " . count($results) . " sermons from API for feed {$feed->id}", 'sermonaudio');
        return $results;
    }

    protected function apiGet(string $url, string $apiKey): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $apiKey],
            ]);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($result === false) {
                Yii::error('API request failed: ' . curl_error($ch), 'sermonaudio');
                curl_close($ch);
                return null;
            }

            curl_close($ch);

            if ($status >= 400) {
                Yii::error("API request returned status {$status}. Response: {$result}", 'sermonaudio');
                return null;
            }

            return $result;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'header' => "X-Api-Key: {$apiKey}\r\n",
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            Yii::error("API request failed for {$url}", 'sermonaudio');
            return null;
        }

        return $result;
    }

    protected function getApiSermonLink(array $sermon): ?string
    {
        if (!empty($sermon['webLink'])) {
            return $sermon['webLink'];
        }

        if (!empty($sermon['externalLink'])) {
            return $sermon['externalLink'];
        }

        if (!empty($sermon['sermonID'])) {
            return 'https://www.sermonaudio.com/sermons/' . $sermon['sermonID'];
        }

        return null;
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
            ->andWhere(['IS NOT', 'p.message', null])  // Exclude orphaned posts with NULL message
            ->exists();
    }

    protected function itemMatchesEventType($item, string $eventType): bool
    {
        $eventType = trim(mb_strtolower($eventType));
        $candidates = [];

        if (isset($item->category)) {
            foreach ($item->category as $category) {
                $value = trim((string) $category);
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        $namespaces = $item->getNameSpaces(true);
        foreach (['sermonaudio', 'sa'] as $nsKey) {
            if (isset($namespaces[$nsKey])) {
                $sa = $item->children($namespaces[$nsKey]);
                foreach (['eventType', 'event_type', 'category'] as $field) {
                    if (isset($sa->$field)) {
                        $value = trim((string) $sa->$field);
                        if ($value !== '') {
                            $candidates[] = $value;
                        }
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (mb_strtolower($candidate) === $eventType) {
                return true;
            }
        }

        return false;
    }

    protected function apiSermonMatchesEventType(array $sermon, string $eventType): bool
    {
        $eventType = $this->normalizeEventType($eventType);
        if ($eventType === '') {
            return true;
        }

        $candidates = [];

        foreach (['eventTypeName', 'eventTypeTitle', 'eventTypeLabel', 'eventTypeDisplayName', 'eventTypeText', 'eventTypeValue', 'category'] as $field) {
            if (!empty($sermon[$field]) && is_string($sermon[$field])) {
                $candidates[] = $sermon[$field];
            }
        }

        if (!empty($sermon['eventType'])) {
            if (is_string($sermon['eventType'])) {
                $candidates[] = $sermon['eventType'];
            } elseif (is_array($sermon['eventType'])) {
                foreach (['displayName', 'name', 'title', 'label', 'value'] as $subField) {
                    if (!empty($sermon['eventType'][$subField]) && is_string($sermon['eventType'][$subField])) {
                        $candidates[] = $sermon['eventType'][$subField];
                    }
                }
            }
        }

        if (!empty($sermon['event'])) {
            if (is_string($sermon['event'])) {
                $candidates[] = $sermon['event'];
            } elseif (is_array($sermon['event'])) {
                foreach (['type', 'displayName', 'name', 'title', 'label'] as $subField) {
                    if (!empty($sermon['event'][$subField]) && is_string($sermon['event'][$subField])) {
                        $candidates[] = $sermon['event'][$subField];
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->normalizeEventType($candidate) === $eventType) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeEventType(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }

    protected function createSermonPost(Feed $feed, $item, $channel)
    {
        $space = $feed->space;
        
        if (!$space) {
            return false;
        }

        $user = $this->resolvePostUser($feed);
        
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
        $message = str_replace('{title_link}', '[' . $title . '](' . $link . ')', $message);
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

    protected function createSermonPostFromApi(Feed $feed, array $sermon)
    {
        $space = $feed->space;

        if (!$space) {
            return false;
        }

        $user = $this->resolvePostUser($feed);
        if (!$user) {
            Yii::error("No user found to post sermon for feed {$feed->id}", 'sermonaudio');
            return false;
        }

        $title = $sermon['displayTitle'] ?? $sermon['fullTitle'] ?? $sermon['title'] ?? 'Sermon';
        $link = $this->getApiSermonLink($sermon);
        if (empty($link)) {
            return false;
        }

        if ($this->sermonAlreadyPosted($feed, $link)) {
            Yii::info("Skipping sermon already posted: {$link}", 'sermonaudio');
            return false;
        }

        $channelName = $sermon['broadcaster']['displayName'] ?? $sermon['broadcaster']['shortName'] ?? '';
        $series = $sermon['series']['displayTitle'] ?? $sermon['series']['title'] ?? '';
        $speaker = $sermon['speaker']['displayName'] ?? $sermon['speaker']['fullName'] ?? '';

        $template = $feed->post_template ?: $feed->getDefaultTemplate();
        $type = $feed->feed_type === 'video' ? 'video' : 'sermon';

        $message = $template;
        $message = str_replace('{channel}', $channelName, $message);
        $message = str_replace('{type}', $type, $message);
        $message = str_replace('{title}', $title, $message);
        $message = str_replace('{title_link}', '[' . $title . '](' . $link . ')', $message);
        $message = str_replace('{link}', '[' . $link . '](oembed:' . $link . ')', $message);

        if (strpos($message, '{series}') !== false) {
            if ($series) {
                $message = str_replace('{series}', $series, $message);
            } else {
                $lines = explode("\n", $message);
                $lines = array_filter($lines, function($line) {
                    return strpos($line, '{series}') === false;
                });
                $message = implode("\n", $lines);
            }
        }

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

        UrlOembed::getOEmbed($link);

        $currentIdentity = Yii::$app->user->identity;
        Yii::$app->user->switchIdentity($user);

        try {
            $post = new Post($space);
            $post->message = trim($message);

            if ($post->save()) {
                Yii::info("Created API sermon post: {$title} in space {$space->name} as user {$user->username}", 'sermonaudio');
                return true;
            }

            Yii::error("Failed to create API sermon post: " . print_r($post->errors, true), 'sermonaudio');
            return false;
        } finally {
            Yii::$app->user->switchIdentity($currentIdentity);
        }
    }

    protected function resolvePostUser(Feed $feed): ?User
    {
        $space = $feed->space;
        if (!$space) {
            return null;
        }

        if ($feed->post_as_user_id && $feed->postAsUser) {
            return $feed->postAsUser;
        }

        $adminMembership = Membership::find()
            ->where(['space_id' => $space->id, 'admin_role' => 1])
            ->with('user')
            ->one();

        if ($adminMembership && $adminMembership->user) {
            return $adminMembership->user;
        }

        $anyMembership = Membership::find()
            ->where(['space_id' => $space->id])
            ->with('user')
            ->one();

        if ($anyMembership && $anyMembership->user) {
            return $anyMembership->user;
        }

        return null;
    }
}
