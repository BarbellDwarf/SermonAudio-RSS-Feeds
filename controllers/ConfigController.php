<?php

namespace app\modules\sermonaudio\controllers;

use app\modules\sermonaudio\models\Feed;
use humhub\modules\content\components\ContentContainerController;
use humhub\modules\space\models\Space;
use humhub\modules\space\models\Membership;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use humhub\models\UrlOembed;
use Yii;

class ConfigController extends ContentContainerController
{
    public function beforeAction($action)
    {
        file_put_contents('/tmp/sermon_debug.log', date('Y-m-d H:i:s') . " - ConfigController::" . $action->id . " called\n", FILE_APPEND);
        return parent::beforeAction($action);
    }
    
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
            // Check for custom event type warning
            if (!empty($model->event_type)) {
                $standardTypes = array_keys(Feed::getEventTypeOptions());
                if (!in_array($model->event_type, $standardTypes)) {
                    Yii::$app->session->setFlash('warning', 'You are using a custom event type "' . $model->event_type . '". Please ensure this event type exists in your SermonAudio broadcaster settings.');
                }
            }
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
            // Check for custom event type warning
            if (!empty($model->event_type)) {
                $standardTypes = array_keys(Feed::getEventTypeOptions());
                if (!in_array($model->event_type, $standardTypes)) {
                    Yii::$app->session->setFlash('warning', 'You are using a custom event type "' . $model->event_type . '". Please ensure this event type exists in your SermonAudio broadcaster settings.');
                }
            }
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
        file_put_contents('/tmp/sermon_debug.log', "=== actionCheckNow START ===\n", FILE_APPEND);
        $space = $this->contentContainer;
        $feed = Feed::findOne(['id' => $id, 'space_id' => $space->id]);

        if (!$feed) {
            file_put_contents('/tmp/sermon_debug.log', "Feed not found\n", FILE_APPEND);
            throw new \yii\web\HttpException(404, 'Feed not found');
        }

        file_put_contents('/tmp/sermon_debug.log', "Feed found: {$feed->feed_url}\n", FILE_APPEND);

