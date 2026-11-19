#!/bin/sh
# Builds a small fake media tree for local development under storage/dev/,
# shaped like media02 (/home/share/Video with symlinks onto drive mounts).
# Files are empty placeholders. Safe to re-run: it rebuilds from scratch.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)/storage/dev"
rm -rf "$ROOT"
mkdir -p "$ROOT"
cd "$ROOT"

mkdir -p drives/hd01/Drama drives/hd02/Movies
mkdir -p share/Video/TV share/Video/"Torrent Downloads" share/Video/"Uploaded Files"/get_iplayer share/Video/"Uploaded Files"/youtube
ln -s "$ROOT/drives/hd02/Movies" share/Video/Movies
ln -s "$ROOT/drives/hd01/Drama" share/Video/TV/Drama
mkdir -p share/Video/TV/Comedy share/Video/TV/Documentary

for film in "The Lantern Keeper (2019)" "A Quiet Harbour (2021)" "Northern Line (2016)" "Ember (2023)"; do
    mkdir -p "drives/hd02/Movies/$film"
    : > "drives/hd02/Movies/$film/$film.mp4"
done

show="drives/hd01/Drama/Harbour Lights"
mkdir -p "$show/Series 1 (2023)" "$show/Series 2 (2025)"
: > "$show/Series 1 (2023)/S01E01 - Pilot.mp4"
: > "$show/Series 1 (2023)/S01E02 - Low Water.mp4"
: > "$show/Series 2 (2025)/Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4"
: > "$show/Series 2 (2025)/Harbour_Lights____s02e05_-_Undertow---m002x4kq.srt"
: > "$show/Harbour Lights - S01E01 - Pilot (SD).avi"
: > "$show/Harbour Lights - Main Theme.m4a"
: > "$show/Harbour.Lights.S02.Extras.zip"
# Awkward names, to exercise quoting and URL encoding
: > "$show/It's \"quoted\" #1 & 50% + more.mp4"

mkdir -p upload/get_iplayer upload/youtube
: > upload/wedding-ceremony.mp4

mkdir -p public/admin public/logs private/search logs
# Big alpha folder for the A–Z rail and type-to-jump
for t in "Arrival Point" "Brass Harbour" "Cold Meridian" "Dune Road" "The Echo Chamber" "Fallow" "A Glass Garden" \
         "Hollow Pines" "Iron Bay" "Juniper" "Kestrel" "Low Tide" "Marrow" "Night Ferry" "Orchard Lane" "Paper Moon" \
         "Quarry" "Riverside" "Salt Marsh" "Tin Coast" "Umber" "Vale" "Westerly" "Yarrow" "Zephyr" "1984 Revisited"; do
    mkdir -p "drives/hd02/Movies/$t"
    : > "drives/hd02/Movies/$t/$t.mp4"
done
# Search index: one library-relative path per line (like media02's movies.db / tv.db)
(cd share/Video && find -L Movies -mindepth 0 | sort) > private/search/movies.db
(cd share/Video && find -L TV -mindepth 0 | sort) > private/search/tv.db
# Home page log panels
for n in 1 2 3; do echo "2026/11/18 21:0$n:12 [1234] >f+++++++++ TV/Drama/Harbour Lights/Series 2 (2025)/S02E0$n.mp4"; done > logs/media01-tv.log
echo "2026/11/18 18:12:40 [999] >f+++++++++ Movies/The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4" > logs/media01-movies.log
ln -s "$ROOT/drives/hd02/Movies/Kestrel/missing.mp4" "drives/hd02/Movies/Kestrel/gone.mp4"

# A fake media01 (a folder) and a fake ssh22 that runs commands against it,
# exactly as ssh + the remote shell would. Plus admin bits for Phase 3.
mkdir -p media01/Video/Movies media01/Video/TV/Drama/"Harbour Lights"/"Series 1 (2023)" media01/Video/TV/Comedy bin public/admin
: > media01/Video/TV/Drama/"Harbour Lights"/"Series 1 (2023)"/"S01E01 - Pilot.mp4"
printf '#!/bin/sh\nshift\nexec sh -c "$*"\n' > bin/ssh22 && chmod +x bin/ssh22
mkdir -p "share/Video/Uploaded Files/get_iplayer"
for f in "Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4" "Harbour_Lights____s02e06_-_The_Tide_Turns---m002x4kr.mp4" "Wild_Isles____s01e03_-_Cliffs---m001xq2c.mp4"; do
    : > "share/Video/Uploaded Files/get_iplayer/$f"
