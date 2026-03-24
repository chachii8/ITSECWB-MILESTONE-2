# Deploy Sole Source to Render

## Quick checklist

1. Push latest code to GitHub
2. Create MySQL private service on Render
3. Import SQL schema (via Adminer or Shell)
4. Create Web Service, connect repo, set env vars
5. Deploy and test

---

## Prerequisites

- Code pushed to GitHub: `chachii8/ITSECWB-MILESTONE-2` (or your fork)
- Render account: https://dashboard.render.com

---

## Step 1: Create MySQL Database

1. Log in at [dashboard.render.com](https://dashboard.render.com)
2. Click **New +** → **Private Service**
3. Search for **MySQL** or use [render.com/deploy-docker/mysql](https://render.com/deploy-docker/mysql)
4. Connect your GitHub (or use the `render-examples/mysql` template)
5. Configure:
   - **Name:** `sole-source-db` (this is your `DB_HOST`)
   - **Region:** e.g. Oregon (US West) or Singapore
   - **Environment variables:**

     | Key | Value |
     |-----|-------|
     | `MYSQL_DATABASE` | `sole_source` |
     | `MYSQL_USER` | `sole_source` |
     | `MYSQL_PASSWORD` | *(choose strong password — save it!)* |
     | `MYSQL_ROOT_PASSWORD` | *(choose strong password — save it!)* |

   - **Advanced** → **Add Disk:**
     - Mount Path: `/var/lib/mysql`
     - Size: 10 GB

6. Click **Create Private Service**
7. Wait for deploy. The **Internal URL** will be like `sole-source-db:3306` — use `sole-source-db` as `DB_HOST`

---

## Step 2: Import Database Schema

1. Deploy [Adminer](https://render.com/docs/deploy-adminer) as a Web Service in the same Render account to access MySQL via browser
2. Or use **Shell** in the MySQL service dashboard: `mysql -h localhost -D sole_source -u sole_source -p` then paste SQL
3. Run these SQL files in order:
   - `database/sole_source.sql`
   - `database/add_mfa_tables.sql` (if not in sole_source.sql)
   - `database/add_audit_log_category.sql` (if needed)

---

## Step 3: Create Web Service

1. Click **New +** → **Web Service**
2. Connect GitHub and select repo: `chachii8/ITSECWB-MILESTONE-2` (or your fork)
3. Configure:
   - **Name:** `sole-source` (or any name)
   - **Region:** Same as MySQL
   - **Branch:** `main`
   - **Root Directory:** (leave blank)
   - **Runtime:** **Docker** (required — PHP is not natively supported)
   - **Build Command:** (leave default)
   - **Start Command:** (leave default — uses `docker-entrypoint.sh`)

---

## Step 4: Environment Variables

In the Web Service → **Environment** tab, add:

| Key | Value |
|-----|-------|
| `DB_HOST` | **Your MySQL service name** (e.g. `sole-source-db`) — use the exact name from Step 1 |
| `DB_USER` | Same as `MYSQL_USER` (e.g. `sole_source`) |
| `DB_PASSWORD` | Same as `MYSQL_PASSWORD` |
| `DB_NAME` | `sole_source` |
| `DB_PORT` | `3306` |
| `APP_DEBUG` | `false` (set `true` only for troubleshooting) |

**For reCAPTCHA on production:** Add your domain to [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin), then:

| Key | Value |
|-----|-------|
| `RECAPTCHA_SITE_KEY` | Your production site key |
| `RECAPTCHA_SECRET_KEY` | Your production secret key |

---

## Step 5: Deploy

1. Click **Create Web Service**
2. Render will build from the Dockerfile and deploy
3. Your app will be at: `https://your-service-name.onrender.com`

---

## Step 6: Custom Domain (Optional)

1. In your Web Service → **Settings** → **Custom Domains**
2. Add your domain (e.g. `app.yourdomain.com`)
3. Add the CNAME record shown by Render to your DNS
4. Render will auto-issue an SSL certificate (HTTPS)

---

## Troubleshooting

- **Database connection failed:** Check `DB_HOST` uses the **internal** hostname (Render private services use internal networking)
- **500 error:** Set `APP_DEBUG=true` temporarily to see the error, then set back to `false`
- **Images not saving:** The `images/` folder is writable in the container; uploads work but are lost on redeploy. For persistent storage, use Render Disks or external storage (S3)

---

## Local Development

Your local XAMPP setup is unchanged. Use `http://localhost/itdbadm/` for development. The app uses `localhost` when env vars are not set.
