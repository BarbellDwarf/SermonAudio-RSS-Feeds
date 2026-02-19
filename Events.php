<?php
namespace app\modules\sermonaudio;

use humhub\events\OembedFetchEvent;
use app\modules\sermonaudio\jobs\FetchSermonsJob;
use Yii;

class Events
{
    public static function onUrlOembedFetch($event)
    {
        $url = $event->url;
        
        // Detect if dark mode is enabled
        $isDarkMode = self::isDarkMode();
        $darkParam = $isDarkMode ? '?dark=true' : '';
        
        // Handle broadcaster browser page
        if (preg_match('~sermonaudio\.com/broadcaster/([^/?]+)~', $url, $matches)) {
            $broadcaster = $matches[1];
            
            // Parse URL to extract query parameters
            $parsedUrl = parse_url($url);
            parse_str($parsedUrl['query'] ?? '', $queryParams);
            
            // Set default parameters if not present
            if (!isset($queryParams['sort'])) {
                $queryParams['sort'] = 'newest';
            }
            if (!isset($queryParams['page_size'])) {
                $queryParams['page_size'] = '25';
            }
            
            // Add dark mode parameter
            $queryParams['dark'] = $isDarkMode ? 'true' : 'false';
            
            $queryString = '?' . http_build_query($queryParams);
            
            // Build embed URL
            $embedUrl = 'https://embed.sermonaudio.com/browser/broadcaster/' . htmlspecialchars($broadcaster, ENT_QUOTES, 'UTF-8') . '/' . $queryString;
            
            // Build the HTML directly
            $html = '<div data-guid="' . uniqid('oembed-', true) . '" data-richtext-feature="1" data-oembed-provider="sermonaudio-browser" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="oembed_snippet">';
            $html .= '<iframe tabindex="-1" width="1" height="540" src="' . $embedUrl . '" style="min-width: 100%; max-width: 100%;" allow="autoplay" frameborder="0" scrolling="no"></iframe>';
            $html .= '</div>';
            
            // Set the result directly, bypassing the provider endpoint logic
            $event->setResult($html);
            return;
        }
        
        // Handle single sermon - new format: /sermons/{id}
        if (preg_match('~sermonaudio\.com/sermons/([0-9]+)~i', $url, $matches)) {
            $sermonId = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            
            // Build the HTML directly with responsive 16:9 container
            $html = '<div data-guid="' . uniqid('oembed-', true) . '" data-richtext-feature="1" data-oembed-provider="sermonaudio-sermon" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="oembed_snippet" style="margin: 0; padding: 0;">';
            $html .= '<div style="position:relative;width:100%;height:0;padding-bottom:56.25%;margin:0;">';
            $html .= '<iframe tabindex="-1" width="100%" height="100%" src="https://embed.sermonaudio.com/player/v/' . $sermonId . '/' . $darkParam . '" style="position:absolute;left:0;top:0;border:0;display:block;" allowfullscreen frameborder="0" scrolling="no"></iframe>';
            $html .= '</div>';
            $html .= '</div>';
            
            // Set the result directly, bypassing the provider endpoint logic
            $event->setResult($html);
            return;
        }
        
        // Handle single sermon - old format: /sermoninfo.asp?SID=
        if (preg_match('~sermonaudio\.com/sermoninfo\.asp\?SID=([a-z0-9]+)~i', $url, $matches)) {
            $sermonId = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            
            // Build the HTML directly with responsive 16:9 container
            $html = '<div data-guid="' . uniqid('oembed-', true) . '" data-richtext-feature="1" data-oembed-provider="sermonaudio-sermon" data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="oembed_snippet" style="margin: 0; padding: 0;">';
            $html .= '<div style="position:relative;width:100%;height:0;padding-bottom:56.25%;margin:0;">';
            $html .= '<iframe tabindex="-1" width="100%" height="100%" src="https://embed.sermonaudio.com/player/v/' . $sermonId . '/' . $darkParam . '" style="position:absolute;left:0;top:0;border:0;display:block;" allowfullscreen frameborder="0" scrolling="no"></iframe>';
            $html .= '</div>';
            $html .= '</div>';
            
            // Set the result directly, bypassing the provider endpoint logic
            $event->setResult($html);
            return;
        }
    }
    
    /**
     * Cron event handler - runs hourly
     */
    public static function onCronRun($event)
    {
        Yii::$app->queue->push(new FetchSermonsJob());
    }
    
    /**
     * Detect if the current theme is dark mode
     * @return bool
     */
    private static function isDarkMode()
    {
        // Try to detect from theme name
        if (isset(Yii::$app->view->theme) && Yii::$app->view->theme->name) {
            $themeName = strtolower(Yii::$app->view->theme->name);
            if (strpos($themeName, 'dark') !== false) {
                return true;
            }
        }
        
        // Try to detect from theme variables
        if (isset(Yii::$app->view->theme)) {
            try {
                // Check if the theme has a dark mode variable
                $isDark = Yii::$app->view->theme->variable('dark', false);
                if ($isDark) {
                    return true;
                }
            } catch (\Exception $e) {
                // Variable doesn't exist, continue
            }
        }
        
        // Default to light mode
        return false;
    }
}
