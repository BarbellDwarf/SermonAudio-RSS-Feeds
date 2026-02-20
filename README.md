# SermonAudio RSS Feeds

Automatically posts new sermons from SermonAudio RSS feeds into HumHub spaces.

## Features
- Import audio or video feeds from SermonAudio
- Schedule checks (hourly, daily, weekly)
- Post as a selected space member
- Configurable post template with variables
- Dark-mode friendly configuration UI
- Optional API integration for enhanced event filtering

## Installation
1. Place this module in `protected/modules/sermonaudio`.
2. Enable the module in **Administration -> Modules**.
3. Enable it per space in **Space -> Modules**.

## Configuration
Open **Space -> Modules -> SermonAudio RSS Feeds -> Configure** and add a feed.

Feed URL examples:
- Audio: `https://feed.sermonaudio.com/broadcasters/{broadcaster_name}`
- Video: `https://feed.sermonaudio.com/broadcasters/{broadcaster_name}?video=true`

## Post Template Variables
You can customize the post content with:
- `{channel}` - Channel name
- `{type}` - "video" or "sermon"
- `{title}` - Sermon title
- `{title_link}` - Sermon title linked to the sermon
- `{speaker}` - Speaker name (line removed if empty)
- `{series}` - Series name (line removed if empty)
- `{link}` - Sermon URL

## Cron Scheduling
By default, feeds are checked hourly by HumHub cron.

### Debug Mode (Testing Only)
For development and testing, you can enable debug mode to run checks at shorter intervals (every 15 minutes).

**To enable debug mode:**
1. Go to **Administration -> Modules -> SermonAudio Settings**
2. Check the "Enable debug mode (testing only)" checkbox
3. Save the settings
4. Add this cron job to your server:
```bash
*/15 * * * * /var/www/humhub/protected/modules/sermonaudio/check-sermons.sh >> /var/log/sermonaudio.log 2>&1
```

**Warning:** Debug mode should only be used for development/testing. Do not enable in production environments.

## Notes
- Links are posted using HumHub oEmbed for rich previews.

## License
MIT License. Provided as-is, without warranty.
