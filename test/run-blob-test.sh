#!/bin/bash
set -euo pipefail

cd ..
docker build -t serverlesswp-blob-test -f test/Dockerfile-blob .
cd test

# Clean up any leftovers from a previous run
pkill -f "node proxy.js" 2>/dev/null || true
pkill -f "node vercel-blob-emulator/server.js" 2>/dev/null || true
docker stop serverlesswp-test serverlesswp-test-readonly 2>/dev/null || true
docker rm serverlesswp-test serverlesswp-test-readonly 2>/dev/null || true

VERCEL=${VERCEL:-1}
VERCEL_GIT_COMMIT_REF=${VERCEL_GIT_COMMIT_REF:-test_branch}

# Token format: vercel_blob_rw_<storeId>_<secret>. The mock derives the storeId
# and rebuilds the hardcoded blob download URL from it. Must match STORE_ID.
STORE_ID=test
BLOB_TOKEN="vercel_blob_rw_${STORE_ID}_testsecret"
FAKE_BLOB_PORT=7000

# How the container authenticates with the store. `oidc` is what the deploy
# button produces: Vercel connects the store, injects BLOB_STORE_ID, and mints
# a VERCEL_OIDC_TOKEN per deployment - no read-write token anywhere. `token`
# covers a store that has a static one.
BLOB_AUTH=${BLOB_AUTH:-token}
BLOB_OIDC_TOKEN=
if [[ "$BLOB_AUTH" == "oidc" ]]; then
    # The emulator doesn't check credentials, but the SDK won't send a request
    # without them, and it tries to refresh an OIDC token that's already
    # expired. So the fake has to parse as a JWT with an exp in the future.
    OIDC_PAYLOAD=$(printf '{"exp":%d}' $(( $(date +%s) + 86400 )) | base64 -w0 | tr '+/' '-_' | tr -d '=')
    # The container gets the store id and nothing else. The credential reaches
    # it the way Vercel delivers one - as a request header, injected by the
    # proxy - so the test fails if the function can't read it off the request.
    BLOB_OIDC_TOKEN="e30.${OIDC_PAYLOAD}.signature"
    BLOB_AUTH_ENV=(-e "BLOB_STORE_ID=$STORE_ID")
else
    BLOB_AUTH_ENV=(-e "SQLITE_BLOB_READ_WRITE_TOKEN=$BLOB_TOKEN")
fi
export BLOB_OIDC_TOKEN
echo "Blob auth mode: $BLOB_AUTH"

PORT=$FAKE_BLOB_PORT STORE_ID=$STORE_ID ACCESS=private \
    node vercel-blob-emulator/server.js > /dev/null 2>&1 &
FAKE_BLOB_PID=$!

# Wait for the emulator to be ready
until curl -s -o /dev/null -w "%{http_code}" http://localhost:$FAKE_BLOB_PORT/does-not-exist | grep -q 404; do sleep 1; done

# host-gateway lets the container reach the host-side blob emulator via
# http://host.docker.internal. Works on Docker Desktop and Docker Engine >= 20.10.
docker run \
    --add-host=host.docker.internal:host-gateway \
    "${BLOB_AUTH_ENV[@]}" \
    -e VERCEL_BLOB_API_URL=http://host.docker.internal:$FAKE_BLOB_PORT \
    -e VERCEL_BLOB_MOCK_URL=http://host.docker.internal:$FAKE_BLOB_PORT \
    -e VERCEL=$VERCEL -e VERCEL_GIT_COMMIT_REF=$VERCEL_GIT_COMMIT_REF \
    -e SERVERLESSWP_TESTING=1 \
    -e SERVERLESSWP_READ_ONLY_MODE=false \
    -p 9000:8080 \
    -d --name serverlesswp-test serverlesswp-blob-test

PROXY_LOG="$PWD/proxy.log"
node proxy.js > "$PROXY_LOG" 2>&1 &
PROXY_PID=$!

