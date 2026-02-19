# SermonAudio RSS Feeds

Automatically posts new sermons from SermonAudio RSS feeds into HumHub spaces.

## Features
- Import audio or video feeds from SermonAudio
- Schedule checks (hourly, daily, weekly, or 15/30-minute via cron)
- Post as a selected space member
- Configurable post template with variables
- Dark-mode friendly configuration UI

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
By default, feeds are checked hourly by HumHub cron. For 15/30-minute checks, add one of the following to your server crontab:

```bash
*/15 * * * * /var/www/humhub/protected/modules/sermonaudio/check-sermons.sh >> /var/log/sermonaudio.log 2>&1
*/30 * * * * /var/www/humhub/protected/modules/sermonaudio/check-sermons.sh >> /var/log/sermonaudio.log 2>&1
```

## Notes
- Links are posted using HumHub oEmbed for rich previews.

## License
MIT License. Provided as-is, without warranty.
