#!/bin/bash
# Playback Cache - unRAID plugin

################################
source /boot/config/plugins/playback-cache/playback-cache.cfg

################################
### the Cached tab passes the ticked lines as arguments, DELETE means release now:
### the cleaner runs its per-file logic on just that folder, files that have an
### identical copy on the array are removed from the cache, everything else stays,
### the folder is dropped from the list and its keep stamp is forgotten,
### the details land in the plugin log, the folder is never deleted as a whole
plugindir="/usr/local/emhttp/plugins/playback-cache/scripts"
if [ "$#" -eq 0 ]; then
	echo "no folder selected, nothing removed"
	exit 0
fi
rc=0
for folder in "$@"
	do
	if [ -z "$folder" ]; then
		continue
	fi
	echo "releasing $folder, see the Log tab for details"
	"$plugindir/playback_cache_clean" folder "$folder" || rc=1
	done
exit $rc