cleanup() {
    kill $PROXY_PID 2>/dev/null || true
    kill $FAKE_BLOB_PID 2>/dev/null || true
    docker stop serverlesswp-test 2>/dev/null || true
    docker rm serverlesswp-test 2>/dev/null || true
    docker stop serverlesswp-test-readonly 2>/dev/null || true
    docker rm serverlesswp-test-readonly 2>/dev/null || true
}
trap cleanup EXIT

until curl -sfko /dev/null https://localhost:3000/; do sleep 1; done

echo "Testing static file serving..."
static_check=$(curl -sk -o /dev/null -w "%{http_code} %{content_type}" https://localhost:3000/wp-includes/css/classic-themes.css)
http_code=${static_check%% *}
content_type=${static_check#* }
[[ "$http_code" == "200" ]] || { echo "Static file test FAILED: expected 200, got $http_code"; exit 1; }
[[ "$content_type" == *"text/css"* ]] || { echo "Static file content-type FAILED: expected text/css, got $content_type"; exit 1; }
echo "Static file test passed."

: "Run the installer before Playwright so the login form actually exists."
echo "Installing WordPress..."
if ! curl -skf --max-time 120 https://localhost:3000/installer.php >/dev/null 2>&1; then
    echo "WordPress installer failed or timed out."
    echo "Proxy log tail:"
    tail -n 50 "$PROXY_LOG" || true
    exit 1
fi
echo "WordPress installer completed."

echo "Waiting for WordPress login page to be ready..."
for i in $(seq 1 60); do
    login_response=$(curl -sk --max-time 10 -w $'\n%{http_code}' https://localhost:3000/wp-login.php || true)
    login_status=${login_response##*$'\n'}
    login_body=${login_response%$'\n'*}
    # Use a language-independent marker (the login form) rather than the
    # translated label text, which depends on WPLANG.
    if echo "$login_body" | grep -q 'id="loginform"'; then
        echo "WordPress login page is ready."
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "WordPress login page did not become ready in time."
        echo "HTTP status: $login_status"
        echo "Response body (first 1000 chars):"
        echo "$login_body" | head -c 1000
        echo
        echo "Response headers:"
        curl -skI --max-time 10 https://localhost:3000/wp-login.php || true
        echo
        if echo "$login_body" | grep -qi 'ServerlessWP is installed'; then
            echo "NOTE: the install wizard was returned instead of wp-login.php; the database backend may not be configured."
        fi
        echo "Proxy log tail:"
        tail -n 50 "$PROXY_LOG" || true
        exit 1
    fi
    echo "  attempt $i: login page not ready yet (status: $login_status)"
    sleep 5
done

npm ci
npx playwright install chromium
ldconfig -p | grep -q libnspr4 || sudo env PATH="$PATH" node_modules/.bin/playwright install-deps chromium
SCREENSHOTS=${SCREENSHOTS:-} npx playwright test e2e.spec.js "$@"

# Read-only mode tests — reuse the populated emulator state from above.
echo "Starting read-only mode tests..."
docker stop serverlesswp-test
docker rm serverlesswp-test

docker run \
    --add-host=host.docker.internal:host-gateway \
    "${BLOB_AUTH_ENV[@]}" \
    -e VERCEL_BLOB_API_URL=http://host.docker.internal:$FAKE_BLOB_PORT \
    -e VERCEL_BLOB_MOCK_URL=http://host.docker.internal:$FAKE_BLOB_PORT \
    -e VERCEL=$VERCEL -e VERCEL_GIT_COMMIT_REF=$VERCEL_GIT_COMMIT_REF \
    -e SERVERLESSWP_TESTING=1 \
    -e SERVERLESSWP_READ_ONLY_MODE=1 \
    -e SERVERLESSWP_READ_ONLY_CACHE_MAX_AGE=3600 \
    -p 9000:8080 \
    -d --name serverlesswp-test-readonly serverlesswp-blob-test

until curl -sfko /dev/null https://localhost:3000/; do sleep 1; done

SKIP_AUTH=1 SCREENSHOTS=${SCREENSHOTS:-} npx playwright test e2e-read-only.spec.js "$@"
