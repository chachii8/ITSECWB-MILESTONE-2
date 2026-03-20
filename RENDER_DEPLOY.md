# Deploy to Render

## Prerequisites

- GitHub repo: https://github.com/chachii8/ITSECWB-MILESTONE-2
- Render account: https://render.com

---

## Step 1: Create MySQL Database on Render

1. Go to [Render MySQL Deploy](https://render.com/deploy-docker/mysql) or **New +** → **Private Service** → use the [render-examples/mysql](https://github.com/render-examples/mysql) template
2. Configure:
   - **Name:** `sole-source-db` (this becomes your `DB_HOST` — e.g. `sole-source-db`)
   - **Region:** Choose closest to you
   - **Add Disk:** Mount Path `/var/lib/mysql`, Size 10 GB
   - **Environment variables:**
     - `MYSQL_DATABASE` = `sole_source`
     - `MYSQL_USER` = `sole_source` (or your choice)
     - `MYSQL_PASSWORD` = (choose a strong password — **save this!**)
     - `MYSQL_ROOT_PASSWORD` = (choose a strong password)
3. Click **Create**
4. After deploy, the internal host is your service name (e.g. `sole-source-db`). Use this as `DB_HOST`.

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
2. Connect your GitHub repo: `chachii8/ITSECWB-MILESTONE-2`
3. Configure:
   - **Name:** `sole-source` (or any name)
   - **Region:** Same as MySQL
   - **Branch:** `main`
   - **Runtime:** **Docker**
   - **Build Command:** (leave default — uses Dockerfile)
   - **Start Command:** (leave default)

---

## Step 4: Environment Variables

In the Web Service → **Environment** tab, add:

| Key | Value |
|-----|-------|
| `DB_HOST` | Internal hostname from MySQL service (e.g. `dbs-xxx.oregon-postgres.render.com` or the host from Internal URL) |
| `DB_USER` | MySQL username |
| `DB_PASSWORD` | MySQL password |
| `DB_NAME` | `sole_source` |
| `DB_PORT` | `3306` |
| `APP_DEBUG` | `false` (use `true` only for troubleshooting) |

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
