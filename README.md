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

### Option A: Install from GitHub Release (recommended)
1. Download the latest release ZIP from the GitHub Releases page.
2. Extract it to `protected/modules/sermonaudio`.
3. Ensure the module folder name is exactly `sermonaudio`.
4. Enable the module in **Administration -> Modules**.
5. Enable it per space in **Space -> Modules**.

### Option B: Install from the Repository
1. Clone the repository into `protected/modules/sermonaudio`:
	- `git clone https://github.com/BarbellDwarf/SermonAudio-RSS-Feeds.git sermonaudio`
2. Enable the module in **Administration -> Modules**.
3. Enable it per space in **Space -> Modules**.

## Updating
After updating the module, run its migrations from the HumHub `protected` folder:

```
cd /var/www/humhub/protected
php yii migrate/up --migrationPath=@app/modules/sermonaudio/migrations
```

Then clear the cache in **Administration -> Settings -> Advanced -> Clear cache**.

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

**Warning:** Debug mode should only be used for development/testing. Do not enable in production environments.

## Notes
- Links are posted using HumHub oEmbed for rich previews.

## License
MIT License. Provided as-is, without warranty.
