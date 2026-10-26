# Tikool Memory Page

WooCommerce extension for pet memorial products whose permanent public URL is written to an NFC tag.

## Flow

1. Enable **Memory Page / NFC product** in a WooCommerce product's General data panel. Guests may check out without creating an account.
2. After a paid order reaches payment complete, processing, or completed, the plugin creates one private Memory Page record per purchased quantity. Pages from account orders are assigned immediately; pages from guest orders remain unclaimed.
3. Processing and completed customer order emails include a reusable, secure **Create Your Memory Page** claim link. The same action also appears on the order-received page.
4. A guest follows the link and either signs in to an existing account with the billing email or sets a password to create an account. All unclaimed Memory Pages in that order are attached to the account, the token is invalidated, and the customer is redirected to the first page's editor.
5. The customer edits the page under **My Account → Memory Pages** and uploads photos, video, audio, story text, and timeline entries.
6. **Save & publish** stores an immutable upload bundle and creates a persistent WordPress publishing-task record. The customer request returns immediately.
7. The background task sparse-checks out only `<random-id>/` from that page's independent bucket repository, updates media and `manifest.json`, pushes the bucket repository, then rebuilds and pushes `public/<random-id>.html` in the persistent HTML repository.
8. The temporary bucket checkout is deleted after every attempt. Successful task bundles are deleted; failed bundles remain available for diagnosis or retry.
9. Cloudflare serves the configured public base URL. The path never changes, so the same URL can be encoded into the product's NFC tag before or after the customer edits it.

## Guest claim security

- The order email contains the claim link, so closing the order-received page does not lose access.
- A link may be opened repeatedly until the order is claimed. It expires after 30 days; the expired-link screen can send a replacement to the verified billing email.
- Only a signed-in account whose email exactly matches the billing email may claim an order. If no account exists, the claim screen creates one with the billing email after the customer sets a password.
- The database stores an HMAC of the claim token, its expiry and token version. It never stores the URL token as plain text.
- Claiming assigns every unclaimed page in the order, records the claimant and timestamp, links the guest order to the customer, deletes the active token, and redirects to the first Memory Page editor.
- WooCommerce order actions include **Send Memory Page claim link** for customer-service recovery. Sending a replacement rotates the token and invalidates the previous link.

## Manual test page

1. Open **WooCommerce → Memory Pages → Add Post**.
2. Enter a title, select the customer in **Author**, and publish the record.
3. The plugin creates a 16-character mixed-case alphanumeric directory (about 95 bits of entropy) and the permanent NFC URL automatically. Legacy 32-character URLs remain supported.
4. Click **Edit content** in the Memory Pages list, or **Edit photos, media & timeline** in the record sidebar.
5. Complete the form and choose **Save & publish**. A task appears under **WooCommerce → Memory Page Tasks** with `queued`, `running`, `completed`, or `failed` status.

The order action **Create missing Memory Pages** only creates pages for order items whose product has **Memory Page / NFC product** enabled. It is idempotent and does not require changing the order status. Paid orders entering Processing or Completed provision their pages automatically.

## Configuration

The Git checkout lives on the Docker host at `/root/wordpress-sites/tikool-memory-pages` and is mounted into WordPress at `/var/lib/tikool-memory-pages`. The checkout is deliberately outside the web root. The host publisher receives requests over a group-protected Unix socket, so GitHub SSH credentials never enter the WordPress container.

Set the production `.env` values:

```dotenv
MEMORY_PAGES_REPO_PATH=/root/wordpress-sites/tikool-memory-pages
```

Then install the publisher once on the server:

```bash
./scripts/install-memory-page-publisher.sh
docker compose up -d --wait --wait-timeout 180 wordpress
```

The HTML checkout is mounted read-only in WordPress. WordPress can write only immutable bundles in the private task directory. The root-only host publisher owns Git credentials, creates disposable sparse bucket checkouts, and writes the persistent HTML checkout.

Open **WooCommerce → Memory Page Settings** and configure the branch, public HTML directory, current bucket Git URL, and public base URL. New Memory Pages permanently copy the currently configured bucket Git URL into `_tikool_memory_bucket_git_url`; changing the setting does not move existing pages.

Settings may instead be provided as constants in `wp-config.php`:

```php
define('TIKOOL_MEMORY_GITHUB_BRANCH', 'main');
define('TIKOOL_MEMORY_GITHUB_BASE_PATH', 'public');
define('TIKOOL_MEMORY_BUCKET_GIT_URL', 'git@github.com:damahaxyz/tikool-memory-pages-bucket1.git');
define('TIKOOL_MEMORY_PUBLIC_URL', 'https://memories.tikool.com');
define('TIKOOL_MEMORY_PUBLISH_SOCKET', '/var/run/tikool-memory-publisher/publisher.sock');
```

The persistent HTML project is `/var/lib/tikool-memory-pages`; its final NFC file is `public/<random-id>.html`, publicly served through Cloudflare's canonical extensionless URL `https://memories.tikool.com/<random-id>`. Each bucket is a separate Git project, for example `git@github.com:damahaxyz/tikool-memory-pages-bucket1.git`. Customer directories live under `public/<random-id>/`, containing `photos`, `videos`, `audio`, and `manifest.json`. Because `public` is the bucket deployment root, manifest media URLs remain `https://bucket1.tikool.com/<random-id>/photos/a.jpg`.

GitHub rejects files larger than 100 MB. Source videos are therefore capped at 90 MB and are reduced to no more than 25 MB when processing is required. For larger or high-volume video, migrate the media layer to object storage while keeping the stable NFC page URL.

## Scene contract

Every directory below `templates/scenes/` needs:

- `scene.json` with `label` and `description`.
- `index.html` containing the `__TIKOOL_MEMORY_MANIFEST_JSON__` placeholder.

The plugin replaces that placeholder with a JSON-safe `window.__TIKOOL_MEMORY_MANIFEST__` value every time the user saves. It also writes the same data to the page's bucket `manifest.json`. Manifest media entries contain complete bucket URLs; the final HTML renders its embedded copy and does not need to fetch the JSON file.

## Media limits and processing

- Photos: maximum 30 photos and 2 MB each.
- Videos: maximum 5 videos, maximum source upload 90 MB and maximum playback duration 1 minute. A video at or below 25 MB and 1 minute is used without transcoding. A larger or longer video is converted by the publishing task to MP4, trimmed to 1 minute, and limited to 720p, 3 Mbps and 30 fps. The processed file must be 25 MB or smaller.
- Audio: maximum 3 audio clips, 20 MB and 1 minute each. FFprobe validates the duration in the publishing task.

The host publisher requires `ffmpeg` and `ffprobe`. Upload-size checks run before the task is created; duration and final-video checks run again in the host task and are recorded as a failed task when invalid.