        try {
            $module = Yii::$app->getModule('sermonaudio');
            file_put_contents('/tmp/sermon_debug.log', "Got module\n", FILE_APPEND);
            
            $apiKey = $module ? $module->getApiKey() : null;
            $apiEnabled = $module ? $module->getApiEnabled() : false;
            
            file_put_contents('/tmp/sermon_debug.log', "API enabled: {$apiEnabled}, hasKey: " . ($apiKey ? 'YES' : 'NO') . "\n", FILE_APPEND);
            
            if ($module && $module->getApiEnabled() && !empty($apiKey)) {
                $broadcasterId = $feed->getBroadcasterId();
                file_put_contents('/tmp/sermon_debug.log', "Broadcaster ID: " . ($broadcasterId ?: 'NULL') . "\n", FILE_APPEND);
                if (empty($broadcasterId)) {
                    Yii::$app->session->setFlash('warning', 'API is enabled, but the broadcaster ID could not be determined from the feed URL. Falling back to RSS.');
                } else {
                    file_put_contents('/tmp/sermon_debug.log', "Calling processApiCheck\n", FILE_APPEND);
                    return $this->processApiCheck($feed, $apiKey, $space);
                }
            } else {
                file_put_contents('/tmp/sermon_debug.log', "Using RSS path\n", FILE_APPEND);
            }


            $xml = @simplexml_load_file($feed->getFullFeedUrl());
            
            if ($xml === false) {
                Yii::$app->session->setFlash('error', 'Failed to load RSS feed. Please check the feed URL.');
                return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
            }

            $items = $xml->channel->item;
            
            if (empty($items)) {
                Yii::$app->session->setFlash('warning', 'No items found in feed.');
                return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
            }

            // Consider sermons in order until we collect the limit of new posts
            $itemsArray = iterator_to_array($items);
            $limit = max(1, (int) $feed->sermon_limit);
            
            // Collect all new sermons
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
            
            // If no new sermons
            if (empty($newSermons)) {
                Yii::$app->session->setFlash('info', 'Latest sermons have already been retrieved.');
                $feed->last_check = time();
                $feed->last_sermon_guid = (string) ($itemsArray[0]->guid ?? $feed->last_sermon_guid);
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
            $feed->last_sermon_guid = (string) ($itemsArray[0]->guid ?? $feed->last_sermon_guid);
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

    protected function processApiCheck(Feed $feed, string $apiKey, Space $space)
    {
        file_put_contents('/tmp/sermon_debug.log', "=== processApiCheck START ===\n", FILE_APPEND);
        $limit = max(1, (int) $feed->sermon_limit);
        file_put_contents('/tmp/sermon_debug.log', "Limit: {$limit}\n", FILE_APPEND);
        $sermons = $this->fetchApiSermons($feed, $apiKey, $limit);
        
        file_put_contents('/tmp/sermon_debug.log', "fetchApiSermons returned " . count($sermons) . " sermons\n", FILE_APPEND);

        if (empty($sermons)) {
            file_put_contents('/tmp/sermon_debug.log', "No sermons from API\n", FILE_APPEND);
            Yii::$app->session->setFlash('info', 'No sermons found via API.');
            $feed->last_check = time();
            $feed->save(false);
            return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
        }

        $newSermons = [];
        foreach ($sermons as $sermon) {
            $link = $this->getApiSermonLink($sermon);
            file_put_contents('/tmp/sermon_debug.log', "Sermon link: " . ($link ?: 'NULL') . "\n", FILE_APPEND);
            if (empty($link)) {
                file_put_contents('/tmp/sermon_debug.log', "No link, skipping\n", FILE_APPEND);
                continue;
            }

            $alreadyPosted = $this->sermonAlreadyPosted($feed, $link);
            file_put_contents('/tmp/sermon_debug.log', "Already posted: " . ($alreadyPosted ? 'YES' : 'NO') . "\n", FILE_APPEND);
            $forcePost = Yii::$app->request->get('force') == '1';
            if ($alreadyPosted && !$forcePost) {
                file_put_contents('/tmp/sermon_debug.log', "Already posted, skipping\n", FILE_APPEND);
                continue;
            }
            if ($forcePost && $alreadyPosted) {
                file_put_contents('/tmp/sermon_debug.log', "Already posted, but force=1, posting anyway\n", FILE_APPEND);
            }

            $newSermons[] = $sermon;
        }

        file_put_contents('/tmp/sermon_debug.log', "New sermons to post: " . count($newSermons) . "\n", FILE_APPEND);
        if (empty($newSermons)) {
            file_put_contents('/tmp/sermon_debug.log', "No new sermons\n", FILE_APPEND);
            Yii::$app->session->setFlash('info', 'All recent sermons have already been posted.');
            $feed->last_check = time();
            $feed->last_sermon_guid = (string) ($sermons[0]['sermonID'] ?? $feed->last_sermon_guid);
            $feed->save(false);
            return $this->htmlRedirect($space->createUrl('/sermonaudio/config-container'));
        }

        $newSermons = array_reverse($newSermons);
        $postedCount = 0;
        foreach ($newSermons as $sermon) {
            file_put_contents('/tmp/sermon_debug.log', "Creating post for sermon: " . ($sermon['sermonID'] ?? 'UNKNOWN') . "\n", FILE_APPEND);
            if ($this->createSermonPostFromApi($feed, $sermon)) {
                $postedCount++;
                file_put_contents('/tmp/sermon_debug.log', "Post created successfully\n", FILE_APPEND);
            } else {
                file_put_contents('/tmp/sermon_debug.log', "Post creation failed\n", FILE_APPEND);
            }
        }

        $feed->last_sermon_guid = (string) ($sermons[0]['sermonID'] ?? $feed->last_sermon_guid);
        $feed->last_check = time();
        $feed->save(false);

        if ($postedCount === 0) {
            Yii::$app->session->setFlash('info', 'All recent sermons have already been posted.');
        } elseif ($postedCount === 1) {
            Yii::$app->session->setFlash('success', 'New sermon post created successfully!');
        } else {
            Yii::$app->session->setFlash('success', "{$postedCount} new sermon posts created successfully!");
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
        
        $exists = (new \yii\db\Query())
            ->from('{{%post}} p')
            ->innerJoin('{{%content}} c', 'c.object_id = p.id AND c.object_model = :model', [':model' => Post::class])
            ->where(['c.contentcontainer_id' => $space->contentcontainer_id])
            ->andWhere(['or', ['like', 'p.message', $link], ['like', 'p.message', $oembedLink]])
            ->andWhere(['IS NOT', 'p.message', null])  // Exclude orphaned posts with NULL message
            ->exists();
        
        file_put_contents('/tmp/sermon_debug.log', "sermonAlreadyPosted check for link: {$link}\n", FILE_APPEND);
        file_put_contents('/tmp/sermon_debug.log', "  Space ID: {$space->contentcontainer_id}\n", FILE_APPEND);
        file_put_contents('/tmp/sermon_debug.log', "  Result: " . ($exists ? 'YES' : 'NO') . "\n", FILE_APPEND);
        
        return $exists;
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

    protected function fetchApiSermons(Feed $feed, string $apiKey, int $limit): array
    {
        file_put_contents('/tmp/sermon_debug.log', "=== fetchApiSermons START ===\n", FILE_APPEND);
        $broadcasterId = $feed->getBroadcasterId();
        file_put_contents('/tmp/sermon_debug.log', "Broadcaster ID: " . ($broadcasterId ?: 'NULL') . "\n", FILE_APPEND);
        if (empty($broadcasterId)) {
            file_put_contents('/tmp/sermon_debug.log', "No broadcaster ID, returning empty\n", FILE_APPEND);
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
        file_put_contents('/tmp/sermon_debug.log', "API URL: {$url}\n", FILE_APPEND);
        
        $response = $this->apiGet($url, $apiKey);
        file_put_contents('/tmp/sermon_debug.log', "API response length: " . strlen($response ?: '') . "\n", FILE_APPEND);
        if (empty($response)) {
            file_put_contents('/tmp/sermon_debug.log', "Empty response from API\n", FILE_APPEND);
            return [];
        }

        file_put_contents('/tmp/sermon_debug.log', "API Response (first 200): " . substr($response, 0, 200) . "\n", FILE_APPEND);
        file_put_contents('/tmp/sermon_full_response.json', $response);
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            file_put_contents('/tmp/sermon_debug.log', "Invalid JSON\n", FILE_APPEND);
            return [];
        }

        if (isset($data['errors'])) {
            file_put_contents('/tmp/sermon_debug.log', "API error: " . json_encode($data['errors']) . "\n", FILE_APPEND);
            return [];
        }

        $results = (isset($data['results']) && is_array($data['results'])) ? $data['results'] : [];
        file_put_contents('/tmp/sermon_debug.log', "Found " . count($results) . " sermons\n", FILE_APPEND);
        return $results;
    }

    protected function apiGet(string $url, string $apiKey): ?string
    {
        file_put_contents('/tmp/sermon_debug.log', "=== apiGet START ===\n", FILE_APPEND);
        file_put_contents('/tmp/sermon_debug.log', "URL: " . substr($url, 0, 100) . "...\n", FILE_APPEND);
        if (function_exists('curl_init')) {
            file_put_contents('/tmp/sermon_debug.log', "Using curl\n", FILE_APPEND);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $apiKey],
            ]);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            file_put_contents('/tmp/sermon_debug.log', "curl status: {$status}\n", FILE_APPEND);

            if ($result === false) {
                file_put_contents('/tmp/sermon_debug.log', 'curl_exec failed: ' . curl_error($ch) . "\n", FILE_APPEND);
                curl_close($ch);
                return null;
            }

            curl_close($ch);

            if ($status >= 400) {
                file_put_contents('/tmp/sermon_debug.log', "API error status {$status}: " . substr($result, 0, 200) . "\n", FILE_APPEND);
                return null;
            }

            file_put_contents('/tmp/sermon_debug.log', "curl success, response length: " . strlen($result) . "\n", FILE_APPEND);
            return $result;
        }

        file_put_contents('/tmp/sermon_debug.log', "curl not available, using file_get_contents\n", FILE_APPEND);
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

    /**
     * Create sermon post
     */
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
                // Publish the post by setting content state to 1
                $content = \humhub\modules\content\models\Content::findOne(['object_model' => Post::class, 'object_id' => $post->id]);
                if ($content) {
                    $content->state = 1;
                    $content->save(false);
                }
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
        file_put_contents('/tmp/sermon_debug.log', "=== createSermonPostFromApi START ===\n", FILE_APPEND);
        $space = $feed->space;

        if (!$space) {
            file_put_contents('/tmp/sermon_debug.log', "No space\n", FILE_APPEND);
            return false;
        }

        $user = $this->resolvePostUser($feed);
        file_put_contents('/tmp/sermon_debug.log', "Post user: " . ($user ? $user->username : 'NULL') . "\n", FILE_APPEND);
        if (!$user) {
            file_put_contents('/tmp/sermon_debug.log', "No user found\n", FILE_APPEND);
            return false;
        }

        $title = $sermon['displayTitle'] ?? $sermon['fullTitle'] ?? $sermon['title'] ?? 'Sermon';
        $link = $this->getApiSermonLink($sermon);
        file_put_contents('/tmp/sermon_debug.log', "Title: {$title}, Link: {$link}\n", FILE_APPEND);
        if (empty($link)) {
            file_put_contents('/tmp/sermon_debug.log', "No link\n", FILE_APPEND);
            return false;
        }

        if ($this->sermonAlreadyPosted($feed, $link)) {
            file_put_contents('/tmp/sermon_debug.log', "Already posted\n", FILE_APPEND);
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
                // Publish the post by setting content state to 1
                $content = \humhub\modules\content\models\Content::findOne(['object_model' => Post::class, 'object_id' => $post->id]);
                if ($content) {
                    $content->state = 1;
                    $content->save(false);
                }
                file_put_contents('/tmp/sermon_debug.log', "Post saved successfully and published\n", FILE_APPEND);
                Yii::info("Created API sermon post: {$title} in space {$space->name} as user {$user->username}", 'sermonaudio');
                return true;
            }

            file_put_contents('/tmp/sermon_debug.log', "Post save failed: " . json_encode($post->errors) . "\n", FILE_APPEND);
            Yii::error("Failed to create API sermon post: " . print_r($post->errors, true), 'sermonaudio');
            return false;
        } finally {
            file_put_contents('/tmp/sermon_debug.log', "=== createSermonPostFromApi END ===\n", FILE_APPEND);
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
