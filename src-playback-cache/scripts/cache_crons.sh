#!/bin/bash
# Playback Cache - unRAID plugin

################################
source /boot/config/plugins/playback-cache/playback-cache.cfg

################################
### one helper for both cron jobs, the settings page calls it on every Apply
### "clear" removes both jobs whatever the settings say
configfile="/boot/config/plugins/playback-cache/playback-cache.cfg"
plugindir="/usr/local/emhttp/plugins/playback-cache/scripts"
copyjob="$plugindir/playback_cache_copy"
cleanjob="$plugindir/playback_cache_clean"
ownlines="playback-cache/scripts/playback_cache|cache-mover/scripts/cache_mover|# Cron Job for Cache_Mover|# Cron Job for Cache_Cleaner"
cronfile="/boot/config/plugins/playback-cache/playback-cache.cron"
tmptab="/var/run/playback-cache.crontab.tmp"

if [ "$1" == "clear" ]; then
	copy_cron=""
	clean_cron=""
	### the settings page writes the form first, so blanking the schedules here sticks
	sed -i 's/^copy_cron=.*/copy_cron=""/; s/^clean_cron=.*/clean_cron=""/' "$configfile"
fi

### a bad line in the crontab could take the other entries down with it, so every field is
### checked: plain cron characters, the right count and values inside the range,
### "99 * * * *" is not a schedule
valid_field() { # $1 field, $2 min, $3 max, $4 optional name list like "jan|feb|..."
	local item base step lo hi
	local -a items
	IFS=',' read -r -a items <<< "$1"
	[ "${#items[@]}" -gt 0 ] || return 1
	for item in "${items[@]}"
		do
		base="$item"
		step=1
		case "$item" in
			*/[0-9]*)	base="${item%%/*}"; step="${item##*/}"
					[ "$step" -ge 1 ] 2> /dev/null || return 1 ;;
			*/*)		return 1 ;;
		esac
		case "$base" in
			\*)		;;
			[0-9]*-[0-9]*)	lo="${base%%-*}"; hi="${base##*-}"
					[ "$lo" -ge "$2" ] 2> /dev/null && [ "$hi" -le "$3" ] 2> /dev/null && [ "$lo" -le "$hi" ] 2> /dev/null || return 1 ;;
			[0-9]*)		[ "$base" -ge "$2" ] 2> /dev/null && [ "$base" -le "$3" ] 2> /dev/null || return 1 ;;
			*-*-*|*-*-*)	return 1 ;;
			*-*)		if [ ! -z "$4" ]; then
							lo="${base%%-*}"; hi="${base##*-}"
							printf '%s' "$lo" | grep -qiE "^($4)$" || return 1
							printf '%s' "$hi" | grep -qiE "^($4)$" || return 1
						else
							return 1
						fi ;;
			*)		if [ ! -z "$4" ]; then
						printf '%s' "$base" | grep -qiE "^($4)$" || return 1
					else
						return 1
					fi ;;
		esac
		done
	return 0
}
valid_cron() { # $1 schedule, the five usual fields
	local -a f
	case "$1" in
		*$'\n'*)	return 1 ;;
	esac
	read -r -a f <<< "$1"
	[ "${#f[@]}" -eq 5 ] || return 1
	valid_field "${f[0]}" 0 59 || return 1
	valid_field "${f[1]}" 0 23 || return 1
	valid_field "${f[2]}" 1 31 || return 1
	valid_field "${f[3]}" 1 12 "jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec" || return 1
	valid_field "${f[4]}" 0 7 "sun|mon|tue|wed|thu|fri|sat" || return 1
	return 0
}

install_copy="$copy_cron"
install_clean="$clean_cron"
if [ ! -z "$install_copy" ] && ! valid_cron "$install_copy"; then
	echo "copy schedule '$copy_cron' is not a valid 5 field cron expression, nothing was installed"
	install_copy=""
fi
if [ ! -z "$install_clean" ] && ! valid_cron "$install_clean"; then
	echo "cleaner schedule '$clean_cron' is not a valid 5 field cron expression, nothing was installed"
	install_clean=""
fi

### root's crontab is the one store: rebuild it without our old lines and put the current
### schedules in. The crontab is rebuilt at every boot, the plg runs this script again at
### boot to put the schedules back from the saved settings, nothing lives on the flash.
rm -f "$cronfile" 2> /dev/null
{
	crontab -l 2> /dev/null | grep -vE "$ownlines"
	[ ! -z "$install_copy" ] && printf '%s %s >> /var/log/playback-cache.log 2>&1\n' "$install_copy" "$copyjob"
	[ ! -z "$install_clean" ] && printf '%s %s >> /var/log/playback-cache.log 2>&1\n' "$install_clean" "$cleanjob"
	true
} > "$tmptab"
if crontab "$tmptab" 2> /dev/null; then
	cron_ok=1
else
	cron_ok=0
	echo "crontab refused the schedules, nothing was changed"
fi
rm -f "$tmptab" 2> /dev/null

### keep the state values in the settings file truthful
if [ -z "$install_copy" ]; then
	sed -i 's/^copy_cron_state=.*/copy_cron_state="stopped"/' "$configfile"
	echo "copy cron removed"
elif [ $cron_ok -eq 1 ]; then
	sed -i 's/^copy_cron_state=.*/copy_cron_state="started"/' "$configfile"
	echo "copy cron set to: $install_copy $copyjob"
else
	sed -i 's/^copy_cron_state=.*/copy_cron_state="stopped"/' "$configfile"
fi
if [ -z "$install_clean" ]; then
	sed -i 's/^clean_cron_state=.*/clean_cron_state="stopped"/' "$configfile"
	echo "cleaner cron removed"
elif [ $cron_ok -eq 1 ]; then
	sed -i 's/^clean_cron_state=.*/clean_cron_state="started"/' "$configfile"
	echo "cleaner cron set to: $install_clean $cleanjob"
else
	sed -i 's/^clean_cron_state=.*/clean_cron_state="stopped"/' "$configfile"
fi

exit;
