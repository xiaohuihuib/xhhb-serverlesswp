#!/bin/bash
set -euo pipefail

./build-test.sh

# Clean up any leftovers from a previous run
pkill -f "node proxy.js" 2>/dev/null || true
docker stop serverlesswp-test mariadb 2>/dev/null || true
docker rm serverlesswp-test mariadb 2>/dev/null || true
docker network rm serverlesswp-test-network 2>/dev/null || true

docker network create serverlesswp-test-network

# Start MariaDB container
docker run -d --name mariadb \
    --network serverlesswp-test-network \
    -p 3306:3306 \
    -e "MYSQL_ROOT_PASSWORD=rootpassword" \
    -e "MYSQL_DATABASE=testdb" \
    -e "MYSQL_USER=testuser" \
    -e "MYSQL_PASSWORD=testpass" \
    mariadb:10.11

# Wait for MariaDB to be ready
echo "Waiting for MariaDB to be ready..."
until docker exec mariadb mariadb -u testuser -ptestpass testdb -e "SELECT 1" >/dev/null 2>&1; do sleep 1; done

# Run the application container with MariaDB environment variables
docker run \
    -e DATABASE=testdb \
    -e USERNAME=testuser \
    -e PASSWORD=testpass \
    -e HOST=mariadb \
    -e SKIP_MYSQL_SSL=1 \
    -e SERVERLESSWP_TESTING=1 \
    -p 9000:8080 \
    --network serverlesswp-test-network \
    -d --name serverlesswp-test serverlesswp-test

PROXY_LOG="$PWD/proxy.log"
node proxy.js > "$PROXY_LOG" 2>&1 &
PROXY_PID=$!

cleanup() {
    kill $PROXY_PID 2>/dev/null || true
    docker stop serverlesswp-test 2>/dev/null || true
    docker rm serverlesswp-test 2>/dev/null || true
    docker stop mariadb 2>/dev/null || true
    docker rm mariadb 2>/dev/null || true
    docker network rm serverlesswp-test-network 2>/dev/null || true
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

echo "Testing sensitive upload policy without the stream wrapper..."
policy_dir=/tmp/wp/wp-content/uploads/serverlesswp-policy-probe
docker exec serverlesswp-test mkdir -p "$policy_dir/php-index"
for extension in php sql sqlite sqlite3 db log env ini; do
    docker exec serverlesswp-test sh -c "printf '%s' 'sensitive-${extension}' > '${policy_dir}/secret.${extension}'"
    policy_response=$(curl -sk -w $'\n%{http_code}' "https://localhost:3000/wp-content/uploads/serverlesswp-policy-probe/secret.${extension}")
    policy_status=${policy_response##*$'\n'}
    policy_body=${policy_response%$'\n'*}
    [[ "$policy_status" == "404" ]] || { echo "Sensitive upload .${extension} FAILED: expected 404, got $policy_status"; exit 1; }
    [[ "$policy_body" == "Not Found" ]] || { echo "Sensitive upload .${extension} FAILED: response revealed file content"; exit 1; }
done
docker exec serverlesswp-test sh -c "printf '%s' '<?php echo \"INDEX-EXECUTED\";' > '${policy_dir}/php-index/index.php'"
index_status=$(curl -sk -o /dev/null -w '%{http_code}' "https://localhost:3000/wp-content/uploads/serverlesswp-policy-probe/php-index/")
[[ "$index_status" == "404" ]] || { echo "Sensitive upload PHP index FAILED: expected 404, got $index_status"; exit 1; }
docker exec serverlesswp-test sh -c "printf '%s' 'public-upload' > '${policy_dir}/public.txt'"
public_response=$(curl -sk -H 'x-serverlesswp-stream-wrapper-fallthrough: 1' -w $'\n%{http_code}' \
    "https://localhost:3000/wp-content/uploads/serverlesswp-policy-probe/public.txt")
public_status=${public_response##*$'\n'}
public_body=${public_response%$'\n'*}
# PHP may append a trailing newline when serving plain text files.
public_body=${public_body%$'\n'}
[[ "$public_status" == "200" ]] || { echo "Normal upload FAILED: expected 200, got $public_status"; exit 1; }
[[ "$public_body" == "public-upload" ]] || { echo "Normal upload FAILED after applying sensitive-file policy: got '$public_body'"; exit 1; }
echo "Sensitive upload policy test passed."

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