done
(cd "share/Video/Uploaded Files/get_iplayer" && printf 'hello' > readme.txt && tar -czf extras.tar.gz readme.txt && rm readme.txt)
printf 'admin: richard\nftp: richard bob\nstandard: alice bob richard\n' > htgroup

# Phase 4: stand-ins for get-iplayer and yt-dlp that behave like the real
# ones (output format, file names, gradual progress), plus some downloads
# already in the get_iplayer folder at different stages.
mkdir -p public/get_iplayer logs/get_iplayer logs/yt-dlp private/youtube
dl="$ROOT/upload/get_iplayer"
dd if=/dev/zero of="$dl/Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4" bs=1024 count=900 2>/dev/null
dd if=/dev/zero of="$dl/Harbour_Lights____s02e05_-_Undertow_(SD)---m002x4kq.video.m4v" bs=1024 count=120 2>/dev/null
dd if=/dev/zero of="$dl/Wild_Isles____s01e04_-_Moorland---m001xq2d.partial.mp4" bs=1024 count=300 2>/dev/null
touch -d '3 hours ago' "$dl/Wild_Isles____s01e04_-_Moorland---m001xq2d.partial.mp4"
mkdir -p public/get_iplayer/m002x4kq
printf 'pid:             m002x4kq\nqualitysizes:    original: hd=1MB,sd=500kB\ntitle:           Harbour Lights: Series 2 - 5. Undertow\n' > public/get_iplayer/m002x4kq/fileinfo

cat > bin/get-iplayer <<SH
#!/bin/sh
# Fake get-iplayer for development.
DL="$dl"
case "\$1" in
  --)
    sleep 1
    cat <<OUT
get_iplayer v3.35, Copyright (C) 2008-2010 Phil Lewis

Matches:
OUT
    case "\$2" in
      *news*) printf '3001:\tBBC News at Six: 19/11/2026, BBC One, m00n0001\n3002:\tBBC News at Ten: 19/11/2026, BBC One, m00n0002\n' ;;
      *weather*) printf '4001:\tWeather for the Week Ahead: 19/11/2026, BBC One, m00w0001\n' ;;
      *) printf '1201:\tWild Isles: Series 1 - 3. Cliffs, BBC Two, m001xq2c\n1202:\tWild Isles: Series 1 - 4. Moorland, BBC Two, m001xq2d\n1203:\tWild Isles: Series 1 - 5. Estuaries, BBC Two, m001xq2f\n1204:\tWild Isles of the North: Special, BBC Four, m001xq2g\n' ;;
    esac
    printf '\nINFO: matching programmes\n'
    ;;
  --info)
    pid="\$3"
    printf 'pid:             %s\nqualitysizes:    original: hd=2MB,sd=1MB\ntitle:           Wild Isles: Series 1 - %s\n' "\$pid" "\$pid"
    ;;
  --pid)
    sd=""; case "\$*" in *"(SD)"*) sd="_(SD)";; esac
    for pid in \$(echo "\$2" | tr ',' ' '); do
      base="\$DL/Wild_Isles____s01e05_-_Episode\${sd}---\$pid"
      echo "INFO: Downloading \$pid"
      for i in 1 2 3 4 5 6 7 8; do dd if=/dev/zero bs=1024 count=200 2>/dev/null >> "\$base.video.m4v"; sleep 1; done
      dd if=/dev/zero of="\$base.audio.m4a" bs=1024 count=100 2>/dev/null; sleep 2
      mv "\$base.video.m4v" "\$base.partial.mp4"; rm -f "\$base.audio.m4a"; sleep 3
      mv "\$base.partial.mp4" "\$base.mp4"
      echo "INFO: Recorded \$base.mp4"
    done
    ;;
esac
SH
chmod +x bin/get-iplayer

cat > bin/yt-dlp <<'SH'
#!/bin/sh
# Fake yt-dlp for development.
if [ "$1" = "-J" ]; then
  sleep 1
  cat <<'JSON'
{"id":"dQw4w9WgXcQ","title":"Sample Video","channel":"Sample Channel","duration":212,"thumbnail":"",
 "formats":[
  {"format_id":"140","ext":"m4a","vcodec":"none","acodec":"mp4a.40.2","tbr":129.5,"filesize":3433148,"format_note":"medium"},
  {"format_id":"251","ext":"webm","vcodec":"none","acodec":"opus","tbr":135.1,"filesize_approx":3500000,"format_note":"medium"},
  {"format_id":"18","ext":"mp4","vcodec":"avc1.42001E","acodec":"mp4a.40.2","height":360,"resolution":"640x360","fps":25,"tbr":500,"filesize":13000000,"format_note":"360p"},
  {"format_id":"137","ext":"mp4","vcodec":"avc1.640028","acodec":"none","height":1080,"resolution":"1920x1080","fps":25,"tbr":4400,"filesize":110000000,"format_note":"1080p"},
  {"format_id":"136","ext":"mp4","vcodec":"avc1.4d401f","acodec":"none","height":720,"resolution":"1280x720","fps":25,"tbr":2300,"format_note":"720p"}
 ]}
