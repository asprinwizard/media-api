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
MEDIA_COPY_QUEUE=$ROOT/public/admin/file_copy_queue
EOF
