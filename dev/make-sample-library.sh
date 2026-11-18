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
printf 'admin: richard\nftp: richard bob\nstandard: alice bob richard\n' > htgroup

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
EOF
