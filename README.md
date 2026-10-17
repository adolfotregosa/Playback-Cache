# Playback Cache

unRAID plugin that keeps media on a cache pool while it plays, so the array disks can spin down.

## What it does

- Watches what your media server (**Jellyfin**, **Emby** or **Plex**) is playing through its API.
- Copies the playing file or folder from the array onto your cache pool.
- Switches the media server's open file handles to the cache copy while playback continues, so the array disks can finally spin down.
- Keeps cached folders on the mover exclusion list, so the mover does not undo the work. Requires CA Mover Tuning. 
- A scheduled cleaner releases folders that have been idle long enough: files with an identical copy on the array are removed from the cache, everything else stays for the mover.

Everything runs on your own schedule (copy every N minutes, clean daily/weekly), can be started by hand from the webui, and is logged to a quiet plugin log with a live status strip and cancel button.

## Install
Paste the following on the Install Plugin URL:
```
https://raw.githubusercontent.com/adolfotregosa/Playback-Cache/main/playback-cache.plg
```

Then find **Playback Cache** under Settings / Utilities, fill in your media server (type, address, port, API key) and the folders to cache, and press **APPLY ALL**.

## AI assistance

I'm not a developer. This plugin was developed with the help of AI, used for
code review, debugging and writing parts of the scripts and webui. Every change was tested
by me on my own unRAID server before release. Bug reports are welcome in the
[issues](https://github.com/adolfotregosa/Playback-Cache/issues).

## Credits

Based on [Cache Mover](https://github.com/alturismo/unraid-cache-mover) by [alturismo](https://github.com/alturismo) — thanks for the original plugin and the handle switching idea.
