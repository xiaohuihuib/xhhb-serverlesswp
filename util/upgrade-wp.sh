#!/bin/bash

cd ..
mkdir temp
cd temp

wget https://cn.wordpress.org/latest-zh_CN.zip
unzip latest-zh_CN.zip

cp ../wp/wp-config.php wordpress/

cp ../wp/wp-content/languages/plugins/asgaros-forum-zh_CN.po wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/asgaros-forum-zh_CN.mo wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/asgaros-forum-zh_CN.l10n.php wordpress/wp-content/languages/plugins/

cp ../wp/wp-content/languages/plugins/wordpress-importer-zh_CN.po wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordpress-importer-zh_CN.mo wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordpress-importer-zh_CN.l10n.php wordpress/wp-content/languages/plugins/

cp ../wp/wp-content/languages/plugins/wordfence-zh_CN.po wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN.mo wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN.l10n.php wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN-0d455069dd479112c75ad60d5dfe35da.json wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN-3a5c971b121f299b74a6c03ec10dfde1.json wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN-9f22b9f504df7b65b96763ff03cb4cde.json wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN-60cecc84730292c14de6fa6a684fa5a2.json wordpress/wp-content/languages/plugins/
cp ../wp/wp-content/languages/plugins/wordfence-zh_CN-925338e6c068b12411f2a3e130f029b4.json wordpress/wp-content/languages/plugins/

mkdir wordpress/wp-content/mu-plugins
cp ../wp/wp-content/mu-plugins/serverlesswp.php wordpress/wp-content/mu-plugins/
cp ../wp/wp-content/mu-plugins/serverlesswp-stream-wrapper.php wordpress/wp-content/mu-plugins/
cp ../wp/wp-content/mu-plugins/serverlesswp-stream-wrapper wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/amazon-s3-and-cloudfront.zip
unzip amazon-s3-and-cloudfront.zip
mv amazon-s3-and-cloudfront wordpress/wp-content/plugins/

git clone --depth 1 https://github.com/WordPress/sqlite-database-integration.git
cp -rL sqlite-database-integration/packages/plugin-sqlite-database-integration wordpress/wp-content/plugins/sqlite-database-integration
rm -rf sqlite-database-integration

wget https://downloads.wordpress.org/plugin/tidb-compatibility.zip
unzip tidb-compatibility
mv tidb-compatibility wordpress/wp-content/plugins/

wget https://github.com/xiaohuihuib/xhhb-serverlesswp/releases/download/V1.3.5/argon.zip
unzip argon.zip
mv argon wordpress/wp-content/themes/

rm -rf wordpress/wp-content/plugins/hello.php
rm -rf wordpress/wp-content/themes/twentytwentytwo wordpress/wp-content/themes/twentytwentyone
rm -rf wordpress/wp-content/themes/twentytwentythree wordpress/wp-content/themes/twentytwentyfour
rm -rf wordpress/wp-content/themes/twentytwenty wordpress/wp-content/themes/twentytwentyfive

wget https://downloads.wordpress.org/plugin/simple-cloudflare-turnstile.zip
unzip simple-cloudflare-turnstile.zip
mv simple-cloudflare-turnstile wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/yctvn-media-offload-cloudflare-r2.1.0.2.zip
unzip yctvn-media-offload-cloudflare-r2.1.0.2.zip
mv yctvn-media-offload-cloudflare-r2 wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/asgaros-forum.3.4.0.zip
unzip asgaros-forum.3.4.0.zip
mv asgaros-forum wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/integrate-umami.0.8.3.zip
unzip integrate-umami.0.8.3.zip
mv integrate-umami wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/wordpress-importer.0.9.5.zip
unzip wordpress-importer.0.9.5.zip
mv wordpress-importer wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/default-admin-color-scheme.1.0.3.zip
unzip default-admin-color-scheme.1.0.3.zip
mv default-admin-color-scheme wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/ai.1.3.0.zip
unzip ai.1.3.0.zip
mv ai wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/ai-provider-for-cloudflare.1.0.0.zip
unzip ai-provider-for-cloudflare.1.0.0.zip
mv ai-provider-for-cloudflare wordpress/wp-content/plugins/

wget https://downloads.wordpress.org/plugin/wordfence.9.0.0.zip
unzip wordfence.9.0.0.zip
mv wordfence wordpress/wp-content/plugins/

rm -rf ../wp
mv wordpress ../wp
cd ..
rm -rf temp
