#!/usr/bin/env bash
set -euo pipefail

urlencode() {
    php -r 'echo rawurlencode($argv[1]);' "$1"
}

json_get() {
    local key="$1"
    php -r '$d=json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? ($d[$argv[1]] ?? "") : "";' "$key"
}

GOOGLE_CLIENT_ID="${GOOGLE_CLIENT_ID:-}"
GOOGLE_CLIENT_SECRET="${GOOGLE_CLIENT_SECRET:-}"
GOOGLE_REDIRECT_URI="${GOOGLE_REDIRECT_URI:-http://localhost}"
GOOGLE_OAUTH_SCOPE="${GOOGLE_OAUTH_SCOPE:-https://www.googleapis.com/auth/drive.file}"

if [[ -z "$GOOGLE_CLIENT_ID" ]]; then
    read -r -p "Google OAuth Client ID: " GOOGLE_CLIENT_ID
fi

if [[ -z "$GOOGLE_CLIENT_SECRET" ]]; then
    read -r -s -p "Google OAuth Client Secret: " GOOGLE_CLIENT_SECRET
    echo
fi

STATE="$(openssl rand -hex 12)"
AUTH_URL="https://accounts.google.com/o/oauth2/v2/auth?client_id=$(urlencode "$GOOGLE_CLIENT_ID")&redirect_uri=$(urlencode "$GOOGLE_REDIRECT_URI")&response_type=code&scope=$(urlencode "$GOOGLE_OAUTH_SCOPE")&access_type=offline&prompt=consent&state=$(urlencode "$STATE")"

echo
echo "Open this URL in your browser, login, and approve access:"
echo
echo "$AUTH_URL"
echo
echo "After redirect, copy the 'code' parameter value from the URL."
read -r -p "Authorization code: " AUTH_CODE

TOKEN_RESPONSE="$(
    curl -sS -X POST "https://oauth2.googleapis.com/token" \
        --data-urlencode "client_id=${GOOGLE_CLIENT_ID}" \
        --data-urlencode "client_secret=${GOOGLE_CLIENT_SECRET}" \
        --data-urlencode "code=${AUTH_CODE}" \
        --data-urlencode "grant_type=authorization_code" \
        --data-urlencode "redirect_uri=${GOOGLE_REDIRECT_URI}"
)"

REFRESH_TOKEN="$(printf '%s' "$TOKEN_RESPONSE" | json_get refresh_token)"
ACCESS_TOKEN="$(printf '%s' "$TOKEN_RESPONSE" | json_get access_token)"
ERROR_CODE="$(printf '%s' "$TOKEN_RESPONSE" | json_get error)"
ERROR_DESC="$(printf '%s' "$TOKEN_RESPONSE" | json_get error_description)"

if [[ -z "$REFRESH_TOKEN" ]]; then
    echo
    echo "Failed to get refresh token."
    if [[ -n "$ERROR_CODE" || -n "$ERROR_DESC" ]]; then
        echo "error: ${ERROR_CODE:-unknown}"
        echo "description: ${ERROR_DESC:-unknown}"
    fi
    echo
    echo "Raw response:"
    echo "$TOKEN_RESPONSE"
    exit 1
fi

echo
echo "Success."
echo "Use these values in Auto Backups > Google Drive (OAuth mode):"
echo
echo "client_id: $GOOGLE_CLIENT_ID"
echo "client_secret: [the same one you entered]"
echo "refresh_token: $REFRESH_TOKEN"
echo
if [[ -n "$ACCESS_TOKEN" ]]; then
    echo "Access token was also returned (short-lived)."
fi
