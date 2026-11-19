#!/usr/bin/env bash
#
# media-adduser — add a Media02 user, or change an existing user's password
# and groups.
#
# Writes the shared Basic-auth files used by both the old and new sites:
#   /etc/apache2/media-auth/.htpasswd   (password, bcrypt)
#   /etc/apache2/media-auth/.htgroup    (roles: admin, ftp, standard)
#
# Install on media02:
#   sudo install -m 750 -o root -g root media-adduser.sh /usr/local/sbin/media-adduser
# Run:
#   sudo media-adduser
#
# Override the folder with MEDIA_AUTH_DIR=/some/dir if needed.

set -euo pipefail

AUTH_DIR="${MEDIA_AUTH_DIR:-/etc/apache2/media-auth}"
PASSWD_FILE="$AUTH_DIR/.htpasswd"
GROUP_FILE="$AUTH_DIR/.htgroup"
ALLOWED_GROUPS=(admin ftp standard)

die() { echo "Error: $*" >&2; exit 1; }

# --- preconditions -----------------------------------------------------------
[[ $EUID -eq 0 || -n "${MEDIA_AUTH_DIR:-}" ]] || die "run this as root (sudo media-adduser)."
command -v htpasswd >/dev/null || die "htpasswd not found. Install it with: apt install apache2-utils"
[[ -f "$PASSWD_FILE" ]] || die "$PASSWD_FILE doesn't exist."
[[ -f "$GROUP_FILE" ]]  || die "$GROUP_FILE doesn't exist."

user_exists() { grep -q "^$1:" "$PASSWD_FILE"; }

current_groups() {
    awk -v u="$1" '
        /^[[:space:]]*(#|$)/ { next }
        {
            i = index($0, ":"); if (!i) next
            g = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", g)
            n = split(substr($0, i + 1), m, " ")
            for (j = 1; j <= n; j++) if (m[j] == u) { printf "%s%s", sep, g; sep = "," }
        }
        END { print "" }' "$GROUP_FILE"
}

# --- username ----------------------------------------------------------------
read -rp "Username: " username
[[ -n "$username" ]] || die "username can't be empty."
[[ "$username" =~ ^[A-Za-z0-9._-]+$ ]] || die "use only letters, digits, dot, dash and underscore."

if user_exists "$username"; then
    echo "User '$username' already exists (groups: $(current_groups "$username"))."
    read -rp "Change their password and groups? [y/N] " answer
    [[ "$answer" =~ ^[Yy]$ ]] || { echo "Nothing changed."; exit 0; }
fi

# --- password ----------------------------------------------------------------
read -rsp "Password: " password; echo
[[ -n "$password" ]] || die "password can't be empty."
(( ${#password} >= 8 )) || die "use at least 8 characters."
read -rsp "Confirm password: " confirm; echo
[[ "$password" == "$confirm" ]] || die "passwords don't match."

# --- groups ------------------------------------------------------------------
read -rp "Groups (comma separated, from: ${ALLOWED_GROUPS[*]}) [standard]: " groups_input
groups_input="${groups_input:-standard}"

declare -A wanted=()
IFS=',' read -ra requested <<< "$groups_input"
for g in "${requested[@]}"; do
    g="${g//[[:space:]]/}"
    [[ -z "$g" ]] && continue
    [[ " ${ALLOWED_GROUPS[*]} " == *" $g "* ]] || die "unknown group '$g' (allowed: ${ALLOWED_GROUPS[*]})."
    wanted[$g]=1
done
wanted[standard]=1   # every account can browse; admin/ftp add to that
groups_csv="$(printf '%s\n' "${!wanted[@]}" | sort | paste -sd, -)"

# --- write -------------------------------------------------------------------
stamp="$(date +%Y%m%d-%H%M%S)"
cp -p "$PASSWD_FILE" "$PASSWD_FILE.bak-$stamp"
cp -p "$GROUP_FILE"  "$GROUP_FILE.bak-$stamp"

# Password: bcrypt, read from stdin so it never appears in the process list.
# htpasswd rewrites the file in place, so owner and permissions are kept.
printf '%s\n' "$password" | htpasswd -i -B "$PASSWD_FILE" "$username" >/dev/null
unset password confirm

# Groups: drop the user from every group, then add them to the chosen ones.
# Written back with "cat >" so the file keeps its owner and permissions.
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
awk -v u="$username" -v sel="$groups_csv" '
    BEGIN { n = split(sel, a, ","); for (i = 1; i <= n; i++) want[a[i]] = 1 }
    /^[[:space:]]*(#|$)/ { print; next }
    {
        i = index($0, ":"); if (!i) { print; next }
        g = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", g)
        n = split(substr($0, i + 1), m, " "); out = ""
        for (j = 1; j <= n; j++) if (m[j] != u) out = out (out == "" ? "" : " ") m[j]
        if (g in want) { out = out (out == "" ? "" : " ") u; seen[g] = 1 }
        print g ": " out
    }
    END { for (g in want) if (!(g in seen)) print g ": " u }
' "$GROUP_FILE" > "$tmp"
cat "$tmp" > "$GROUP_FILE"

# Keep the five most recent backups of each file.
for f in "$PASSWD_FILE" "$GROUP_FILE"; do
    find "$AUTH_DIR" -maxdepth 1 -name "$(basename "$f").bak-*" | sort -r | tail -n +6 | xargs -r rm -f
done

echo "Done: '$username' is in groups: $(current_groups "$username")"
echo "Backups: $PASSWD_FILE.bak-$stamp, $GROUP_FILE.bak-$stamp"
echo "Takes effect immediately; no Apache reload needed."
