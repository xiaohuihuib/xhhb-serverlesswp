# ServerlessWP

WordPress hosting is silly.

**Low maintenance** and **low cost/free** WordPress hosting on Vercel, Netlify, or AWS Lambda.

ServerlessWP puts WordPress in serverless functions. Deploy this repository to give it a try.

Stay up-to-date at the ServerlessWP repository: [github.com/mitchmac/serverlesswp](https://github.com/mitchmac/serverlesswp)

![WordPress 7.1](https://img.shields.io/badge/version-7.1-blue?logo=wordpress&labelColor=white&logoColor=black) ![PHP 8.3.33](https://img.shields.io/badge/version-8.3.33-blue?logo=php&labelColor=white)

## Use Cases

**This is currently an experimental project.** It's built for content sites rather than applications:

✅ **Great fit:** personal blogs, documentation, portfolios, marketing and small business sites, dev and staging sites — anything that isn't heavily updated by more than one person at a time.

✅ **Also great: headless/decoupled WordPress.** run WordPress purely as the editing backend and content API (REST or GraphQL) for a separate frontend.




## Quick Deploy

**Deploy to Vercel or Netlify with a MySQL-compatible database such as [TiDB Cloud Serverless](https://www.pingcap.com/tidb-cloud-serverless/).** See the [MySQL database option](#mysql-database-option) below for the environment variables to set.

The deploy form can also pre-fill `SERVERLESSWP_STREAM_PROVIDER` and `SERVERLESSWP_STREAM_VERCEL_ACCESS` to turn on [media uploads](#media-uploads-on-vercel-blob).

Other ways to deploy (click to deploy):

- **[Netlify](https://app.netlify.com/start/deploy?repository=https://github.com/mitchmac/serverlesswp)** with your own database (MySQL). Trade-offs vs. Vercel: 10 second max request duration instead of 60, manual branch config, and analytics/firewall are paid add-ons.
- **AWS Lambda** with the Serverless Framework: `npm install && serverless deploy`

## Project goals

🌴 WordPress hosting made easy. Lower maintenance with serverless functions instead of servers.

💲 Small WordPress sites shouldn't cost much to host. **Vercel, Netlify, & AWS have free tiers**.

🔓 WordPress plugins and themes are extensively supported. No arbitrary limitations here.

⚡ Blazing fast websites that take advantage of caching and content delivery networks.

🌎 Lower the carbon footprint of WordPress websites.

🤝 A helpful community. [Share your successes, ideas, or struggles](https://github.com/mitchmac/ServerlessWP/discussions) in the discussions.

## Deploy ServerlessWP

### 1. Deploy this repository to Vercel, Netlify, or AWS.
One of the links above will get you started. You'll just need a GitHub account.

### 2. Setup a database.
**MySQL is required.** A serverless MySQL-compatible database such as [TiDB Cloud Serverless](https://www.pingcap.com/tidb-cloud-serverless/) is the easiest way to get started. See [MySQL](#mysql-database-option) for the environment variables to set.

Whichever you choose, you set it up with environment variables. See [here for Vercel](https://vercel.com/docs/concepts/projects/environment-variables) and [here for Netlify](https://docs.netlify.com/environment-variables/overview/) for how to manage them. **Remember to redeploy** your project if you change environment variables after the initial deploy.

### 3. File and media uploads (optional, can be done later)
File and media uploads can be enabled using the included WP Offload Media Lite for Amazon S3 plugin, or with the [stream wrapper](#media-uploads-on-vercel-blob). S3 setup details for WP Offload Media can be found [here](https://deliciousbrains.com/wp-offload-media/doc/amazon-s3-quick-start-guide/). The wp-config.php file is set up to use the following environment variables for use by the plugin:
- S3_KEY_ID
- S3_ACCESS_KEY

## Media uploads on Vercel Blob or S3

Serverless containers throw away anything written to disk, so `wp-content/uploads` has to live somewhere else. A PHP stream wrapper routes writes under `wp-content` to remote object storage and serves them back through the function with cache headers so the CDN keeps a copy.

On an existing project, set:

| Media uploads | |
|---|---|
| SERVERLESSWP_STREAM_PROVIDER | `vercel-blob` to store uploads in Vercel Blob, or `s3` for a bucket |
| SERVERLESSWP_STREAM_VERCEL_ACCESS | `private` for a private store, `public` for a public one - it decides which host the files are read from |
| SERVERLESSWP_STREAM_VERCEL_STORE_ID | optional: store id to use instead of `BLOB_STORE_ID` |
| SERVERLESSWP_STREAM_S3_BUCKET | bucket name for S3/R2 |
| SERVERLESSWP_STREAM_S3_KEY | access key ID for S3/R2 |
| SERVERLESSWP_STREAM_S3_SECRET | secret access key for S3/R2 |
| SERVERLESSWP_STREAM_S3_REGION | region for S3/R2 |
| SERVERLESSWP_STREAM_S3_ENDPOINT | optional: custom endpoint for S3-compatible stores like R2 |
| SERVERLESSWP_STREAM_CACHE_CONTROL | optional: `Cache-Control` for served files - defaults to `public, max-age=3600, s-maxage=86400` |

A private store can only be read with a credential, so uploads are served by the function and cached at the edge. A public store can serve straight from the Blob CDN instead - point `SERVERLESSWP_STREAM_CDN_BASE_URL` at it.

Not everything under `wp-content` is routed or served: plugins, themes, mu-plugins and languages ship with the deployment and stay local, and `.php`, `.log`, `.sqlite` and `.htaccess` files are never routed. The full list of settings, what gets served and the known limitations are in the [stream wrapper README](packages/serverlesswp-stream-wrapper/README.md).

## MySQL database option

The right call when you need full plugin compatibility or more than a couple of people writing at once. [TiDB](https://www.pingcap.com/tidb-cloud-serverless/) provides a cloud MySQL database with a generous free tier.

After creating your database, set these environment variables with the credentials. ```wp-config.php``` is automatically configured to use them to connect.

|  |  |
|---|---|
| DATABASE | database name you created |
| USERNAME | database user to access the database |
| PASSWORD | database user's password |
| HOST |  address to access the database |
| TABLE_PREFIX | optional: to use a prefix on the database tables |

## Customizing WordPress
- WordPress and its files are in the ```/wp``` directory. You can add plugins or themes there in their respective directories in ```wp-content``` then commit the files to your repository so it will re-deploy.
- Plugins like [Cache-Control](https://wordpress.org/plugins/cache-control/) can enable CDN caching with the s-maxage directive and make your site super fast. Refer to [Vercel Edge Caching](https://vercel.com/docs/concepts/edge-network/caching) or [Netlfiy Cache Headers](https://docs.netlify.com/edge-functions/optional-configuration/#supported-headers)

## Customizing ServerlessWP
- `netlify.toml` or `vercel.json` are where we configure ```/api/index.js``` to handle all requests
- [mitchmac/serverlesswp-node](https://github.com/mitchmac/serverlesswp-node) is used to run PHP and handle the request
- You can modify the incoming request through the ```event``` object in api/index.js. You can also modify the WordPress ```response``` object there. ServerlessWP has a basic plugin system to do this. Checkout out ```/api/index.js``` for hints.

## Getting help
Need help getting ServerlessWP installed? [Start a discussion](https://github.com/mitchmac/ServerlessWP/discussions).

## Contributing
- Using ServerlessWP and [reporting any problems you experience](https://github.com/mitchmac/ServerlessWP/issues) is a great way to help.
- Spread the word!

## License
GNU General Public License v3.0