JSON
  exit 0
fi
out=""; while [ $# -gt 0 ]; do [ "$1" = "-o" ] && out="$2"; shift; done
dir=$(dirname "$out" | sed 's/%(channel)s/Sample Channel/'); mkdir -p "$dir"
echo "[youtube] dQw4w9WgXcQ: Downloading webpage"
echo "[download] Destination: $dir/Sample Video.mp4"
for p in 5.0 18.2 33.9 51.4 67.0 82.5 96.1 100; do echo "[download]  $p% of   10.00MiB at  2.00MiB/s ETA 00:04"; sleep 1; done
: > "$dir/Sample Video.mp4"
SH
chmod +x bin/yt-dlp

# Phase 5: a pretend Live TV box (wakeonlan "boots" it: a listener on port
# 8765 appears after 6 seconds), a harmless ssh for its power actions, and
# an hdparm that reports drive states.
cat > bin/wakeonlan <<SH
#!/bin/sh
echo "Sending magic packet to 255.255.255.255:9 with \$1"
( sleep 6; exec timeout 900 php -S 127.0.0.1:8765 -t "$ROOT" >/dev/null 2>&1 ) </dev/null >/dev/null 2>&1 &
SH
chmod +x bin/wakeonlan
cat > bin/ssh-livetv <<SH
#!/bin/sh
echo "\$(date) ssh \$*" >> "$ROOT/logs/livetv-ssh.log"
case "\$*" in *shutdown*|*suspend*|*reboot*) pkill -f 'php -S 127.0.0.1:876[5]' ;; esac
case "\$*" in *reboot*) "$ROOT/bin/wakeonlan" x ;; esac
exit 0
SH
chmod +x bin/ssh-livetv
cat > bin/hdparm <<'SH'
#!/bin/sh
case "$2" in */sda) s="active/idle";; */sdb) s="standby";; *) s="sleeping";; esac
printf '\n%s:\n drive state is:  %s\n' "$2" "$s"
SH
chmod +x bin/hdparm
mkdir -p logs/get_iplayer
for i in $(seq 1 40); do echo "2026/11/19 18:$(printf %02d $i):00 copy_file_queue: line $i"; done > logs/copy_file_queue.log

echo "Sample library created in $ROOT"
echo "Point .env at it with:"
cat <<EOF
MEDIA_HTGROUP_FILE=$ROOT/htgroup
MEDIA_SHARE_PATH=$ROOT/share
MEDIA_LIBRARY_PATH=$ROOT/share/Video
MEDIA_UPLOAD_PATH=$ROOT/upload
MEDIA_PUBLIC_PATH=$ROOT/public
MEDIA_PRIVATE_PATH=$ROOT/private
MEDIA_LOG_DIR=$ROOT/logs
MEDIA_ALLOWED_MOUNTS=$ROOT/drives/hd01,$ROOT/drives/hd02
MEDIA_SEARCH_INDEX=$ROOT/private/search
MEDIA_CAST_PATH=$ROOT/public-cast
MEDIA01_SSH=$ROOT/bin/ssh22
MEDIA01_ROOT=$ROOT/media01/Video
MEDIA_IPLAYER_COMMAND=$ROOT/bin/get-iplayer
MEDIA_YTDLP=$ROOT/bin/yt-dlp
LIVE_TV_HOST=127.0.0.1
LIVE_TV_CHECK_PORT=8765
LIVE_TV_SSH=$ROOT/bin/ssh-livetv
STREAMER_MAC_ADDRESS=aa:bb:cc:dd:ee:ff
MEDIA_WAKEONLAN=$ROOT/bin/wakeonlan
MEDIA_HDPARM=$ROOT/bin/hdparm
MEDIA_HDPARM_SUDO=false
MEDIA01_HDPARM=$ROOT/bin/hdparm
MEDIA_COPY_QUEUE=$ROOT/public/admin/file_copy_queue
EOF
